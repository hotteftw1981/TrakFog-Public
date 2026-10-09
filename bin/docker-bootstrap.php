<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/RuntimeConfig.php';
require_once dirname(__DIR__) . '/src/Migrations.php';

$config = RuntimeConfig::fromEnvironment();
if (!$config) {
    fwrite(STDERR, "Docker runtime configuration not available.\n");
    exit(2);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $config['db']['host'],
    (int)$config['db']['port'],
    $config['db']['name']
);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Database not ready: " . $e->getMessage() . "\n");
    exit(3);
}

try {
    $hasUsers = false;
    try {
        $hasUsers = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable) {
        $hasUsers = false;
    }

    if (!$hasUsers) {
        Migrations::runFile($pdo, dirname(__DIR__) . '/database/install.sql');
        fwrite(STDOUT, "Fresh TrakFog schema installed.\n");
    }

    $migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_NATURAL);

    $applied = Migrations::applied($pdo);
    foreach ($migrationFiles as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $applied, true)) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            Migrations::runFile($pdo, $file);
            $pdo->prepare('INSERT INTO schema_migrations(version,description) VALUES(?,?)')
                ->execute([$version, 'Docker startup migration ' . $version]);
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('schema_version',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute([$version]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            fwrite(STDOUT, "Applied migration {$version}.\n");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $username = trim((string)getenv('TRAKFOG_OWNER_USER')) ?: 'admin';
        $email = trim((string)getenv('TRAKFOG_OWNER_EMAIL'));
        $password = (string)getenv('TRAKFOG_OWNER_PASSWORD');

        // A completely blank owner bootstrap intentionally leaves the instance
        // unclaimed so the browser-based first-run wizard can create the owner.
        if ($email === '' && $password === '') {
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('setup_completed','0')
                 ON DUPLICATE KEY UPDATE setting_value='0'"
            )->execute();
            fwrite(STDOUT, "No owner preconfigured. Waiting for first-run web setup.\n");
        } else {
            if ($username === '' || $email === '' || strlen($password) < 10) {
                fwrite(STDERR, "Owner bootstrap is incomplete. Provide email + password (min. 10 chars) or leave both blank for web setup.\n");
                exit(4);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fwrite(STDERR, "TRAKFOG_OWNER_EMAIL is invalid.\n");
                exit(5);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users(username,email,password_hash,role,active)
                 VALUES(?,?,?,'owner',1)"
            );
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);

            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('setup_completed','1')
                 ON DUPLICATE KEY UPDATE setting_value='1'"
            )->execute();
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('setup_completed_at',UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE setting_value=UTC_TIMESTAMP()"
            )->execute();
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('setup_owner_source','environment')
                 ON DUPLICATE KEY UPDATE setting_value='environment'"
            )->execute();

            fwrite(STDOUT, "Owner account created from environment; web setup marked complete.\n");
        }
    }

    $pdo->prepare(
        "INSERT INTO settings(setting_key,setting_value) VALUES('runtime_mode','docker')
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
    )->execute();

    // Reconcile an update request after the new container has started and all
    // migrations have completed successfully.
    $pendingStmt = $pdo->prepare(
        "SELECT setting_value FROM settings WHERE setting_key='update_pending' LIMIT 1"
    );
    $pendingStmt->execute();
    $pendingRaw = $pendingStmt->fetchColumn();
    $pending = is_string($pendingRaw) && trim($pendingRaw) !== ''
        ? json_decode($pendingRaw, true)
        : null;
    $installedVersion = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));

    if (is_array($pending) && !empty($pending['target_version']) && $installedVersion !== '') {
        $targetVersion = (string)$pending['target_version'];
        if (version_compare($installedVersion, $targetVersion, '>=')) {
            $result = array_merge($pending, [
                'status' => 'success',
                'installed_version' => $installedVersion,
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'message' => 'Update erfolgreich gestartet und Migrationen abgeschlossen.',
            ]);
            $resultJson = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('update_last_result',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute([$resultJson]);
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('update_last_success_version',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute([$installedVersion]);
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('update_last_success_at',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute([gmdate('Y-m-d H:i:s')]);
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('update_pending',NULL)
                 ON DUPLICATE KEY UPDATE setting_value=NULL"
            )->execute();

            fwrite(STDOUT, "TrakFog update to V{$installedVersion} confirmed.\n");
        } else {
            fwrite(
                STDOUT,
                "TrakFog update pending: installed V{$installedVersion}, target V{$targetVersion}.\n"
            );
        }
    }

    fwrite(STDOUT, "TrakFog Docker bootstrap ready.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: " . $e->getMessage() . "\n");
    exit(10);
}
