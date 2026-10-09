ALTER TABLE trips
    ADD COLUMN start_odometer_km DECIMAL(12,3) NULL AFTER start_longitude,
    ADD COLUMN end_odometer_km DECIMAL(12,3) NULL AFTER start_odometer_km,
    ADD COLUMN start_soc DECIMAL(5,2) NULL AFTER end_odometer_km,
    ADD COLUMN end_soc DECIMAL(5,2) NULL AFTER start_soc,
    ADD COLUMN start_range_km DECIMAL(10,3) NULL AFTER end_soc,
    ADD COLUMN end_range_km DECIMAL(10,3) NULL AFTER start_range_km,
    ADD COLUMN sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_speed_kmh,
    ADD COLUMN last_sample_at DATETIME(3) NULL AFTER sample_count,
    ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'tesla_stream' AFTER last_sample_at;

CREATE INDEX idx_trip_open ON trips(vehicle_id, ended_at);
CREATE INDEX idx_trip_last_sample ON trips(last_sample_at);
