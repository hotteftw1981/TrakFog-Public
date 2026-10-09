<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require dirname(__DIR__) . '/src/bootstrap.php';
    $pdo->query('SELECT 1')->fetchColumn();

    echo json_encode([
        'ok' => true,
        'service' => 'trakfog-web',
        'version' => app_version(),
        'runtime' => RuntimeConfig::docker() ? 'docker' : 'webspace',
        'database' => 'ok',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'service' => 'trakfog-web',
        'error' => 'unavailable',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
