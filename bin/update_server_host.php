#!/usr/bin/env php
<?php
/**
 * Change VPN server public IP/hostname and rewrite client Endpoint + QR.
 *
 * Usage (inside web container):
 *   php bin/update_server_host.php <server_id> <new_ip>
 *
 * Example:
 *   docker compose exec web php bin/update_server_host.php 2 109.120.176.246
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../inc/Config.php';
require __DIR__ . '/../inc/DB.php';
require __DIR__ . '/../inc/VpnClient.php';
require __DIR__ . '/../inc/VpnServer.php';

Config::load(__DIR__ . '/../.env');

$serverId = (int)($argv[1] ?? 0);
$newHost = trim((string)($argv[2] ?? ''));

if ($serverId <= 0 || $newHost === '') {
    fwrite(STDERR, "Usage: php bin/update_server_host.php <server_id> <new_ip>\n");
    exit(1);
}

try {
    $server = new VpnServer($serverId);
    $before = $server->getData();
    $count = $server->updateHost($newHost);
    echo "Server #{$serverId} ({$before['name']}): {$before['host']} -> {$newHost}\n";
    $after = $server->getData();
    echo "Rewrote {$count} client config(s). Keys/UUID unchanged.\n";
    if (VpnServer::isVlessServer($after)) {
        echo "VLESS clients: address in vless://...@{$newHost}:{$after['vpn_port']} (re-download or scan new QR).\n";
    } else {
        echo "Clients must set Endpoint = {$newHost}:{$after['vpn_port']} (or re-download config).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
