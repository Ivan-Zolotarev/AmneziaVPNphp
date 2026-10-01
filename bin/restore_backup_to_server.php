#!/usr/bin/env php
<?php
/**
 * Restore a panel JSON backup onto another (or the same) already-deployed AWG server.
 * Keeps client keys and IPs; rewrites Endpoint / server key / PSK / AWG junk for the target.
 *
 * Usage (inside web container):
 *   php bin/restore_backup_to_server.php <target_server_id> <path-to.json>
 *
 * Example — Germany backup onto France (id from /servers/5):
 *   docker compose exec web php bin/restore_backup_to_server.php 5 \
 *     /var/www/html/storage/server-backups/backup_2_2026-09-30_203513.json
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../inc/Config.php';
require __DIR__ . '/../inc/DB.php';
require __DIR__ . '/../inc/VpnClient.php';
require __DIR__ . '/../inc/VpnServer.php';

Config::load(__DIR__ . '/../.env');

$serverId = (int)($argv[1] ?? 0);
$path = trim((string)($argv[2] ?? ''));

if ($serverId <= 0 || $path === '') {
    fwrite(STDERR, "Usage: php bin/restore_backup_to_server.php <target_server_id> <backup.json>\n");
    exit(1);
}

if (!str_starts_with($path, '/')) {
    $path = dirname(__DIR__) . '/' . $path;
}

try {
    $server = new VpnServer($serverId);
    $data = $server->getData();
    if (VpnServer::isVlessServer($data)) {
        throw new Exception('This backup is for AmneziaWG. Target server is VLESS — pick an AWG server.');
    }
    echo "Target #{$serverId} {$data['name']} {$data['host']}:{$data['vpn_port']}\n";
    echo "File: {$path}\n";
    $result = $server->restoreBackupFromFile($path, true);
    echo $result['message'] . "\n";
    if (!empty($result['errors'])) {
        foreach (array_slice($result['errors'], 0, 30) as $err) {
            echo "  - {$err}\n";
        }
        if (count($result['errors']) > 30) {
            echo "  ... " . (count($result['errors']) - 30) . " more\n";
        }
    }
    echo "Clients must download a NEW config/QR from this server (old Germany QR will not work).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
