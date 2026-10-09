<?php

declare(strict_types=1);

final class AppLogger
{
    private static string $root = '';
    private static ?string $requestId = null;

    public static function boot(string $root): void
    {
        self::$root = rtrim($root, DIRECTORY_SEPARATOR);
        self::$requestId ??= self::makeRequestId();
        if (!headers_sent()) {
            header('X-TrakFog-Request-ID: ' . self::$requestId);
        }
    }

    public static function requestId(): string
    {
        self::$requestId ??= self::makeRequestId();
        return self::$requestId;
    }

    public static function path(): string
    {
        return self::$root . '/storage/logs/trakfog-' . date('Y-m-d') . '.log';
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $line = [
            'ts' => date('c'),
            'level' => strtoupper($level),
            'request_id' => self::requestId(),
            'message' => $message,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => self::safeUri(),
            'user_id' => $_SESSION['user_id'] ?? null,
            'context' => self::sanitize($context),
        ];

        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        $dir = dirname(self::path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents(self::path(), $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }

        error_log('[TrakFog ' . self::requestId() . '] ' . $message);
    }

    public static function exception(Throwable $e, array $context = []): string
    {
        self::log('error', $e->getMessage(), array_merge($context, [
            'exception' => $e::class,
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]));

        return self::requestId();
    }

    private static function makeRequestId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, 12);
        }
    }

    private static function safeUri(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($uri)) {
            return null;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : null;
    }

    private static function sanitize(mixed $value, ?string $key = null): mixed
    {
        $sensitive = ['password','pass','token','access_token','refresh_token','authorization','cookie','secret','app_key'];

        if ($key !== null) {
            $lower = strtolower($key);
            foreach ($sensitive as $needle) {
                if (str_contains($lower, $needle)) {
                    return '[redacted]';
                }
            }
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sanitize($v, (string)$k);
            }
            return $out;
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        if (is_string($value) && strlen($value) > 8000) {
            return substr($value, 0, 8000) . '…';
        }

        return $value;
    }
}
