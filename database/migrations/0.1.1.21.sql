CREATE TABLE IF NOT EXISTS charging_tariffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    provider VARCHAR(120) NULL,
    price_per_kwh DECIMAL(10,4) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tariff_active (active),
    INDEX idx_tariff_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE charges
    ADD COLUMN tariff_id BIGINT UNSIGNED NULL AFTER location_name,
    ADD COLUMN price_per_kwh DECIMAL(10,4) NULL AFTER tariff_id,
    ADD COLUMN cost_source VARCHAR(32) NULL AFTER cost_currency,
    ADD COLUMN cost_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_source,
    ADD CONSTRAINT fk_charge_tariff FOREIGN KEY (tariff_id) REFERENCES charging_tariffs(id) ON DELETE SET NULL,
    ADD INDEX idx_charge_tariff (tariff_id),
    ADD INDEX idx_charge_cost_source (cost_source);

UPDATE charges
SET cost_source='manual',
    cost_locked=1
WHERE cost_amount IS NOT NULL;
