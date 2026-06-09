#!/usr/bin/env php
<?php
/**
 * Импорт существующего AWG-сервера в БД панели из бэкапа wg0.conf + clientsTable.json.
 *
 * ВАЖНО: не трогает VPN на удалённом сервере (без deploy, без addClientToServer).
 * Клиенты на VPN продолжают работать. private_key/config в панели не восстанавливаются
 * (их нет в таком бэкапе) — скачивание старых .conf из UI будет недоступно.
 *
 * Usage:
 *   php bin/import_awg_backup.php --server-id=1 --backup-dir=/var/www/html/awg-import
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';

Config::load(__DIR__ . '/../.env');

function usage(): void
{
    fwrite(STDERR, "Usage: php bin/import_awg_backup.php --server-id=ID --backup-dir=PATH\n");
    exit(1);
}

$serverId = null;
$backupDir = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--server-id=')) {
        $serverId = (int)substr($arg, strlen('--server-id='));
    } elseif (str_starts_with($arg, '--backup-dir=')) {
        $backupDir = rtrim(substr($arg, strlen('--backup-dir=')), '/');
    } elseif ($arg === '--help' || $arg === '-h') {
        usage();
    }
}

if (!$serverId || !$backupDir) {
    usage();
}

$wg0Path = $backupDir . '/wg0.conf';
$tablePath = $backupDir . '/clientsTable.json';

if (!is_file($wg0Path)) {
    fwrite(STDERR, "File not found: {$wg0Path}\n");
    exit(1);
}
if (!is_file($tablePath)) {
    fwrite(STDERR, "File not found: {$tablePath}\n");
    exit(1);
}

function parseWg0Conf(string $content): array
{
    $interface = [];
    $peers = [];
    $section = null;
    $currentPeer = null;

    $flushPeer = static function () use (&$peers, &$currentPeer): void {
        if ($currentPeer !== null && !empty($currentPeer['PublicKey'])) {
            $peers[] = $currentPeer;
        }
        $currentPeer = null;
    };

    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '[Interface]') {
            $flushPeer();
            $section = 'interface';
            continue;
        }
        if ($trimmed === '[Peer]') {
            $flushPeer();
            $section = 'peer';
            $currentPeer = [];
            continue;
        }
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($section === 'interface') {
            $interface[$key] = $value;
        } elseif ($section === 'peer' && $currentPeer !== null) {
            $currentPeer[$key] = $value;
        }
    }

    $flushPeer();

    return ['interface' => $interface, 'peers' => $peers];
}

function serverPublicKeyFromPrivate(string $privateKey): ?string
{
    $privateKey = trim($privateKey);
    $cmd = sprintf('echo %s | wg pubkey 2>/dev/null', escapeshellarg($privateKey));
    $out = shell_exec($cmd);
    if (!$out) {
        return null;
    }
    return trim($out);
}

function executeServerCommand(array $serverData, string $command, bool $sudo = false): string
{
    if ($sudo && strtolower($serverData['username']) !== 'root') {
        $command = "echo '{$serverData['password']}' | sudo -S " . $command;
    }

    $sshCommand = sprintf(
        "sshpass -p '%s' ssh -p %d -q -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no %s@%s %s 2>&1",
        $serverData['password'],
        $serverData['port'],
        $serverData['username'],
        $serverData['host'],
        escapeshellarg($command)
    );

    return shell_exec($sshCommand) ?? '';
}

$wg0 = parseWg0Conf(file_get_contents($wg0Path));
$interface = $wg0['interface'];
$peers = $wg0['peers'];

if (empty($interface['ListenPort'])) {
    fwrite(STDERR, "Invalid wg0.conf: ListenPort not found\n");
    exit(1);
}

$namesByPublicKey = [];
$table = json_decode(file_get_contents($tablePath), true);
if (!is_array($table)) {
    fwrite(STDERR, "Invalid clientsTable.json\n");
    exit(1);
}
foreach ($table as $row) {
    $pk = $row['clientId'] ?? '';
    if ($pk !== '') {
        $namesByPublicKey[$pk] = $row['userData']['clientName'] ?? $pk;
    }
}

$pdo = DB::conn();
$stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ?');
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) {
    fwrite(STDERR, "Server #{$serverId} not found in panel DB\n");
    exit(1);
}

$awgParams = [];
foreach (['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'H1', 'H2', 'H3', 'H4'] as $key) {
    if (isset($interface[$key])) {
        $awgParams[$key] = is_numeric($interface[$key]) ? (int)$interface[$key] : $interface[$key];
    }
}

$serverPublicKey = null;
if (!empty($interface['PrivateKey'])) {
    $serverPublicKey = serverPublicKeyFromPrivate($interface['PrivateKey']);
}
if (!$serverPublicKey) {
    $container = $server['container_name'] ?: 'amnezia-awg';
    $serverPublicKey = trim(executeServerCommand(
        $server,
        "docker exec -i {$container} wg show wg0 public-key",
        true
    ));
}
if (!$serverPublicKey) {
    fwrite(STDERR, "Failed to determine server public key (install wg in container or check SSH)\n");
    exit(1);
}

$presharedKey = $peers[0]['PresharedKey'] ?? null;
$vpnSubnet = $interface['Address'] ?? ($server['vpn_subnet'] ?: '10.8.1.0/24');

$update = $pdo->prepare('
    UPDATE vpn_servers
    SET vpn_port = ?, vpn_subnet = ?, server_public_key = ?, preshared_key = ?,
        awg_params = ?, status = ?, deployed_at = NOW(), error_message = NULL
    WHERE id = ?
');
$update->execute([
    (int)$interface['ListenPort'],
    $vpnSubnet,
    $serverPublicKey,
    $presharedKey,
    json_encode($awgParams, JSON_UNESCAPED_UNICODE),
    'active',
    $serverId,
]);

echo "Server #{$serverId} marked active (vpn_port={$interface['ListenPort']}, peers in backup: " . count($peers) . ")\n";

$userId = (int)$server['user_id'];
$insert = $pdo->prepare('
    INSERT INTO vpn_clients
    (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, qr_code, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');

$imported = 0;
$skipped = 0;

foreach ($peers as $peer) {
    $publicKey = $peer['PublicKey'] ?? '';
    $allowed = $peer['AllowedIPs'] ?? '';
    if ($publicKey === '' || $allowed === '') {
        $skipped++;
        continue;
    }

    $clientIp = explode('/', $allowed)[0];
    $name = $namesByPublicKey[$publicKey] ?? $clientIp;

    $check = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND (public_key = ? OR client_ip = ?)');
    $check->execute([$serverId, $publicKey, $clientIp]);
    if ($check->fetch()) {
        echo "  skip: {$name} (already in DB)\n";
        $skipped++;
        continue;
    }

    $insert->execute([
        $serverId,
        $userId,
        $name,
        $clientIp,
        $publicKey,
        'IMPORTED_NO_PRIVATE_KEY',
        $peer['PresharedKey'] ?? $presharedKey,
        '',
        '',
        'active',
    ]);

    echo "  imported: {$name} ({$clientIp})\n";
    $imported++;
}

echo "Done. Imported: {$imported}, skipped: {$skipped}\n";
echo "VPN on remote host was NOT modified.\n";
echo "Note: download/QR for old clients unavailable without full panel backup (.json with private_key).\n";
