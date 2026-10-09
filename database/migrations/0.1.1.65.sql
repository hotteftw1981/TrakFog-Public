-- Optional verified/manually-entered VoltCore session meter reading per Tesla charge.
-- The Tesla-side value is preserved; no automatic VoltCore import is implied.
ALTER TABLE charges
  ADD COLUMN IF NOT EXISTS voltcore_energy_kwh DECIMAL(12,3) NULL,
  ADD COLUMN IF NOT EXISTS voltcore_session_ref VARCHAR(40) NULL;
