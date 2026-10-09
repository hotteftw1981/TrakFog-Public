SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(32) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value LONGTEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    status ENUM('disconnected','connected','error') NOT NULL DEFAULT 'disconnected',
    access_token_enc MEDIUMTEXT NULL,
    refresh_token_enc MEDIUMTEXT NULL,
    token_expires_at DATETIME NULL,
    config_json JSON NULL,
    last_error TEXT NULL,
    last_sync_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_integrations_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_integrations_type (type),
    INDEX idx_integrations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(40) NOT NULL DEFAULT 'tesla_owner_api',
    external_id VARCHAR(190) NULL,
    vehicle_id VARCHAR(190) NULL,
    vin VARCHAR(64) NULL,
    display_name VARCHAR(120) NULL,
    model_name VARCHAR(80) NULL,
    state VARCHAR(40) NULL,
    odometer_km DECIMAL(12,2) NULL,
    battery_level DECIMAL(5,2) NULL,
    usable_battery_level DECIMAL(5,2) NULL,
    rated_range_km DECIMAL(10,2) NULL,
    ideal_range_km DECIMAL(10,2) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    heading DECIMAL(7,2) NULL,
    speed_kmh DECIMAL(8,2) NULL,
    last_seen_at DATETIME NULL,
    raw_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vehicles_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE SET NULL,
    UNIQUE KEY uq_vehicle_source_external (source_type, external_id),
    INDEX idx_vehicle_vin (vin),
    INDEX idx_vehicle_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    speed_kmh DECIMAL(8,2) NULL,
    heading DECIMAL(7,2) NULL,
    battery_level DECIMAL(5,2) NULL,
    usable_battery_level DECIMAL(5,2) NULL,
    range_km DECIMAL(10,2) NULL,
    odometer_km DECIMAL(12,2) NULL,
    power_kw DECIMAL(10,2) NULL,
    charging_state VARCHAR(60) NULL,
    raw_json JSON NULL,
    CONSTRAINT fk_snapshot_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_snapshot_vehicle_time (vehicle_id, recorded_at),
    INDEX idx_snapshot_time (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_stream_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(3) NOT NULL,
    speed_kmh DECIMAL(8,2) NULL,
    odometer_km DECIMAL(12,3) NULL,
    soc DECIMAL(5,2) NULL,
    elevation_m DECIMAL(10,2) NULL,
    est_heading DECIMAL(7,2) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    power_kw DECIMAL(10,2) NULL,
    shift_state VARCHAR(8) NULL,
    range_km DECIMAL(10,3) NULL,
    est_range_km DECIMAL(10,3) NULL,
    heading DECIMAL(7,2) NULL,
    raw_value TEXT NULL,
    CONSTRAINT fk_stream_sample_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_stream_sample_vehicle_time (vehicle_id, recorded_at),
    INDEX idx_stream_sample_time (recorded_at),
    INDEX idx_stream_sample_shift (vehicle_id, shift_state, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_stream_status (
    vehicle_id BIGINT UNSIGNED PRIMARY KEY,
    status VARCHAR(40) NOT NULL DEFAULT 'starting',
    connected_at DATETIME NULL,
    last_event_at DATETIME(3) NULL,
    last_error TEXT NULL,
    update_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shift_state VARCHAR(8) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_stream_status_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_stream_status_status (status),
    INDEX idx_stream_status_event (last_event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    start_latitude DECIMAL(10,7) NULL,
    start_longitude DECIMAL(10,7) NULL,
    end_latitude DECIMAL(10,7) NULL,
    end_longitude DECIMAL(10,7) NULL,
    distance_km DECIMAL(12,3) NULL,
    energy_kwh DECIMAL(12,3) NULL,
    avg_wh_km DECIMAL(10,2) NULL,
    max_speed_kmh DECIMAL(8,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_trip_vehicle_time (vehicle_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS charges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    energy_added_kwh DECIMAL(12,3) NULL,
    start_battery_percent DECIMAL(5,2) NULL,
    end_battery_percent DECIMAL(5,2) NULL,
    max_power_kw DECIMAL(10,2) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_name VARCHAR(190) NULL,
    cost_amount DECIMAL(12,2) NULL,
    cost_currency CHAR(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_charge_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_charge_vehicle_time (vehicle_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key, setting_value) VALUES
('worker_enabled', '1'),
('worker_last_heartbeat', NULL),
('worker_state', 'starting'),
('worker_last_result', NULL),
('worker_last_error', NULL)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT IGNORE INTO schema_migrations(version, description) VALUES
('0.1.1.6', 'Tesla-only TrakFog schema'),
('0.1.1.7', 'Legacy collector baseline'),
('0.1.1.8', 'Docker and persistent worker baseline'),
('0.1.1.9', 'Repository cleanup and legacy collector removal'),
('0.1.1.12', 'Tesla Owner API driving stream foundation');

INSERT INTO settings(setting_key, setting_value) VALUES ('schema_version', '0.1.1.12')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
