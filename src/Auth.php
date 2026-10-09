<?php

declare(strict_types=1);

final class Auth
{
    private const IDLE_TIMEOUT = 43200;
    private const REGENERATE_AFTER = 1800;
    private const LOGIN_WINDOW_SECONDS = 900;
    private const LOGIN_BLOCK_SECONDS = 600;
    private const MAX_FAILED_LOGINS = 5;

    // Use the actual socket peer, not untrusted X-Forwarded-For headers.
    // Include the account to prevent a shared reverse proxy blocking all owners.
    private static function loginFingerprint(string $login): string
    {
        $peer = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        return hash('sha256', strtolower(trim($login)) . '|' . $peer);
    }

    private static function isLoginBlocked(PDO $pdo, string $fingerprint): bool
    {
        $stmt = $pdo->prepare("SELECT blocked_until FROM login_attempts WHERE fingerprint_hash=? LIMIT 1");
        $stmt->execute([$fingerprint]);
        $until = $stmt->fetchColumn();
        return is_string($until) && $until !== '' && strtotime($until . ' UTC') > time();
    }

    private static function recordLoginFailure(PDO $pdo, string $fingerprint): void
    {
        $now = time();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT attempts, first_attempt_at FROM login_attempts WHERE fingerprint_hash=? FOR UPDATE");
            $stmt->execute([$fingerprint]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->prepare("INSERT IGNORE INTO login_attempts(fingerprint_hash,attempts,first_attempt_at,last_attempt_at,blocked_until) VALUES(?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL)")
                    ->execute([$fingerprint]);
            } else {
                $first = strtotime((string)$row['first_attempt_at'] . ' UTC') ?: 0;
                $attempts = $now - $first >= self::LOGIN_WINDOW_SECONDS ? 1 : ((int)$row['attempts'] + 1);
                $reset = $now - $first >= self::LOGIN_WINDOW_SECONDS;
                $blockedUntil = $attempts >= self::MAX_FAILED_LOGINS
                    ? gmdate('Y-m-d H:i:s', $now + self::LOGIN_BLOCK_SECONDS)
                    : null;
                $pdo->prepare("UPDATE login_attempts SET attempts=?,first_attempt_at=IF(?,UTC_TIMESTAMP(),first_attempt_at),last_attempt_at=UTC_TIMESTAMP(),blocked_until=? WHERE fingerprint_hash=?")
                    ->execute([$attempts, $reset ? 1 : 0, $blockedUntil, $fingerprint]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }


    public static function attempt(PDO $pdo, string $login, string $password): bool
    {
        $normalizedLogin = trim($login);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE active = 1 AND (username = :username OR email = :email) LIMIT 1');
        $stmt->execute(['username' => $normalizedLogin, 'email' => $normalizedLogin]);
        $user = $stmt->fetch();
        // Unknown names share one throttle per socket peer. Otherwise an attacker
        // could fill the database with unlimited distinct login-attempt rows.
        // Existing accounts use the canonical ID to cover username and email aliases.
        $bucket = $user ? 'account:' . (int)$user['id'] : '__unknown__';
        $fingerprint = self::loginFingerprint($bucket);
        if (self::isLoginBlocked($pdo, $fingerprint)) {
            AppLogger::log('warning', 'Login temporarily throttled');
            return false;
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordLoginFailure($pdo, $fingerprint);
            AppLogger::log('warning', 'Login failed', ['login_length' => strlen($normalizedLogin)]);
            return false;
        }

        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint_hash=?')->execute([$fingerprint]);
        return self::startSession($pdo, $user);
    }

    public static function loginUser(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return false;
        }

        return self::startSession($pdo, $user);
    }

    private static function startSession(PDO $pdo, array $user): bool
    {
        session_regenerate_id(true);
        $now = time();

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = (string)$user['username'];
        $_SESSION['role'] = (string)$user['role'];
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['session_regenerated_at'] = $now;

        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
        AppLogger::log('info', 'Login successful', ['user_id'=>(int)$user['id']]);

        return true;
    }

    public static function check(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        $now = time();
        $lastActivity = (int)($_SESSION['last_activity_at'] ?? $now);

        if (($now - $lastActivity) > self::IDLE_TIMEOUT) {
            AppLogger::log('info', 'Session expired after inactivity');
            self::logout();
            return false;
        }

        $lastRegeneration = (int)($_SESSION['session_regenerated_at'] ?? 0);
        if (!headers_sent() && ($now - $lastRegeneration) >= self::REGENERATE_AFTER) {
            session_regenerate_id(true);
            $_SESSION['session_regenerated_at'] = $now;
        }

        $_SESSION['last_activity_at'] = $now;
        return true;
    }

    public static function id(): ?int
    {
        return self::check() ? (int)$_SESSION['user_id'] : null;
    }

    public static function user(PDO $pdo): ?array
    {
        if (!self::check()) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id, username, email, role, created_at, last_login_at FROM users WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;

        if ($user === null) {
            AppLogger::log('warning', 'Session referenced missing or inactive user');
            self::logout();
        }

        return $user;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!in_array($_SESSION['role'] ?? '', ['owner', 'admin'], true)) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::forbidden();
            }
            http_response_code(403);
            exit('Forbidden');
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?: '/',
                    'domain' => $params['domain'] ?: '',
                    'secure' => (bool)$params['secure'],
                    'httponly' => (bool)$params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
