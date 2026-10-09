<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);

require_once __DIR__ . '/AppLogger.php';
require_once __DIR__ . '/ErrorPage.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/RuntimeConfig.php';

AppLogger::boot($rootDir);
ErrorHandler::register($rootDir);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (RuntimeConfig::docker() && $forwardedProto === 'https');

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.cookie_secure', $https ? '1' : '0');

    session_name('trakfog_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$config = RuntimeConfig::fromEnvironment();

if (!$config) {
    throw new RuntimeException('TrakFog Docker runtime is not configured.');
}

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Berlin');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Migrations.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Journey.php';
require_once __DIR__ . '/TripMerge.php';
require_once __DIR__ . '/TripConsolidation.php';
require_once __DIR__ . '/TripSoc.php';
require_once __DIR__ . '/TripDeletion.php';
require_once __DIR__ . '/TeslaVehicleArt.php';
require_once __DIR__ . '/LiveViewAuth.php';
require_once __DIR__ . '/TeslaConnector.php';
require_once __DIR__ . '/TeslaService.php';
require_once __DIR__ . '/UpdateService.php';
require_once __DIR__ . '/IntegrationApi.php';
require_once __DIR__ . '/SetupService.php';
require_once __DIR__ . '/DataMigrationService.php';

$pdo = Database::connect($config);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_version(): string
{
    $version = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));
    return $version !== '' ? $version : 'dev';
}

function setting(PDO $pdo, string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value !== false ? $value : $default;
    } catch (Throwable $e) {
        AppLogger::log('warning', 'Could not read setting', [
            'setting_key' => $key,
            'exception' => $e::class,
        ]);
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function report_exception(Throwable $e, string $publicMessage = 'Die Aktion konnte nicht abgeschlossen werden.'): string
{
    $ref = AppLogger::exception($e);
    return $publicMessage . ' Fehler-ID: ' . $ref;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/View.php';
