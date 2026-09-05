-- VLESS + Reality (3x-ui) alongside existing AmneziaWG servers

ALTER TABLE vpn_servers
  ADD COLUMN protocol VARCHAR(32) NOT NULL DEFAULT 'awg' AFTER container_name,
  ADD COLUMN panel_web_path VARCHAR(255) NULL DEFAULT '' AFTER protocol,
  ADD COLUMN panel_use_https TINYINT(1) NOT NULL DEFAULT 1 AFTER panel_web_path,
  ADD COLUMN panel_insecure_tls TINYINT(1) NOT NULL DEFAULT 1 AFTER panel_use_https,
  ADD COLUMN vless_params JSON NULL COMMENT 'inbound_id, public_key, short_id, sni, flow, dest, fingerprint' AFTER awg_params;

ALTER TABLE vpn_servers ADD INDEX idx_protocol (protocol);

INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'servers', 'protocol', 'Protocol'),
('ru', 'servers', 'protocol', 'Протокол'),
('en', 'servers', 'protocol_awg', 'AmneziaWG (SSH deploy)'),
('ru', 'servers', 'protocol_awg', 'AmneziaWG (деплой по SSH)'),
('en', 'servers', 'protocol_vless', 'VLESS + Reality (3x-ui API)'),
('ru', 'servers', 'protocol_vless', 'VLESS + Reality (API 3x-ui)'),
('en', 'servers', 'xui_port', '3x-ui panel port'),
('ru', 'servers', 'xui_port', 'Порт панели 3x-ui'),
('en', 'servers', 'xui_web_path', '3x-ui web base path (optional)'),
('ru', 'servers', 'xui_web_path', 'Web path 3x-ui (необязательно)'),
('en', 'servers', 'xui_inbound_id', 'Reality inbound ID (empty = first VLESS+Reality)'),
('ru', 'servers', 'xui_inbound_id', 'ID инбаунда Reality (пусто = первый VLESS+Reality)'),
('en', 'servers', 'xui_https', 'Use HTTPS to talk to 3x-ui'),
('ru', 'servers', 'xui_https', 'HTTPS для API 3x-ui'),
('en', 'servers', 'xui_user', '3x-ui username'),
('ru', 'servers', 'xui_user', 'Логин 3x-ui'),
('en', 'servers', 'xui_password', '3x-ui password'),
('ru', 'servers', 'xui_password', 'Пароль 3x-ui');
