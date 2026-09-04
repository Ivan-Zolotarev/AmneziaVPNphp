-- Persistent login ("Remember me") — random token, not the password

CREATE TABLE IF NOT EXISTS remember_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_selector (selector),
  INDEX idx_user (user_id),
  INDEX idx_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'auth', 'remember_me', 'Remember me'),
('ru', 'auth', 'remember_me', 'Запомнить меня'),
('es', 'auth', 'remember_me', 'Recordarme'),
('de', 'auth', 'remember_me', 'Angemeldet bleiben'),
('fr', 'auth', 'remember_me', 'Se souvenir de moi'),
('zh', 'auth', 'remember_me', '记住我'),
('en', 'auth', 'remember_me_hint', 'Stay signed in on this device (30 days). Do not use on a shared computer.'),
('ru', 'auth', 'remember_me_hint', 'Оставаться в системе на этом устройстве (30 дней). Не используйте на чужом компьютере.');
