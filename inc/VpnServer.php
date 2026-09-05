<?php
/**
 * VPN Server Management Class
 * Handles deployment and management of Amnezia VPN servers
 * Based on amnezia_deploy_v2.php
 */
require_once __DIR__ . '/XuiClient.php';

class VpnServer {
    private $serverId;
    private $data;
    
    public function __construct(?int $serverId = null) {
        $this->serverId = $serverId;
        if ($serverId) {
            $this->load();
        }
    }
    
    /**
     * Load server data from database
     */
    private function load(): void {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ?');
        $stmt->execute([$this->serverId]);
        $this->data = $stmt->fetch();
        if (!$this->data) {
            throw new Exception('Server not found');
        }
    }
    
    /**
     * Create new VPN server in database
     */
    public static function create(array $data): int {
        $pdo = DB::conn();
        
        // Validate required fields
        $required = ['user_id', 'name', 'host', 'port', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }
        
        $protocol = $data['protocol'] ?? 'awg';
        if (!in_array($protocol, ['awg', 'vless_reality'], true)) {
            throw new Exception('Invalid protocol');
        }

        $vlessParams = $data['vless_params'] ?? null;
        if (is_array($vlessParams)) {
            $vlessParams = json_encode($vlessParams, JSON_UNESCAPED_SLASHES);
        }

        $stmt = $pdo->prepare('
            INSERT INTO vpn_servers 
            (user_id, name, host, port, username, password, container_name, protocol, panel_web_path, panel_use_https, panel_insecure_tls, vpn_subnet, vless_params, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['container_name'] ?? 'amnezia-awg',
            $protocol,
            $data['panel_web_path'] ?? '',
            !empty($data['panel_use_https']) ? 1 : 0,
            !empty($data['panel_insecure_tls']) ? 1 : 0,
            $data['vpn_subnet'] ?? '10.8.1.0/24',
            $vlessParams,
            'deploying'
        ]);
        
        return (int)$pdo->lastInsertId();
    }
    
    /**
     * Deploy VPN server using amnezia_deploy_v2.php logic
     */
    public function deploy(): array {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }
        
        $pdo = DB::conn();
        $errors = [];
        
        try {
            if (self::isVlessServer($this->data)) {
                return $this->deployVlessReality();
            }

            // Update status to deploying
            $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
                ->execute(['deploying', $this->serverId]);
            
            // Test SSH connection
            if (!$this->testConnection()) {
                throw new Exception('SSH connection failed');
            }
            
            // Install Docker if needed
            $this->installDocker();
            
            // Create directories
            $this->executeCommand('mkdir -p /opt/amnezia/amnezia-awg', true);
            
            // Find free UDP port
            $vpnPort = $this->findFreeUdpPort();
            
            // Create Dockerfile
            $this->createDockerfile();
            
            // Create start script
            $this->createStartScript();
            
            // Build Docker image
            $this->buildDockerImage();
            
            // Run container
            $this->runContainer($vpnPort);
            
            // Initialize server config
            $keys = $this->initializeServerConfig($vpnPort);
            
            // Update database with deployment info
            $stmt = $pdo->prepare('
                UPDATE vpn_servers 
                SET vpn_port = ?, 
                    server_public_key = ?, 
                    preshared_key = ?, 
                    awg_params = ?,
                    status = ?,
                    deployed_at = NOW(),
                    error_message = NULL
                WHERE id = ?
            ');
            
            $stmt->execute([
                $vpnPort,
                $keys['public_key'],
                $keys['preshared_key'],
                json_encode($keys['awg_params']),
                'active',
                $this->serverId
            ]);
            
            // Reload data
            $this->load();
            
            $this->installNatRecoveryOnHost($this->data['container_name']);
            
            return [
                'success' => true,
                'vpn_port' => $vpnPort,
                'public_key' => $keys['public_key']
            ];
            
        } catch (Exception $e) {
            // Update status to error
            $pdo->prepare('UPDATE vpn_servers SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['error', $e->getMessage(), $this->serverId]);
            
            throw $e;
        }
    }
    
    /**
     * Test SSH connection to server
     */
    private function testConnection(): bool {
        $testCommand = sprintf(
            "sshpass -p '%s' ssh -p %d -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 %s@%s 'echo test' 2>/dev/null",
            $this->data['password'],
            $this->data['port'],
            $this->data['username'],
            $this->data['host']
        );
        
        $result = shell_exec($testCommand);
        return trim($result) === 'test';
    }
    
    /**
     * Execute command on remote server
     */
    private function executeCommand(string $command, bool $sudo = false): string {
        if ($sudo && strtolower($this->data['username']) !== 'root') {
            $command = "echo '{$this->data['password']}' | sudo -S " . $command;
        }
        
        $escapedCommand = escapeshellarg($command);
        $sshCommand = sprintf(
            "sshpass -p '%s' ssh -p %d -q -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no %s@%s %s 2>&1",
            $this->data['password'],
            $this->data['port'],
            $this->data['username'],
            $this->data['host'],
            $escapedCommand
        );
        
        return shell_exec($sshCommand) ?? '';
    }
    
    /**
     * Install Docker on remote server
     */
    private function installDocker(): void {
        $dockerVersion = $this->executeCommand('docker --version');
        if (stripos($dockerVersion, 'version') !== false) {
            return; // Docker already installed
        }
        
        $this->executeCommand('curl -fsSL https://get.docker.com | sh', true);
        $this->executeCommand('systemctl enable --now docker', true);
    }
    
    /**
     * Prefer UDP 443/80 (pass Russian DPI / geo filters); TCP 443 on the panel does not occupy UDP 443.
     */
    private function findFreeUdpPort(): int {
        $preferredRaw = (string)Config::get('DEFAULT_VPN_UDP_PORTS', '443,80,51820');
        $preferred = [];
        foreach (explode(',', $preferredRaw) as $part) {
            $n = (int)trim($part);
            if ($n > 0 && $n <= 65535) {
                $preferred[] = $n;
            }
        }

        foreach ($preferred as $candidate) {
            if ($this->isUdpPortFree($candidate)) {
                return $candidate;
            }
        }

        $min = (int)Config::get('DEFAULT_VPN_PORT_MIN', 30000);
        $max = (int)Config::get('DEFAULT_VPN_PORT_MAX', 65000);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $candidate = random_int($min, $max);
            if ($this->isUdpPortFree($candidate)) {
                return $candidate;
            }
        }

        throw new Exception('Could not find free UDP port');
    }

    private function isUdpPortFree(int $port): bool {
        $cmd = "ss -lun | awk '{print \$4}' | grep -E ':(" . $port . ")($| )' || true";
        $out = $this->executeCommand($cmd, false);
        return trim($out) === '';
    }
    
    /**
     * Create Dockerfile on remote server
     */
    private function createDockerfile(): void {
        $dockerfile = <<<'DOCKERFILE'
FROM amneziavpn/amnezia-wg:latest

LABEL maintainer="AmneziaVPN"

RUN apk add --no-cache bash curl dumb-init
RUN apk --update upgrade --no-cache

RUN mkdir -p /opt/amnezia
COPY start.sh /opt/amnezia/start.sh
RUN chmod a+x /opt/amnezia/start.sh

ENTRYPOINT [ "dumb-init", "/opt/amnezia/start.sh" ]
CMD [ "" ]
DOCKERFILE;
        
        $escaped = addslashes(trim($dockerfile));
        $this->executeCommand("echo \"{$escaped}\" > /opt/amnezia/amnezia-awg/Dockerfile", true);
    }
    
    /**
     * PostUp/PostDown hooks for wg0.conf — NAT survives container/VPS reboot via wg-quick.
     */
    private function getNatIptablesHooks(string $vpnSubnet): array {
        $postUp = 'OUT_IF=$(ip route show default | awk \'{print $5}\' | head -n1); [ -z "$OUT_IF" ] && OUT_IF=eth0; '
            . 'iptables -t nat -C POSTROUTING -s ' . $vpnSubnet . ' -o $OUT_IF -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -s ' . $vpnSubnet . ' -o $OUT_IF -j MASQUERADE; '
            . 'iptables -C FORWARD -i %i -o $OUT_IF -s ' . $vpnSubnet . ' -j ACCEPT 2>/dev/null || iptables -A FORWARD -i %i -o $OUT_IF -s ' . $vpnSubnet . ' -j ACCEPT; '
            . 'iptables -C FORWARD -i $OUT_IF -o %i -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || iptables -A FORWARD -i $OUT_IF -o %i -m state --state RELATED,ESTABLISHED -j ACCEPT';

        $postDown = 'OUT_IF=$(ip route show default | awk \'{print $5}\' | head -n1); [ -z "$OUT_IF" ] && OUT_IF=eth0; '
            . 'iptables -t nat -D POSTROUTING -s ' . $vpnSubnet . ' -o $OUT_IF -j MASQUERADE 2>/dev/null || true; '
            . 'iptables -D FORWARD -i %i -o $OUT_IF -s ' . $vpnSubnet . ' -j ACCEPT 2>/dev/null || true; '
            . 'iptables -D FORWARD -i $OUT_IF -o %i -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || true';

        return ['postUp' => $postUp, 'postDown' => $postDown];
    }

    /**
     * On VPN host: sysctl, ensure_awg_nat.sh, and cron (@reboot + periodic heal).
     */
    private function installNatRecoveryOnHost(string $containerName): void {
        $localScript = dirname(__DIR__) . '/scripts/ensure_awg_nat.sh';
        if (!is_readable($localScript)) {
            return;
        }

        $remoteScript = '/usr/local/bin/amnezia-ensure-awg-nat.sh';
        $b64 = base64_encode((string)file_get_contents($localScript));
        $this->executeCommand(
            "echo " . escapeshellarg($b64) . " | base64 -d > {$remoteScript} && chmod 755 {$remoteScript}",
            true
        );

        $this->executeCommand(
            'grep -q "^net.ipv4.ip_forward=1" /etc/sysctl.conf 2>/dev/null || '
            . 'printf "%s\n" "net.ipv4.ip_forward=1" "net.ipv4.conf.all.rp_filter=0" "net.ipv4.conf.default.rp_filter=0" >> /etc/sysctl.conf; '
            . 'sysctl -p >/dev/null 2>&1 || true',
            true
        );

        $marker = 'amnezia-panel-awg-nat';
        $logFile = '/var/log/amnezia-awg-nat.log';
        $rebootLine = "@reboot sleep 45 && AWG_CONTAINER_NAME={$containerName} {$remoteScript} >> {$logFile} 2>&1 # {$marker}";
        $healLine = "*/15 * * * * AWG_CONTAINER_NAME={$containerName} {$remoteScript} >> {$logFile} 2>&1 # {$marker}";

        $cronCmd = '(crontab -l 2>/dev/null | grep -v ' . escapeshellarg($marker) . ' || true; '
            . 'echo ' . escapeshellarg($rebootLine) . '; '
            . 'echo ' . escapeshellarg($healLine) . ') | crontab -';

        $this->executeCommand($cronCmd, true);
        $this->executeCommand("touch {$logFile} 2>/dev/null || true", true);
        $this->executeCommand("AWG_CONTAINER_NAME={$containerName} {$remoteScript}", true);
    }

    /**
     * Create start script on remote server
     */
    private function createStartScript(): void {
        $script = <<<'BASH'
#!/usr/bin/env bash

echo "Container startup"

apply_nat_rules() {
    echo 1 > /proc/sys/net/ipv4/ip_forward 2>/dev/null || true

    if [ ! -f /opt/amnezia/awg/wg0.conf ]; then
        echo "No wg0.conf, skip NAT" >&2
        return 1
    fi

    VPN_SUBNET="$(grep -m1 '^Address' /opt/amnezia/awg/wg0.conf | cut -d= -f2 | tr -d ' ')"
    if [ -z "$VPN_SUBNET" ]; then
        VPN_SUBNET="10.8.1.0/24"
    fi

    OUT_IF="$(ip route show default 2>/dev/null | awk '{print $5}' | head -n1)"
    if [ -z "$OUT_IF" ]; then
        OUT_IF="eth0"
    fi

    if iptables -t nat -L POSTROUTING -n >/dev/null 2>&1; then
        IPT="iptables"
    elif iptables-legacy -t nat -L POSTROUTING -n >/dev/null 2>&1; then
        IPT="iptables-legacy"
    else
        echo "iptables nat unavailable" >&2
        return 1
    fi

    $IPT -C INPUT -i wg0 -j ACCEPT 2>/dev/null || $IPT -A INPUT -i wg0 -j ACCEPT
    $IPT -C FORWARD -i wg0 -j ACCEPT 2>/dev/null || $IPT -A FORWARD -i wg0 -j ACCEPT
    $IPT -C OUTPUT -o wg0 -j ACCEPT 2>/dev/null || $IPT -A OUTPUT -o wg0 -j ACCEPT
    $IPT -C FORWARD -i wg0 -o "$OUT_IF" -s "$VPN_SUBNET" -j ACCEPT 2>/dev/null || \
        $IPT -A FORWARD -i wg0 -o "$OUT_IF" -s "$VPN_SUBNET" -j ACCEPT
    $IPT -C FORWARD -i "$OUT_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || \
        $IPT -A FORWARD -i "$OUT_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT
    $IPT -t nat -C POSTROUTING -s "$VPN_SUBNET" -o "$OUT_IF" -j MASQUERADE 2>/dev/null || \
        $IPT -t nat -A POSTROUTING -s "$VPN_SUBNET" -o "$OUT_IF" -j MASQUERADE

    echo "NAT applied ($VPN_SUBNET -> $OUT_IF via $IPT)"
}

# Wait for config if not exists yet
for i in {1..30}; do
    if [ -f /opt/amnezia/awg/wg0.conf ]; then
        break
    fi
    sleep 1
done

# Prefer amneziawg-go; older images ship the same daemon as wireguard-go
if command -v amneziawg-go >/dev/null 2>&1; then
    export WG_QUICK_USERSPACE_IMPLEMENTATION=amneziawg-go
else
    export WG_QUICK_USERSPACE_IMPLEMENTATION=wireguard-go
fi

# PostUp in wg0.conf also applies NAT; apply_nat_rules is a second pass after wg-quick up
if [ -f /opt/amnezia/awg/wg0.conf ]; then
    wg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || true
    if ! wg-quick up /opt/amnezia/awg/wg0.conf; then
        echo "WireGuard failed to start" >&2
        exit 1
    fi
    echo "WireGuard started"
    apply_nat_rules || echo "NAT apply failed on startup" >&2
else
    echo "No wg0.conf found, skipping WireGuard startup"
fi

tail -f /dev/null
BASH;
        
        $escaped = addslashes(trim($script));
        $this->executeCommand("echo \"{$escaped}\" > /opt/amnezia/amnezia-awg/start.sh", true);
        $this->executeCommand("chmod +x /opt/amnezia/amnezia-awg/start.sh", true);
    }
    
    /**
     * Build Docker image
     */
    private function buildDockerImage(): void {
        $containerName = $this->data['container_name'];
        
        // Cleanup old container/image
        $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
        $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
        $this->executeCommand("docker rmi {$containerName} 2>/dev/null || true", true);
        
        // Build new image
        $buildCmd = sprintf(
            'docker build --no-cache --pull -t %s /opt/amnezia/amnezia-awg',
            $containerName
        );
        $this->executeCommand($buildCmd, true);
    }
    
    /**
     * Run Docker container
     */
    private function runContainer(int $vpnPort): void {
        $containerName = $this->data['container_name'];
        
        $runCmd = sprintf(
            'docker run -d --log-driver none --restart always --privileged --cap-add=NET_ADMIN --cap-add=SYS_MODULE --device /dev/net/tun --sysctl net.ipv4.ip_forward=1 -p %d:%d/udp -v /lib/modules:/lib/modules --name %s %s',
            $vpnPort,
            $vpnPort,
            $containerName,
            $containerName
        );
        
        $this->executeCommand($runCmd, true);
        $this->executeCommand(
            "ufw allow {$vpnPort}/udp >/dev/null 2>&1 || iptables -C INPUT -p udp --dport {$vpnPort} -j ACCEPT >/dev/null 2>&1 || iptables -I INPUT -p udp --dport {$vpnPort} -j ACCEPT >/dev/null 2>&1 || true",
            true
        );
        sleep(3); // Wait for container to start
    }
    
    /**
     * Initialize server configuration with AWG parameters
     */
    private function initializeServerConfig(int $vpnPort): array {
        $containerName = $this->data['container_name'];
        
        // Create directory
        $this->executeCommand("docker exec -i {$containerName} mkdir -p /opt/amnezia/awg", true);
        
        // Generate keys
        $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && umask 077 && wg genkey | tee server_private.key | wg pubkey > wireguard_server_public_key.key'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && wg genpsk > wireguard_psk.key'", true);
        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true);
        
        // Get keys
        $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key", true));
        $pubKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
        $psk = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_psk.key", true));
        
        // Generate AWG parameters
        $awgParams = [
            'Jc' => 3,
            'Jmin' => 10,
            'Jmax' => 50,
            'S1' => rand(50, 250),
            'S2' => rand(50, 250),
            'H1' => rand(100000, 2000000000),
            'H2' => rand(100000, 2000000000),
            'H3' => rand(100000, 2000000000),
            'H4' => rand(100000, 2000000000)
        ];
        
        // Create wg0.conf
        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";
        $wgConfig .= "Address = {$this->data['vpn_subnet']}\n";
        $wgConfig .= "ListenPort = {$vpnPort}\n";
        foreach ($awgParams as $key => $value) {
            $wgConfig .= "{$key} = {$value}\n";
        }

        $natHooks = $this->getNatIptablesHooks($this->data['vpn_subnet']);
        $wgConfig .= "PostUp = {$natHooks['postUp']}\n";
        $wgConfig .= "PostDown = {$natHooks['postDown']}\n";
        $wgConfig .= "\n";
        
        VpnClient::assertValidWgConfigContent($wgConfig);
        VpnClient::writeFileInContainer($this->data, $containerName, '/opt/amnezia/awg/wg0.conf', $wgConfig);
        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true);
        VpnClient::assertValidWgConfigOnServer($this->data);

        // Create clientsTable
        VpnClient::writeFileInContainer($this->data, $containerName, '/opt/amnezia/awg/clientsTable', '[]');

        // Start WireGuard (AmneziaWG via amneziawg-go)
        VpnClient::wgQuickUp($this->data);
        $this->assertWgInterfaceRunning($containerName, $vpnPort);
        
        // Apply firewall rules
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A INPUT -i wg0 -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A OUTPUT -o wg0 -j ACCEPT 2>/dev/null || true'", true);
        $subnet = $this->data['vpn_subnet'];
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A FORWARD -i wg0 -o eth0 -s {$subnet} -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -t nat -A POSTROUTING -s {$subnet} -o eth0 -j MASQUERADE 2>/dev/null || true'", true);
        
        sleep(2);
        
        return [
            'public_key' => $pubKey,
            'preshared_key' => $psk,
            'awg_params' => $awgParams
        ];
    }

    /**
     * Fail deploy early if wg0 did not come up (empty/broken wg0.conf, wrong userspace WG).
     */
    private function assertWgInterfaceRunning(string $containerName, int $vpnPort): void
    {
        $listenPort = trim($this->executeCommand(
            "docker exec -i {$containerName} wg show wg0 listen-port 2>&1",
            true
        ));

        if ((int)$listenPort !== $vpnPort) {
            throw new Exception(
                "WireGuard did not start on port {$vpnPort} (wg show listen-port: " . ($listenPort ?: 'empty') . ")"
            );
        }
    }
    
    public static function isVlessServer(?array $data): bool {
        return ($data['protocol'] ?? 'awg') === 'vless_reality';
    }

    public function getVlessParams(): array {
        $raw = $this->data['vless_params'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Attach to an existing 3x-ui VLESS+Reality inbound (no Docker/AWG).
     */
    private function deployVlessReality(): array {
        $pdo = DB::conn();
        $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
            ->execute(['deploying', $this->serverId]);

        $existing = $this->getVlessParams();
        $xui = new XuiClient($this->data);
        $inbound = $xui->findRealityInbound(
            !empty($existing['inbound_id']) ? (int)$existing['inbound_id'] : null
        );
        $params = XuiClient::extractRealityParams($inbound);
        $vpnPort = (int)$params['listen_port'];
        $warnings = [];
        if ($vpnPort !== 443) {
            $warnings[] = 'Reality listen port is ' . $vpnPort . ', not 443. Other ports are often filtered.';
        }
        if (($params['short_id'] ?? '') === '') {
            $warnings[] = 'Reality shortId is empty. Add a shortId in 3x-ui or clients may fail to connect.';
        }

        $stmt = $pdo->prepare('
            UPDATE vpn_servers
            SET vpn_port = ?,
                server_public_key = ?,
                vless_params = ?,
                status = ?,
                deployed_at = NOW(),
                error_message = NULL
            WHERE id = ?
        ');
        $stmt->execute([
            $vpnPort,
            $params['public_key'],
            json_encode($params, JSON_UNESCAPED_SLASHES),
            'active',
            $this->serverId
        ]);
        $this->load();

        return [
            'success' => true,
            'vpn_port' => $vpnPort,
            'public_key' => $params['public_key'],
            'protocol' => 'vless_reality',
            'sni' => $params['sni'],
            'short_id' => $params['short_id'],
            'dest' => $params['dest'] ?? '',
            'warnings' => $warnings,
        ];
    }

    /**
     * Get server status from database
     */
    public function getStatus(): string {
        return $this->data['status'] ?? 'unknown';
    }

    /**
     * Change public IP/hostname used for SSH and client Endpoint, then rewrite stored configs/QR.
     */
    public function updateHost(string $newHost): int {
        $newHost = trim($newHost);
        if ($newHost === '' || !filter_var($newHost, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9.-]+$/', $newHost)) {
            throw new Exception('Invalid host (use IPv4 or hostname)');
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_servers SET host = ? WHERE id = ?');
        $stmt->execute([$newHost, $this->serverId]);
        $this->load();

        return VpnClient::rewriteEndpointForServer($this->serverId);
    }
    
    /**
     * Get all servers for a user
     */
    public static function listByUser(int $userId): array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all servers (admin only)
     */
    public static function listAll(): array {
        $pdo = DB::conn();
        $stmt = $pdo->query('SELECT s.*, u.email as user_email FROM vpn_servers s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC');
        return $stmt->fetchAll();
    }
    
    /**
     * Delete server
     */
    public function delete(): bool {
        if (!self::isVlessServer($this->data)) {
            try {
                $containerName = $this->data['container_name'];
                $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
                $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
                $this->executeCommand("rm -rf /opt/amnezia/amnezia-awg", true);
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('DELETE FROM vpn_servers WHERE id = ?');
        return $stmt->execute([$this->serverId]);
    }
    
    /**
     * Get server data
     */
    public function getData(): ?array {
        return $this->data;
    }
    
    /**
     * Directory for panel server backup JSON files (separate from update.sh SQL dumps in backups/).
     */
    private static function getBackupDir(): string {
        return dirname(__DIR__) . '/storage/server-backups';
    }

    /**
     * Ensure backup directory exists and is writable by the web server.
     */
    private static function ensureBackupDirectory(): void {
        $dir = self::getBackupDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('Cannot create backup directory: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new Exception(
                'Backup directory is not writable: ' . $dir
                . '. Run: mkdir -p storage/server-backups && chown www-data:www-data storage/server-backups'
            );
        }
    }

    /**
     * Create backup of server configuration and all clients
     * 
     * @param int $userId User who creates the backup
     * @param string $backupType Type: 'manual' or 'automatic'
     * @return int Backup ID
     */
    public function createBackup(int $userId, string $backupType = 'manual'): int {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }
        
        $pdo = DB::conn();
        $backupName = 'backup_' . $this->serverId . '_' . date('Y-m-d_His') . '.json';
        self::ensureBackupDirectory();
        $backupDir = self::getBackupDir();
        $backupPath = $backupDir . '/' . $backupName;
        
        try {
            // Get all clients for this server
            $stmt = $pdo->prepare('
                SELECT id, name, client_ip, public_key, private_key, preshared_key, 
                       config, status, expires_at, created_at
                FROM vpn_clients 
                WHERE server_id = ?
            ');
            $stmt->execute([$this->serverId]);
            $clients = $stmt->fetchAll();
            
            $awgParams = $this->data['awg_params'];
            if (is_string($awgParams)) {
                $decoded = json_decode($awgParams, true);
                $awgParams = is_array($decoded) ? $decoded : $awgParams;
            }
            
            // Prepare backup data
            $backupData = [
                'server' => [
                    'name' => $this->data['name'],
                    'host' => $this->data['host'],
                    'port' => $this->data['port'],
                    'vpn_port' => $this->data['vpn_port'],
                    'vpn_subnet' => $this->data['vpn_subnet'],
                    'container_name' => $this->data['container_name'],
                    'server_public_key' => $this->data['server_public_key'],
                    'preshared_key' => $this->data['preshared_key'],
                    'awg_params' => $awgParams,
                    'protocol' => $this->data['protocol'] ?? 'awg',
                    'panel_web_path' => $this->data['panel_web_path'] ?? '',
                    'vless_params' => $this->getVlessParams(),
                ],
                'clients' => $clients,
                'backup_date' => date('Y-m-d H:i:s'),
                'version' => '1.0'
            ];
            
            // Write backup to file
            $json = json_encode(
                $backupData,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($json === false) {
                throw new Exception('Failed to encode backup JSON: ' . json_last_error_msg());
            }
            
            if (file_put_contents($backupPath, $json) === false) {
                throw new Exception('Failed to write backup file: ' . $backupPath);
            }
            
            $backupSize = filesize($backupPath);
            if ($backupSize === false) {
                throw new Exception('Failed to read backup file size');
            }
            
            // Insert backup record
            $stmt = $pdo->prepare('
                INSERT INTO server_backups 
                (server_id, backup_name, backup_path, backup_size, clients_count, backup_type, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $this->serverId,
                $backupName,
                $backupPath,
                $backupSize,
                count($clients),
                $backupType,
                'completed',
                $userId
            ]);
            
            return (int)$pdo->lastInsertId();
            
        } catch (Throwable $e) {
            // Mark backup as failed
            try {
                $failStmt = $pdo->prepare('
                    INSERT INTO server_backups 
                    (server_id, backup_name, backup_path, backup_type, status, error_message, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $failStmt->execute([
                    $this->serverId,
                    $backupName,
                    $backupPath,
                    $backupType,
                    'failed',
                    $e->getMessage(),
                    $userId
                ]);
            } catch (Throwable $ignored) {
                // ignore secondary failure
            }
            
            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        }
    }
    
    /**
     * List all backups for this server
     * 
     * @return array List of backups
     */
    public function listBackups(): array {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT b.*, u.name as created_by_name, u.email as created_by_email
            FROM server_backups b
            LEFT JOIN users u ON b.created_by = u.id
            WHERE b.server_id = ?
            ORDER BY b.created_at DESC
        ');
        $stmt->execute([$this->serverId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Restore server from backup
     * Note: This only restores client configurations to database
     * Server must already be deployed
     * 
     * @param int $backupId Backup ID
     * @return array Restoration results
     */
    public function restoreBackup(int $backupId): array {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }
        
        if ($this->data['status'] !== 'active') {
            throw new Exception('Server must be active to restore backup');
        }
        
        $pdo = DB::conn();
        
        // Get backup record
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ? AND server_id = ?');
        $stmt->execute([$backupId, $this->serverId]);
        $backup = $stmt->fetch();
        
        if (!$backup) {
            throw new Exception('Backup not found');
        }
        
        if (!file_exists($backup['backup_path'])) {
            throw new Exception('Backup file not found');
        }
        
        // Read backup data
        $backupData = json_decode(file_get_contents($backup['backup_path']), true);
        
        if (!$backupData || !isset($backupData['clients'])) {
            throw new Exception('Invalid backup format');
        }
        
        $restored = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($backupData['clients'] as $clientData) {
            try {
                // Check if client already exists by IP
                $stmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?');
                $stmt->execute([$this->serverId, $clientData['client_ip']]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    $errors[] = "Client {$clientData['name']} already exists";
                    $failed++;
                    continue;
                }
                
                // Insert client
                $stmt = $pdo->prepare('
                    INSERT INTO vpn_clients 
                    (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, 
                     config, status, expires_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                
                $stmt->execute([
                    $this->serverId,
                    $this->data['user_id'],
                    $clientData['name'],
                    $clientData['client_ip'],
                    $clientData['public_key'],
                    $clientData['private_key'],
                    $clientData['preshared_key'],
                    $clientData['config'],
                    'disabled', // Restore as disabled for safety
                    $clientData['expires_at']
                ]);
                
                // Add client to server container
                VpnClient::addClientToServer(
                    $this->data,
                    $clientData['public_key'],
                    $clientData['client_ip'],
                    $clientData['name'],
                    (string)($clientData['private_key'] ?? '')
                );
                
                $restored++;
                
            } catch (Exception $e) {
                $failed++;
                $errors[] = "Failed to restore {$clientData['name']}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => true, // Always success if process completed
            'restored' => $restored,
            'failed' => $failed,
            'total' => count($backupData['clients']),
            'errors' => $errors,
            'message' => $restored > 0 ? "Restored $restored clients" : "No clients restored"
        ];
    }
    
    /**
     * Delete backup
     * 
     * @param int $backupId Backup ID
     * @return bool Success
     */
    public static function deleteBackup(int $backupId): bool {
        $pdo = DB::conn();
        
        // Get backup path
        $stmt = $pdo->prepare('SELECT backup_path FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();
        
        if (!$backup) {
            return false;
        }
        
        // Delete file
        if (file_exists($backup['backup_path'])) {
            unlink($backup['backup_path']);
        }
        
        // Delete record
        $stmt = $pdo->prepare('DELETE FROM server_backups WHERE id = ?');
        return $stmt->execute([$backupId]);
    }
    
    /**
     * Get backup by ID
     * 
     * @param int $backupId Backup ID
     * @return array|null Backup data
     */
    public static function getBackup(int $backupId): ?array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        return $stmt->fetch() ?: null;
    }
}
