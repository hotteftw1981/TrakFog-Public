ALTER TABLE geofences
    ADD COLUMN IF NOT EXISTS shape_type VARCHAR(20) NOT NULL DEFAULT 'circle' AFTER kind,
    ADD COLUMN IF NOT EXISTS polygon_json MEDIUMTEXT NULL AFTER radius_m;

UPDATE geofences
SET shape_type='circle'
WHERE shape_type IS NULL OR shape_type='';
