CREATE TABLE IF NOT EXISTS journeys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'travel',
    status VARCHAR(24) NOT NULL DEFAULT 'planned',
    destination_label VARCHAR(190) NULL,
    planned_start_at DATETIME NULL,
    planned_end_at DATETIME NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    auto_assign TINYINT(1) NOT NULL DEFAULT 1,
    planned_distance_km DECIMAL(12,2) NULL,
    budget_amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_journey_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_journey_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_journey_vehicle_status (vehicle_id,status),
    INDEX idx_journey_time (started_at,ended_at),
    INDEX idx_journey_planned (planned_start_at,planned_end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_trips (
    journey_id BIGINT UNSIGNED NOT NULL,
    trip_id BIGINT UNSIGNED NOT NULL,
    included TINYINT(1) NOT NULL DEFAULT 1,
    assignment_source VARCHAR(24) NOT NULL DEFAULT 'auto',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (journey_id,trip_id),
    CONSTRAINT fk_journey_trip_journey FOREIGN KEY (journey_id) REFERENCES journeys(id) ON DELETE CASCADE,
    CONSTRAINT fk_journey_trip_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    INDEX idx_journey_trip_trip (trip_id,included)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_charges (
    journey_id BIGINT UNSIGNED NOT NULL,
    charge_id BIGINT UNSIGNED NOT NULL,
    included TINYINT(1) NOT NULL DEFAULT 1,
    assignment_source VARCHAR(24) NOT NULL DEFAULT 'auto',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (journey_id,charge_id),
    CONSTRAINT fk_journey_charge_journey FOREIGN KEY (journey_id) REFERENCES journeys(id) ON DELETE CASCADE,
    CONSTRAINT fk_journey_charge_charge FOREIGN KEY (charge_id) REFERENCES charges(id) ON DELETE CASCADE,
    INDEX idx_journey_charge_charge (charge_id,included)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
