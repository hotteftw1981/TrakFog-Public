ALTER TABLE charges
    ADD COLUMN end_reason VARCHAR(40) NULL AFTER ended_at,
    ADD COLUMN last_state VARCHAR(32) NULL AFTER end_reason,
    ADD COLUMN last_active_at DATETIME NULL AFTER last_state,
    ADD COLUMN last_sample_at DATETIME NULL AFTER last_active_at,
    ADD COLUMN sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sample_at,
    ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'tesla_rest' AFTER sample_count;

CREATE INDEX idx_charge_open ON charges(vehicle_id, ended_at);
CREATE INDEX idx_charge_last_active ON charges(last_active_at);
