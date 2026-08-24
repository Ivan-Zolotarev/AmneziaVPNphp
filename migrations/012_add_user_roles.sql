-- Migration: Add user roles and permissions (idempotent)
-- 001_init.sql already creates users.role — do not fail on fresh docker init.

CREATE TABLE IF NOT EXISTS user_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    permissions JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'role'
);
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_role'
);
SET @sql := IF(
  @col_exists = 0 AND @idx_exists = 0,
  'ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT ''viewer'' AFTER ldap_dn, ADD INDEX idx_role (role)',
  IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT ''viewer'' AFTER ldap_dn',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO user_roles (name, display_name, description, permissions) VALUES
('admin', 'Administrator', 'Full access to all features', JSON_ARRAY('*')),
('manager', 'Manager', 'Can manage servers and clients', JSON_ARRAY('servers.view', 'servers.create', 'servers.edit', 'clients.view', 'clients.create', 'clients.edit', 'clients.delete')),
('viewer', 'Viewer', 'Can only view own clients', JSON_ARRAY('clients.view_own', 'clients.download_own'));

INSERT IGNORE INTO ldap_group_mappings (ldap_group, role_name, description) VALUES
('vpn-admins', 'admin', 'VPN administrators with full access'),
('vpn-managers', 'manager', 'VPN managers who can create and manage clients'),
('vpn-users', 'viewer', 'Regular VPN users with view-only access');

UPDATE users SET role = 'admin' WHERE role IS NULL OR role = '';
