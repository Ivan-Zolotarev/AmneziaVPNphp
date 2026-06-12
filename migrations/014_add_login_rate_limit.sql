-- Login rate limiting: track failed attempts by IP and email

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  email VARCHAR(255) NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at),
  INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'auth', 'too_many_attempts', 'Too many login attempts. Try again in %d minutes.'),
('ru', 'auth', 'too_many_attempts', 'Слишком много попыток входа. Повторите через %d мин.');
