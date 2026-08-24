-- Migration: Add LDAP configuration and settings (idempotent)

CREATE TABLE IF NOT EXISTS ldap_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enabled BOOLEAN DEFAULT FALSE,
    host VARCHAR(255) NOT NULL,
    port INT DEFAULT 389,
    use_tls BOOLEAN DEFAULT FALSE,
    base_dn VARCHAR(255) NOT NULL,
    bind_dn VARCHAR(255) NOT NULL,
    bind_password VARCHAR(255) NOT NULL,
    user_search_filter VARCHAR(255) DEFAULT '(uid=%s)',
    group_search_filter VARCHAR(255) DEFAULT '(memberUid=%s)',
    sync_interval INT DEFAULT 30 COMMENT 'Sync interval in minutes',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ldap_group_mappings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ldap_group VARCHAR(255) NOT NULL UNIQUE,
    role_name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'ldap_synced'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN ldap_synced BOOLEAN DEFAULT FALSE AFTER status, ADD COLUMN ldap_dn VARCHAR(255) NULL AFTER ldap_synced, ADD INDEX idx_ldap_dn (ldap_dn)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO ldap_configs (id, enabled, host, port, base_dn, bind_dn, bind_password)
VALUES (1, FALSE, 'ldap.example.com', 389, 'dc=example,dc=com', 'cn=admin,dc=example,dc=com', '');
