-- V0.1.1.53
-- Integration API tokens and privacy-conscious request audit.

CREATE TABLE IF NOT EXISTS integration_api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    token_prefix VARCHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    scopes VARCHAR(255) NOT NULL DEFAULT 'vehicle:read',
    rate_limit_per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    rate_window_started_at DATETIME NULL,
    rate_window_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    CONSTRAINT fk_integration_api_token_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_integration_api_token_hash (token_hash),
    INDEX idx_integration_api_token_active (revoked_at, created_at),
    INDEX idx_integration_api_token_prefix (token_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_api_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_id BIGINT UNSIGNED NULL,
    method VARCHAR(10) NOT NULL,
    route VARCHAR(190) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL,
    error_code VARCHAR(64) NULL,
    duration_ms INT UNSIGNED NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_integration_api_request_token FOREIGN KEY (token_id) REFERENCES integration_api_tokens(id) ON DELETE SET NULL,
    INDEX idx_integration_api_request_time (requested_at),
    INDEX idx_integration_api_request_token_time (token_id, requested_at),
    INDEX idx_integration_api_request_status (status_code, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
