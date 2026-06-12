<?php
class LoginRateLimit {
  private static function maxAttempts(): int {
    return max(1, (int)Config::get('LOGIN_MAX_ATTEMPTS', 5));
  }

  private static function windowMinutes(): int {
    return max(1, (int)Config::get('LOGIN_ATTEMPT_WINDOW_MINUTES', 15));
  }

  private static function lockoutMinutes(): int {
    return max(1, (int)Config::get('LOGIN_LOCKOUT_MINUTES', 15));
  }

  private static function clientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
      $ips = array_map('trim', explode(',', $forwarded));
      $ip = $ips[0] ?? '';
      if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
      }
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
  }

  private static function normalizeEmail(string $email): ?string {
    $email = strtolower(trim($email));
    return $email !== '' ? $email : null;
  }

  private static function failureCount(PDO $pdo, string $field, string $value, int $windowMinutes): int {
    $column = $field === 'email' ? 'email' : 'ip_address';
    $stmt = $pdo->prepare(
      "SELECT COUNT(*) FROM login_attempts
       WHERE {$column} = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$value, $windowMinutes]);
    return (int)$stmt->fetchColumn();
  }

  private static function lastFailureAt(PDO $pdo, string $field, string $value, int $windowMinutes): ?string {
    $column = $field === 'email' ? 'email' : 'ip_address';
    $stmt = $pdo->prepare(
      "SELECT attempted_at FROM login_attempts
       WHERE {$column} = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
       ORDER BY attempted_at DESC LIMIT 1"
    );
    $stmt->execute([$value, $windowMinutes]);
    $attemptedAt = $stmt->fetchColumn();
    return $attemptedAt !== false ? (string)$attemptedAt : null;
  }

  private static function remainingLockoutMinutes(string $email): int {
    $pdo = DB::conn();
    $ip = self::clientIp();
    $email = self::normalizeEmail($email);
    $maxAttempts = self::maxAttempts();
    $windowMinutes = self::windowMinutes();
    $lockoutMinutes = self::lockoutMinutes();
    $remaining = 0;

    $check = function (string $field, string $value) use (
      $pdo, $maxAttempts, $windowMinutes, $lockoutMinutes, &$remaining
    ): void {
      if (self::failureCount($pdo, $field, $value, $windowMinutes) < $maxAttempts) {
        return;
      }

      $lastFailure = self::lastFailureAt($pdo, $field, $value, $windowMinutes);
      if ($lastFailure === null) {
        return;
      }

      $unlockAt = strtotime($lastFailure . ' +' . $lockoutMinutes . ' minutes');
      if ($unlockAt === false || $unlockAt <= time()) {
        return;
      }

      $minutes = (int)ceil(($unlockAt - time()) / 60);
      $remaining = max($remaining, $minutes);
    };

    $check('ip', $ip);
    if ($email !== null) {
      $check('email', $email);
    }

    return $remaining;
  }

  private static function purgeOldAttempts(PDO $pdo): void {
    $keepMinutes = self::windowMinutes() + self::lockoutMinutes() + 60;
    $pdo->prepare(
      'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    )->execute([$keepMinutes]);
  }

  public static function isBlocked(string $email): bool {
    return self::remainingLockoutMinutes($email) > 0;
  }

  public static function blockedMessage(string $email): string {
    $minutes = self::remainingLockoutMinutes($email);
    if ($minutes < 1) {
      $minutes = 1;
    }

    if (class_exists('Translator', false)) {
      return Translator::t('auth.too_many_attempts', [$minutes]);
    }

    return sprintf('Too many login attempts. Try again in %d minutes.', $minutes);
  }

  public static function recordFailure(string $email): void {
    $pdo = DB::conn();
    $ip = self::clientIp();
    $email = self::normalizeEmail($email);

    $stmt = $pdo->prepare('INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)');
    $stmt->execute([$ip, $email]);

    self::purgeOldAttempts($pdo);
  }

  public static function clearSuccess(string $email): void {
    $pdo = DB::conn();
    $ip = self::clientIp();
    $email = self::normalizeEmail($email);

    if ($email !== null) {
      $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?');
      $stmt->execute([$ip, $email]);
      return;
    }

    $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
  }
}
