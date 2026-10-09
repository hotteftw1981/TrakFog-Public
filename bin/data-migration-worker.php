<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/RuntimeConfig.php';
require_once dirname(__DIR__) . '/src/AppLogger.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/DataMigrationService.php';

AppLogger::boot(dirname(__DIR__));

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php bin/data-migration-worker.php <job-id>\n");
    exit(2);
}

$config = RuntimeConfig::fromEnvironment();
if (!$config) {
    fwrite(STDERR, "TrakFog Docker runtime is not configured.\n");
    exit(3);
}

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Berlin');

try {
    $pdo = Database::connect($config);
    DataMigrationService::processJob($pdo, $jobId);
    fwrite(STDOUT, "Migration job {$jobId} completed.\n");
    exit(0);
} catch (Throwable $e) {
    AppLogger::exception($e, ['migration_job_id' => $jobId]);
    fwrite(STDERR, "Migration job {$jobId} failed: " . $e->getMessage() . "\n");
    exit(10);
}
