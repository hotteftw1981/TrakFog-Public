<?php

declare(strict_types=1);

final class ErrorHandler
{
    public static function register(string $root): void
    {
        error_reporting(E_ALL);
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
        @ini_set('log_errors', '1');

        set_exception_handler(static function (Throwable $e): void {
            self::handleException($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!$error || !in_array($error['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $ref = AppLogger::requestId();
            AppLogger::log('fatal', (string)($error['message'] ?? 'Fatal error'), [
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
                'type' => $error['type'] ?? null,
            ]);

            if (!headers_sent()) {
                self::respond500($ref);
            }
        });
    }

    public static function handleException(Throwable $e): never
    {
        $ref = AppLogger::exception($e);
        self::respond500($ref);
    }

    public static function forbidden(string $detail = 'Für diesen Bereich fehlen dir die nötigen Rechte.'): never
    {
        AppLogger::log('warning', 'Forbidden request');
        ErrorPage::render(403, AppLogger::requestId(), $detail);
    }

    private static function respond500(string $reference): never
    {
        if (self::wantsJson()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Interner Serverfehler.',
                'error_id' => $reference,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        ErrorPage::render(500, $reference);
    }

    private static function wantsJson(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($uri, '/api/') || str_contains($accept, 'application/json');
    }
}
