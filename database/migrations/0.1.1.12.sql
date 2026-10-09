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
