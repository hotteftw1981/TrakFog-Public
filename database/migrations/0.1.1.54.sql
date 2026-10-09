-- V0.1.1.54
-- First-run setup state. Existing installations are considered configured.

INSERT INTO settings(setting_key,setting_value)
SELECT 'setup_completed',
       CASE WHEN EXISTS(SELECT 1 FROM users LIMIT 1) THEN '1' ELSE '0' END
ON DUPLICATE KEY UPDATE setting_value=setting_value;

INSERT INTO settings(setting_key,setting_value)
SELECT 'setup_completed_at',
       CASE WHEN EXISTS(SELECT 1 FROM users LIMIT 1) THEN UTC_TIMESTAMP() ELSE NULL END
ON DUPLICATE KEY UPDATE setting_value=setting_value;
