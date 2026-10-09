ALTER TABLE charges
    ADD COLUMN tesla_charge_session_id VARCHAR(190) NULL AFTER cost_locked,
    ADD COLUMN tesla_site_name VARCHAR(190) NULL AFTER tesla_charge_session_id,
    ADD COLUMN tesla_cost_synced_at DATETIME NULL AFTER tesla_site_name,
    ADD COLUMN tesla_history_json JSON NULL AFTER tesla_cost_synced_at,
    ADD UNIQUE KEY uq_charge_tesla_session (tesla_charge_session_id),
    ADD INDEX idx_charge_tesla_cost_synced (tesla_cost_synced_at);

INSERT INTO settings(setting_key,setting_value) VALUES
('tesla_charging_cost_sync_enabled','1'),
('tesla_charging_cost_sync_interval_minutes','90'),
('tesla_charging_cost_sync_force','0'),
('tesla_charging_cost_sync_last_at',NULL),
('tesla_charging_cost_sync_last_error',NULL),
('tesla_charging_cost_sync_last_summary',NULL)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
