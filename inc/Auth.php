<?php
class Auth {
  public static function register(string $name, string $email, string $password): bool {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (strlen($password) < 6) return false;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$email, $hash, $name ?: $email, 'user', 'active']);
  }

  public static function login(string $email, string $password): bool {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    
    // Try LDAP authentication first if enabled
    $ldap = new LdapSync();
    if ($ldap->isEnabled()) {
      $ldapUser = $ldap->authenticate($email, $password);
      if ($ldapUser) {
        // LDAP auth successful - sync/create user in local DB
        $stmt = $pdo->prepare('SELECT * FROM users WHERE ldap_dn = ? LIMIT 1');
        $stmt->execute([$ldapUser['ldap_dn']]);
        $user = $stmt->fetch();
        
        if (!$user) {
          // Create new LDAP user
          $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status, ldap_synced, ldap_dn) VALUES (?, \'\', ?, ?, \'active\', 1, ?)');
          $stmt->execute([$ldapUser['email'], $ldapUser['display_name'], $ldapUser['role'], $ldapUser['ldap_dn']]);
          $userId = (int)$pdo->lastInsertId();
        } else {
          $userId = (int)$user['id'];
          // Update user info from LDAP
          $stmt = $pdo->prepare('UPDATE users SET email = ?, name = ?, role = ?, status = \'active\', last_login_at = NOW() WHERE id = ?');
          $stmt->execute([$ldapUser['email'], $ldapUser['display_name'], $ldapUser['role'], $userId]);
        }
        
        $_SESSION['user_id'] = $userId;
        return true;
      }
    }
    
    // Fallback to local DB authentication
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if (!password_verify($password, $user['password_hash'])) return false;
    $_SESSION['user_id'] = (int)$user['id'];
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    return true;
  }

  public static function rememberCookieName(): string {
    return (getenv('SESSION_NAME') ?: 'amnezia_panel_session') . '_remember';
  }

  public static function rememberDays(): int {
    $days = (int)(Config::get('LOGIN_REMEMBER_DAYS', 30) ?: 30);
    return max(1, min(365, $days));
  }

  private static function rememberCookieOptions(int $expires): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return [
      'expires' => $expires,
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ];
  }

  public static function issueRememberMe(int $userId): void {
    self::clearRememberCookieToken();
    $selector = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $days = self::rememberDays();
    $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

    try {
      $pdo = DB::conn();
      $pdo->prepare('DELETE FROM remember_tokens WHERE expires_at < NOW()')->execute();
      $stmt = $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
      );
      $stmt->execute([$userId, $selector, $hash, $expiresAt]);
    } catch (Throwable $e) {
      error_log('Remember-me token not stored: ' . $e->getMessage());
      return;
    }

    setcookie(
      self::rememberCookieName(),
      $selector . ':' . $validator,
      self::rememberCookieOptions(time() + $days * 86400)
    );

    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
      'expires' => time() + $days * 86400,
      'path' => $params['path'] ?: '/',
      'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }

  public static function attemptRememberCookie(): void {
    if (self::check()) {
      return;
    }
    $raw = $_COOKIE[self::rememberCookieName()] ?? '';
    if (!is_string($raw) || !str_contains($raw, ':')) {
      return;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '' || !ctype_xdigit($validator)) {
      self::clearRememberCookie();
      return;
    }

    try {
      $pdo = DB::conn();
      $stmt = $pdo->prepare(
        'SELECT t.id, t.user_id, t.validator_hash, u.status
         FROM remember_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.selector = ? AND t.expires_at > NOW()
         LIMIT 1'
      );
      $stmt->execute([$selector]);
      $row = $stmt->fetch();
    } catch (Throwable $e) {
      return;
    }

    if (!$row || ($row['status'] ?? '') !== 'active') {
      self::clearRememberCookie();
      return;
    }
    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
      $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
      self::clearRememberCookie();
      return;
    }

    $_SESSION['user_id'] = (int)$row['user_id'];
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$row['user_id']]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
    self::issueRememberMe((int)$row['user_id']);
  }

  private static function clearRememberCookieToken(): void {
    $raw = $_COOKIE[self::rememberCookieName()] ?? '';
    if (!is_string($raw) || !str_contains($raw, ':')) {
      return;
    }
    $selector = explode(':', $raw, 2)[0];
    try {
      DB::conn()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    } catch (Throwable $e) {
      // table may not exist yet
    }
  }

  public static function clearRememberCookie(): void {
    self::clearRememberCookieToken();
    setcookie(self::rememberCookieName(), '', self::rememberCookieOptions(time() - 3600));
    unset($_COOKIE[self::rememberCookieName()]);
  }

  public static function logout(): void {
    $userId = $_SESSION['user_id'] ?? null;
    self::clearRememberCookie();
    if ($userId) {
      try {
        DB::conn()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([(int)$userId]);
      } catch (Throwable $e) {
        // ignore
      }
    }
    unset($_SESSION['user_id']);
  }

  public static function check(): bool { return isset($_SESSION['user_id']); }

  public static function getUserByEmail(string $email): ?array {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    return $user ?: null;
  }

  public static function user(): ?array {
    if (!self::check()) return null;
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    return $u ?: null;
  }

  public static function isAdmin(): bool {
    $u = self::user();
    return $u && ($u['role'] === 'admin');
  }

  public static function seedAdmin(string $email, string $password): void {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $hash, 'Administrator', 'admin', 'active']);
  }

  public static function listUsers(): array {
    $pdo = DB::conn();
    $stmt = $pdo->query('SELECT id, email, name, role, status, created_at, last_login_at FROM users ORDER BY id DESC');
    return $stmt->fetchAll();
  }

  public static function setRole(int $userId, string $role): bool {
    if (!in_array($role, ['admin','user'], true)) return false;
    $pdo = DB::conn();
    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
    return $stmt->execute([$role, $userId]);
  }

  public static function saveSetting(?int $userId, string $namespace, string $key, string $valueJson): bool {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('INSERT INTO settings (user_id, namespace, `key`, `value`) VALUES (?, ?, ?, CAST(? AS JSON))
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()');
    return $stmt->execute([$userId, $namespace, $key, $valueJson]);
  }

  public static function getSetting(?int $userId, string $namespace, string $key): array {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE user_id <=> ? AND namespace = ? AND `key` = ? LIMIT 1');
    $stmt->execute([$userId, $namespace, $key]);
    $val = $stmt->fetchColumn();
    if (!$val) return [];
    $decoded = json_decode($val, true);
    return is_array($decoded) ? $decoded : [];
  }
}