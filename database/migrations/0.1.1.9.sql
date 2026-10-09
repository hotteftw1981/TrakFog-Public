-- V0.1.1.9 · Repository cleanup
-- Remove settings belonging to the retired shared-hosting cron collector.

DELETE FROM settings
WHERE setting_key IN (
    'collector_enabled',
    'collector_active_seconds',
    'collector_sleep_seconds',
    'collector_last_run_at',
    'collector_last_result',
    'collector_secret'
);
