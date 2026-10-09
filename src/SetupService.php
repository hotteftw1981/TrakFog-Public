<?php

declare(strict_types=1);

final class SetupService
{
    private const CLAIM_LOCK = 'trakfog_first_run_setup';

    /** Secret generated once per installation, kept only in the private runtime volume. */
    public static function validateSetupToken(string $supplied): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $supplied)) {
            return false;
        }
        $file = trim((string)(getenv('TRAKFOG_SETUP_TOKEN_FILE') ?: '/var/lib/trakfog/setup_token'));
        $expected = @file_get_contents($file);
        return is_string($expected)
            && preg_match('/^[a-f0-9]{64}$/', trim($expected)) === 1
            && hash_equals(trim($expected), $supplied);
    }

    public static function userCount(PDO $pdo): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function completed(PDO $pdo): bool
    {
        return (string)setting($pdo, 'setup_completed', '0') === '1';
    }

    public static function required(PDO $pdo): bool
    {
        return !self::completed($pdo);
    }

    public static function claimOwner(
        PDO $pdo,
        string $username,
        string $email,
        string $password,
        string $passwordConfirm,
        string $setupToken
    ): int {
        // The lock and password checks do not authorize public account takeover.
        // Require possession of the locally stored first-run secret before any write.
        if (!self::validateSetupToken(trim($setupToken))) {
            throw new RuntimeException('Ungültiger Installationsschlüssel. Bitte den Schlüssel aus dem TrakFog-Webcontainer eingeben.');
        }
        $username = trim($username);
        $email = strtolower(trim($email));

        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
            throw new RuntimeException('Der Benutzername muss 3–80 Zeichen lang sein und darf Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        if (!hash_equals($password, $passwordConfirm)) {
            throw new RuntimeException('Die beiden Passwörter stimmen nicht überein.');
        }

        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $lockStmt->execute([self::CLAIM_LOCK]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new RuntimeException('Die Ersteinrichtung wird gerade in einer anderen Sitzung durchgeführt. Bitte kurz erneut versuchen.');
        }

        try {
            $pdo->beginTransaction();
            if (self::userCount($pdo) !== 0) {
                throw new RuntimeException('Diese TrakFog-Installation wurde bereits beansprucht.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users(username,email,password_hash,role,active)
                 VALUES(?,?,?,'owner',1)"
            );
            $stmt->execute([$username,$email,password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();

            self::writeSetting($pdo, 'setup_completed', '0');
            self::writeSetting($pdo, 'setup_owner_created_at', gmdate('Y-m-d H:i:s'));
            self::writeSetting($pdo, 'setup_owner_source', 'web');

            $pdo->commit();
            AppLogger::log('info', 'First-run owner created', ['user_id'=>$userId]);
            return $userId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::CLAIM_LOCK]);
            } catch (Throwable) {
                // Connection close also releases a named lock.
            }
        }
    }

    public static function complete(PDO $pdo, int $userId): void
    {
        self::writeSetting($pdo, 'setup_completed', '1');
        self::writeSetting($pdo, 'setup_completed_at', gmdate('Y-m-d H:i:s'));
        self::writeSetting($pdo, 'setup_completed_by', (string)$userId);
        AppLogger::log('info', 'First-run setup completed', ['user_id'=>$userId]);
    }

    public static function checks(PDO $pdo, array $config): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'PHP',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'detail' => PHP_VERSION,
            'required' => true,
        ];

        try {
            $pdo->query('SELECT 1')->fetchColumn();
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }
        $checks[] = [
            'label' => 'Datenbank',
            'ok' => $dbOk,
            'detail' => $dbOk ? 'erreichbar' : 'nicht erreichbar',
            'required' => true,
        ];

        $appKey = trim((string)($config['app']['app_key'] ?? ''));
        $checks[] = [
            'label' => 'App-Key',
            'ok' => $appKey !== '',
            'detail' => $appKey !== '' ? 'persistent bereit' : 'fehlt',
            'required' => true,
        ];

        $baseUrl = trim((string)($config['app']['base_url'] ?? ''));
        $https = str_starts_with(strtolower($baseUrl), 'https://')
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '')) === 'https';
        $checks[] = [
            'label' => 'HTTPS',
            'ok' => $https,
            'detail' => $https ? 'erkannt' : 'für lokalen Erststart optional',
            'required' => false,
        ];

        $checks[] = [
            'label' => 'Stable-Updater',
            'ok' => is_file('/var/run/docker.sock'),
            'detail' => is_file('/var/run/docker.sock') ? 'Docker-Socket im Web nicht gemountet erwartet' : 'isolierter Updater-Container',
            'required' => false,
            'neutral' => true,
        ];

        return $checks;
    }

    private static function writeSetting(PDO $pdo, string $key, ?string $value): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings(setting_key,setting_value)
             VALUES(?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute([$key,$value]);
    }
}
