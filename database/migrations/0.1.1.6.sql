-- V0.1.1.6 · Tesla-only scope cut
-- Removes the retired generic tracking / exploration data model.

DROP TABLE IF EXISTS location_points;
DROP TABLE IF EXISTS tracking_sessions;
DROP TABLE IF EXISTS tracking_sources;
DROP TABLE IF EXISTS explorer_stats;

DELETE FROM settings WHERE setting_key = 'gps_max_accuracy_m';
