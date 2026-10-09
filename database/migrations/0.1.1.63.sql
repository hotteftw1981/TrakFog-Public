-- One stored trip replaces selected consecutive trips. Movement time excludes stops.
ALTER TABLE trips ADD COLUMN IF NOT EXISTS drive_seconds INT UNSIGNED NULL AFTER ended_at;

-- Hidden technical audit of original trip values, not an extra user-facing tour.
CREATE TABLE IF NOT EXISTS trip_consolidations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  surviving_trip_id BIGINT UNSIGNED NULL,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  original_trip_ids_json LONGTEXT NOT NULL,
  originals_json LONGTEXT NOT NULL,
  merged_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_consolidation_trip FOREIGN KEY (surviving_trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_consolidation_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_consolidation_user FOREIGN KEY (merged_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_consolidation_trip (surviving_trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
