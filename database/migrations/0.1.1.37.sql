CREATE TABLE IF NOT EXISTS tesla_vehicle_extras (
    vehicle_id BIGINT UNSIGNED PRIMARY KEY,
    nearby_charging_json JSON NULL,
    recent_alerts_json JSON NULL,
    release_notes_json JSON NULL,
    service_data_json JSON NULL,
    synced_at DATETIME NULL,
    last_error TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tesla_vehicle_extras_vehicle
        FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_tesla_vehicle_extras_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES
('tesla_vehicle_extras_enabled','1'),
('tesla_vehicle_extras_interval_minutes','360'),
('tesla_vehicle_extras_last_at',NULL),
('tesla_vehicle_extras_last_error',NULL)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
