-- V0.1.1.7 · Sleep-friendly Tesla collector defaults

INSERT INTO settings(setting_key, setting_value) VALUES
('collector_enabled', '0'),
('collector_active_seconds', '60'),
('collector_sleep_seconds', '300'),
('collector_last_run_at', NULL),
('collector_last_result', NULL)
ON DUPLICATE KEY UPDATE setting_value = setting_value;
