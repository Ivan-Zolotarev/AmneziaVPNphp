-- Add traffic limit field to vpn_clients table (idempotent for fresh docker init)

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vpn_clients'
    AND COLUMN_NAME = 'traffic_limit'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE vpn_clients ADD COLUMN traffic_limit BIGINT UNSIGNED NULL COMMENT ''Traffic limit in bytes (NULL = unlimited)'' AFTER expires_at, ADD INDEX idx_traffic_limit (traffic_limit)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
