<?php
/**
 * HTTP client for 3x-ui / X-UI (VLESS + Reality).
 *
 * Supports cookie login (JSON or form), CSRF on newer panels,
 * modern /panel/api/clients/* and legacy /panel/api/inbounds/*addClient.
 */
class XuiClient {
    private string $baseUrl;
    private string $username;
    private string $password;
    private bool $verifyTls;
    private string $cookieFile;
    private bool $loggedIn = false;
    private string $csrfToken = '';

    public function __construct(array $serverData) {
        $host = rtrim((string)$serverData['host'], '/');
        $port = (int)$serverData['port'];
        $https = !empty($serverData['panel_use_https']);
        $path = trim((string)($serverData['panel_web_path'] ?? ''), '/');
        $scheme = $https ? 'https' : 'http';
        $this->baseUrl = $scheme . '://' . $host . ':' . $port . ($path !== '' ? '/' . $path : '');
        $this->username = (string)$serverData['username'];
        $this->password = (string)$serverData['password'];
        $this->verifyTls = $https && empty($serverData['panel_insecure_tls']);
        $this->cookieFile = sys_get_temp_dir() . '/xui_cookie_' . md5($this->baseUrl . $this->username) . '.txt';
    }

    public function login(): void {
        $payload = [
            'username' => $this->username,
            'password' => $this->password,
            'twoFactorCode' => '',
        ];
        $lastError = 'login failed';
        foreach ([true, false] as $asJson) {
            try {
                $res = $this->request('POST', '/login', $payload, [
                    'json' => $asJson,
                    'require_json' => false,
                ]);
                if ($this->isSuccess($res) || $this->looksLoggedIn($res)) {
                    $this->loggedIn = true;
                    $this->refreshCsrf();
                    return;
                }
                $lastError = is_array($res) ? (string)($res['msg'] ?? json_encode($res)) : substr((string)$res, 0, 200);
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }
        }
        throw new Exception('3x-ui login failed: ' . $lastError);
    }

    public function listInbounds(): array {
        $this->ensureLogin();
        $paths = [
            '/panel/api/inbounds/list',
            '/panel/inbound/list',
        ];
        $last = null;
        foreach ($paths as $path) {
            try {
                $res = $this->request('GET', $path);
                if ($this->isSuccess($res) && isset($res['obj'])) {
                    return is_array($res['obj']) ? $res['obj'] : [];
                }
                $last = $res;
            } catch (Exception $e) {
                $last = $e->getMessage();
            }
        }
        throw new Exception('3x-ui inbounds/list failed: ' . json_encode($last));
    }

    public function findRealityInbound(?int $inboundId = null): array {
        $inbounds = $this->listInbounds();
        if ($inboundId) {
            foreach ($inbounds as $ib) {
                if ((int)($ib['id'] ?? 0) === $inboundId) {
                    return $ib;
                }
            }
            throw new Exception('3x-ui inbound id ' . $inboundId . ' not found');
        }
        foreach ($inbounds as $ib) {
            $proto = strtolower((string)($ib['protocol'] ?? ''));
            $stream = $this->decodeJson($ib['streamSettings'] ?? '{}');
            $sec = strtolower((string)($stream['security'] ?? ''));
            if ($proto === 'vless' && $sec === 'reality') {
                return $ib;
            }
        }
        throw new Exception('No VLESS+Reality inbound found in 3x-ui. Create one in the panel first.');
    }

    public function addClient(int $inboundId, string $uuid, string $email, string $flow, int $expiryMs = 0): void {
        $this->ensureLogin();
        $client = [
            'id' => $uuid,
            'flow' => $flow,
            'email' => $email,
            'limitIp' => 0,
            'totalGB' => 0,
            'expiryTime' => $expiryMs,
            'enable' => true,
            'tgId' => '',
            'subId' => substr(bin2hex(random_bytes(8)), 0, 16),
            'reset' => 0,
        ];

        $attempts = [
            [
                'path' => '/panel/api/clients/add',
                'body' => ['client' => $client, 'inboundIds' => [$inboundId]],
                'json' => true,
            ],
            [
                'path' => '/panel/api/inbounds/addClient',
                'body' => [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_UNESCAPED_SLASHES),
                ],
                'json' => true,
            ],
            [
                'path' => '/panel/api/inbounds/addClient',
                'body' => [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_UNESCAPED_SLASHES),
                ],
                'json' => false,
            ],
            [
                'path' => '/panel/inbound/addClient',
                'body' => [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_UNESCAPED_SLASHES),
                ],
                'json' => false,
            ],
        ];

        $last = 'addClient failed';
        foreach ($attempts as $try) {
            try {
                $res = $this->request('POST', $try['path'], $try['body'], ['json' => $try['json']]);
                if ($this->isSuccess($res)) {
                    return;
                }
                $last = is_array($res) ? (string)($res['msg'] ?? json_encode($res)) : (string)$res;
            } catch (Exception $e) {
                $last = $e->getMessage();
            }
        }
        throw new Exception('3x-ui addClient failed: ' . $last);
    }

    public function deleteClient(int $inboundId, string $uuid, string $email = ''): void {
        $this->ensureLogin();
        $paths = [];
        if ($email !== '') {
            $paths[] = ['POST', '/panel/api/clients/del/' . rawurlencode($email), null, true];
        }
        $paths[] = ['POST', '/panel/api/inbounds/' . $inboundId . '/delClient/' . rawurlencode($uuid), null, true];
        $paths[] = ['POST', '/panel/inbound/' . $inboundId . '/delClient/' . rawurlencode($uuid), null, false];

        foreach ($paths as [$method, $path, $body, $requireJson]) {
            try {
                $res = $this->request($method, $path, $body, ['require_json' => $requireJson]);
                if ($this->isSuccess($res) || $res === null) {
                    return;
                }
            } catch (Exception $e) {
                // try next path
            }
        }
    }

    /**
     * Reality fields used in vless:// links and stored in vpn_servers.vless_params.
     */
    public static function extractRealityParams(array $inbound): array {
        $stream = self::decodeJsonStatic($inbound['streamSettings'] ?? '{}');
        $reality = $stream['realitySettings'] ?? [];
        $nested = $reality['settings'] ?? [];
        $names = $reality['serverNames'] ?? [];
        $sni = '';
        if (is_array($names) && $names !== []) {
            $sni = (string)reset($names);
        }
        $shortIds = $reality['shortIds'] ?? [];
        $sid = '';
        if (is_array($shortIds)) {
            foreach ($shortIds as $id) {
                if ((string)$id !== '') {
                    $sid = (string)$id;
                    break;
                }
            }
        }
        $settings = self::decodeJsonStatic($inbound['settings'] ?? '{}');
        $flow = 'xtls-rprx-vision';
        foreach ($settings['clients'] ?? [] as $c) {
            if (!empty($c['flow'])) {
                $flow = (string)$c['flow'];
                break;
            }
        }
        $listen = (int)($inbound['port'] ?? 443);
        $publicKey = (string)($nested['publicKey'] ?? $reality['publicKey'] ?? '');
        if ($publicKey === '') {
            throw new Exception('Reality publicKey is empty on inbound. Check 3x-ui inbound Reality settings.');
        }
        $dest = (string)($reality['dest'] ?? $reality['target'] ?? '');
        if ($sni === '' && $dest !== '') {
            $sni = (string)preg_replace('/:\d+$/', '', $dest);
        }
        if ($sni === '') {
            throw new Exception('Reality inbound has no serverNames/SNI. Set server names in 3x-ui (a site that opens over HTTPS from Russia).');
        }
        return [
            'inbound_id' => (int)($inbound['id'] ?? 0),
            'listen_port' => $listen,
            'public_key' => $publicKey,
            'short_id' => $sid,
            'sni' => $sni,
            'dest' => $dest,
            'fingerprint' => (string)($nested['fingerprint'] ?? 'chrome'),
            'spider_x' => (string)($nested['spiderX'] ?? '/'),
            'flow' => $flow,
            'network' => (string)($stream['network'] ?? 'tcp'),
            'inbound_remark' => (string)($inbound['remark'] ?? ''),
        ];
    }

    public static function buildVlessUri(string $uuid, string $host, int $port, array $params, string $remark): string {
        $query = [
            'encryption' => 'none',
            'flow' => $params['flow'] ?? 'xtls-rprx-vision',
            'security' => 'reality',
            'sni' => $params['sni'] ?? '',
            'fp' => $params['fingerprint'] ?? 'chrome',
            'pbk' => $params['public_key'] ?? '',
            'sid' => $params['short_id'] ?? '',
            'type' => $params['network'] ?? 'tcp',
            'headerType' => 'none',
        ];
        if (!empty($params['spider_x'])) {
            $query['spx'] = $params['spider_x'];
        }
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $frag = rawurlencode($remark !== '' ? $remark : 'vless');
        return 'vless://' . $uuid . '@' . $host . ':' . $port . '?' . $qs . '#' . $frag;
    }

    public static function rewriteVlessHost(string $uri, string $newHost, ?int $newPort = null): string {
        $uri = trim($uri);
        if (!str_starts_with($uri, 'vless://')) {
            return $uri;
        }
        $replacement = '${1}' . $newHost;
        if ($newPort !== null && $newPort > 0) {
            $replacement .= ':' . $newPort;
            return preg_replace('#^(vless://[^@]+@)[^/?#]+#', $replacement, $uri, 1) ?? $uri;
        }
        return preg_replace('#^(vless://[^@]+@)[^:/?#]+#', $replacement, $uri, 1) ?? $uri;
    }

    public static function uuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function ensureLogin(): void {
        if (!$this->loggedIn) {
            $this->login();
        }
    }

    private function refreshCsrf(): void {
        try {
            $res = $this->request('GET', '/csrf-token', null, ['require_json' => false]);
            if (is_array($res) && !empty($res['obj']) && is_string($res['obj'])) {
                $this->csrfToken = $res['obj'];
            }
        } catch (Exception $e) {
            $this->csrfToken = '';
        }
    }

    private function isSuccess($res): bool {
        if (!is_array($res)) {
            return false;
        }
        if (!empty($res['success'])) {
            return true;
        }
        $msg = strtolower((string)($res['msg'] ?? ''));
        return $msg !== '' && (str_contains($msg, 'success') || str_contains($msg, 'logged in'));
    }

    private function looksLoggedIn($res): bool {
        if (is_string($res) && stripos($res, 'success') !== false) {
            return true;
        }
        return false;
    }

    /**
     * @param array{json?:bool,require_json?:bool} $opts
     */
    private function request(string $method, string $path, $body = null, array $opts = []) {
        $url = $this->baseUrl . $path;
        $asJsonBody = !empty($opts['json']);
        $requireJson = $opts['require_json'] ?? true;

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json, text/plain, */*',
            'User-Agent: AmneziaVPNphp/1.0',
        ];
        if ($this->csrfToken !== '' && strtoupper($method) !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADER => true,
        ];

        if (strtoupper($method) === 'POST') {
            $curlOpts[CURLOPT_POST] = true;
            if ($asJsonBody) {
                $headers[] = 'Content-Type: application/json';
                $curlOpts[CURLOPT_POSTFIELDS] = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : '{}';
            } else {
                $curlOpts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string)($body ?? '');
            }
        }

        $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $curlOpts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception('3x-ui HTTP error: ' . $err);
        }

        $headerBlock = substr((string)$raw, 0, $headerSize);
        $responseBody = substr((string)$raw, $headerSize);
        if ($this->csrfToken === '' && preg_match('/^set-cookie:\s*x-ui-csrf=([^;]+)/mi', $headerBlock, $m)) {
            $this->csrfToken = urldecode($m[1]);
        }

        if ($code === 403 && empty($opts['_csrf_retry']) && strtoupper($method) !== 'GET') {
            $this->refreshCsrf();
            if ($this->csrfToken !== '') {
                $opts['_csrf_retry'] = true;
                return $this->request($method, $path, $body, $opts);
            }
        }
        if ($code >= 400) {
            throw new Exception('3x-ui HTTP ' . $code . ' for ' . $path . ': ' . substr((string)$responseBody, 0, 300));
        }

        $decoded = json_decode((string)$responseBody, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (!$requireJson) {
            return (string)$responseBody;
        }
        throw new Exception('3x-ui invalid JSON from ' . $path);
    }

    private function decodeJson($raw): array {
        return self::decodeJsonStatic($raw);
    }

    private static function decodeJsonStatic($raw): array {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
