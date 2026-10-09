-- V0.1.1.52
-- Persistent data migration jobs for imports/exports.

CREATE TABLE IF NOT EXISTS data_migration_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction VARCHAR(12) NOT NULL,
    source VARCHAR(40) NOT NULL,
    source_version VARCHAR(40) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    original_name VARCHAR(255) NULL,
    stored_path VARCHAR(500) NULL,
    result_path VARCHAR(500) NULL,
    file_size BIGINT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    progress_message VARCHAR(255) NULL,
    analysis_json LONGTEXT NULL,
    result_json LONGTEXT NULL,
    error_message TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_data_migration_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_data_migration_status (status, created_at),
    INDEX idx_data_migration_source (source, created_at),
    INDEX idx_data_migration_user (created_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
