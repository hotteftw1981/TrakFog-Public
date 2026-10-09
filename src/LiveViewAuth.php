<?php

declare(strict_types=1);

final class LiveViewAuth
{
    private const COOKIE = 'trakfog_live_device';
    private const SESSION_UNTIL = 'liveview_authorized_until';
    private const SESSION_KIND = 'liveview_authorized_kind';
    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_WINDOW_SECONDS = 900;
    private const BLOCK_SECONDS = 300;

    public static function mode(PDO $pdo): string
    {
        $mode = (string)setting($pdo, 'liveview_mode', 'disabled');
        return in_array($mode, ['disabled','pin','open'], true) ? $mode : 'disabled';
    }

    public static function pinConfigured(PDO $pdo): bool
    {
        $hash = setting($pdo, 'liveview_pin_hash', null);
        return is_string($hash) && $hash !== '';
    }

    public static function authorized(PDO $pdo, bool $touch = true): bool
    {
        if (class_exists('Auth') && Auth::check()) {
            return true;
        }

        $mode = self::mode($pdo);
        if ($mode === 'open') {
            return true;
        }
        if ($mode !== 'pin' || !self::pinConfigured($pdo)) {
            return false;
        }

        $until = (int)($_SESSION[self::SESSION_UNTIL] ?? 0);
        if ($until > time()) {
            return true;
        }

        $token = (string)($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            "SELECT id
             FROM liveview_devices
             WHERE token_hash=?
               AND revoked_at IS NULL
               AND expires_at>UTC_TIMESTAMP()
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            self::forgetCookie();
            return false;
        }

        $_SESSION[self::SESSION_UNTIL] = time() + 43200;
        $_SESSION[self::SESSION_KIND] = 'device';

        if ($touch) {
            $pdo->prepare(
                "UPDATE liveview_devices
                 SET last_used_at=UTC_TIMESTAMP()
                 WHERE id=?"
            )->execute([(int)$id]);
        }

        return true;
    }

    public static function accessKind(PDO $pdo): string
    {
        if (class_exists('Auth') && Auth::check()) return 'account';
        if (self::mode($pdo) === 'open') return 'open';
        if ((int)($_SESSION[self::SESSION_UNTIL] ?? 0) > time()) {
            return (string)($_SESSION[self::SESSION_KIND] ?? 'pin');
        }
        return 'none';
    }

    public static function attemptPin(PDO $pdo, string $pin, bool $remember, ?string $deviceHint = null): array
    {
        if (self::mode($pdo) !== 'pin' || !self::pinConfigured($pdo)) {
            return ['ok' => false, 'message' => 'LiveView-PIN ist nicht aktiviert.'];
        }

        $blockedFor = self::blockedFor($pdo);
        if ($blockedFor > 0) {
            return [
                'ok' => false,
                'blocked_for' => $blockedFor,
                'message' => 'Zu viele Fehlversuche. Bitte kurz warten.',
            ];
        }

        $pin = trim($pin);
        if (!preg_match('/^\d{6}$/', $pin)) {
            self::recordFailure($pdo);
            return ['ok' => false, 'message' => 'Bitte die sechsstellige PIN eingeben.'];
        }

        $hash = (string)setting($pdo, 'liveview_pin_hash', '');
        if ($hash === '' || !password_verify($pin, $hash)) {
            self::recordFailure($pdo);
            $blockedFor = self::blockedFor($pdo);
            return [
                'ok' => false,
                'blocked_for' => $blockedFor,
                'message' => $blockedFor > 0
                    ? 'Zu viele Fehlversuche. Der PIN-Zugang ist kurz gesperrt.'
                    : 'PIN ist nicht korrekt.',
            ];
        }

        self::clearFailures($pdo);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_UNTIL] = time() + 43200;
        $_SESSION[self::SESSION_KIND] = $remember ? 'device' : 'pin';

        if ($remember) {
            self::issueTrustedDevice($pdo, $deviceHint);
        }

        AppLogger::log('info', 'LiveView PIN login successful', [
            'remember' => $remember,
        ]);

        return ['ok' => true];
    }

    public static function devices(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id,label,created_at,last_used_at,expires_at,revoked_at
             FROM liveview_devices
             ORDER BY
               CASE WHEN revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() THEN 0 ELSE 1 END,
               COALESCE(last_used_at,created_at) DESC,
               id DESC
             LIMIT 50"
        )->fetchAll();
    }

    public static function revokeDevice(PDO $pdo, int $id): void
    {
        if ($id <= 0) return;
        $pdo->prepare(
            "UPDATE liveview_devices
             SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())
             WHERE id=?"
        )->execute([$id]);
    }

    public static function revokeAllDevices(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE liveview_devices
             SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())
             WHERE revoked_at IS NULL"
        );
    }

    public static function logout(PDO $pdo, bool $revokeDevice = true): void
    {
        if ($revokeDevice) {
            $token = (string)($_COOKIE[self::COOKIE] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                $pdo->prepare(
                    "UPDATE liveview_devices
                     SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())
                     WHERE token_hash=?"
                )->execute([hash('sha256', $token)]);
            }
        }

        unset($_SESSION[self::SESSION_UNTIL], $_SESSION[self::SESSION_KIND]);
        self::forgetCookie();
    }

    private static function issueTrustedDevice(PDO $pdo, ?string $deviceHint): void
    {
        $days = max(1, min(365, (int)setting($pdo, 'liveview_remember_days', '90')));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $label = self::deviceLabel($userAgent, $deviceHint);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($days * 86400));

        $stmt = $pdo->prepare(
            "INSERT INTO liveview_devices(
                token_hash,label,user_agent_hash,last_used_at,expires_at
             )
             VALUES(?,?,?,UTC_TIMESTAMP(),?)"
        );
        $stmt->execute([
            $tokenHash,
            $label,
            $userAgent !== '' ? hash('sha256', $userAgent) : null,
            $expiresAt,
        ]);

        setcookie(self::COOKIE, $token, [
            'expires' => time() + ($days * 86400),
            'path' => '/',
            'secure' => self::secureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    private static function deviceLabel(string $userAgent, ?string $hint): string
    {
        $hint = trim((string)$hint);
        if ($hint !== '' && strlen($hint) <= 120) {
            return $hint;
        }

        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'tesla') => 'Tesla Browser',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'android') && str_contains($ua, 'mobile') => 'Android Smartphone',
            str_contains($ua, 'android') => 'Android Tablet',
            str_contains($ua, 'smart-tv'),
            str_contains($ua, 'smarttv'),
            str_contains($ua, 'hbbtv') => 'Smart TV',
            str_contains($ua, 'windows') => 'Windows Browser',
            str_contains($ua, 'macintosh') => 'Mac Browser',
            default => 'LiveView Browser',
        };
    }

    private static function fingerprint(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', $ip . '|' . $ua);
    }

    private static function blockedFor(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            "SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),blocked_until))
             FROM liveview_access_attempts
             WHERE fingerprint_hash=?
               AND blocked_until IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([self::fingerprint()]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : max(0, (int)$value);
    }

    private static function recordFailure(PDO $pdo): void
    {
        $key = self::fingerprint();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT attempts,first_attempt_at,blocked_until
                 FROM liveview_access_attempts
                 WHERE fingerprint_hash=?
                 FOR UPDATE"
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            $now = time();
            if (!$row) {
                $pdo->prepare(
                    "INSERT INTO liveview_access_attempts(
                        fingerprint_hash,attempts,first_attempt_at,last_attempt_at,blocked_until
                     )
                     VALUES(?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL)"
                )->execute([$key]);
            } else {
                $first = strtotime((string)$row['first_attempt_at'] . ' UTC') ?: $now;
                $blocked = $row['blocked_until']
                    ? (strtotime((string)$row['blocked_until'] . ' UTC') ?: 0)
                    : 0;

                if ($blocked > $now) {
                    $pdo->prepare(
                        "UPDATE liveview_access_attempts
                         SET last_attempt_at=UTC_TIMESTAMP()
                         WHERE fingerprint_hash=?"
                    )->execute([$key]);
                } elseif (($now - $first) > self::ATTEMPT_WINDOW_SECONDS) {
                    $pdo->prepare(
                        "UPDATE liveview_access_attempts
                         SET attempts=1,
                             first_attempt_at=UTC_TIMESTAMP(),
                             last_attempt_at=UTC_TIMESTAMP(),
                             blocked_until=NULL
                         WHERE fingerprint_hash=?"
                    )->execute([$key]);
                } else {
                    $attempts = (int)$row['attempts'] + 1;
                    $blockedUntil = $attempts >= self::MAX_ATTEMPTS
                        ? gmdate('Y-m-d H:i:s', $now + self::BLOCK_SECONDS)
                        : null;

                    $pdo->prepare(
                        "UPDATE liveview_access_attempts
                         SET attempts=?,
                             last_attempt_at=UTC_TIMESTAMP(),
                             blocked_until=?
                         WHERE fingerprint_hash=?"
                    )->execute([$attempts,$blockedUntil,$key]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        AppLogger::log('warning', 'LiveView PIN login failed');
    }

    private static function clearFailures(PDO $pdo): void
    {
        $pdo->prepare(
            "DELETE FROM liveview_access_attempts
             WHERE fingerprint_hash=?"
        )->execute([self::fingerprint()]);
    }

    private static function secureRequest(): bool
    {
        $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || $forwarded === 'https';
    }

    private static function forgetCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::secureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }
}
