-- V0.1.1.8 · Docker / Portainer foundation

INSERT INTO settings(setting_key, setting_value) VALUES
('worker_enabled', '1'),
('worker_last_heartbeat', NULL),
('worker_state', 'starting'),
('worker_last_result', NULL),
('worker_last_error', NULL)
ON DUPLICATE KEY UPDATE setting_value = setting_value;
