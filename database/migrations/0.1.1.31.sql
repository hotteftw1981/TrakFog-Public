CREATE TABLE IF NOT EXISTS liveview_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    user_agent_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_liveview_device_token (token_hash),
    INDEX idx_liveview_device_valid (expires_at,revoked_at),
    INDEX idx_liveview_device_last_used (last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS liveview_access_attempts (
    fingerprint_hash CHAR(64) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    INDEX idx_liveview_attempt_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings(setting_key,setting_value) VALUES
('liveview_mode','disabled'),
('liveview_pin_hash',NULL),
('liveview_remember_days','90'),
('liveview_default_vehicle_id',NULL),
('liveview_location_share','0'),
('liveview_other_teslas_layer','0'),
('liveview_community_places_layer','0');
