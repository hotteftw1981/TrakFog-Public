-- TrakFog Public Beta: persistent login rate limiting.
CREATE TABLE IF NOT EXISTS login_attempts (
    fingerprint_hash CHAR(64) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    INDEX idx_login_attempts_last (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
