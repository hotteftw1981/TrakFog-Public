<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-TrakFog-API-Version: ' . IntegrationApi::API_VERSION);

$started = microtime(true);
$tokenId = null;
$route = trim((string)($_GET['route'] ?? ''));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    if ($method !== 'GET') {
        header('Allow: GET');
        throw new IntegrationApiException(405, 'method_not_allowed', 'Für diesen API-Endpunkt ist nur GET erlaubt.');
    }

    $auth = IntegrationApi::authenticate($pdo, IntegrationApi::SCOPE_VEHICLE_READ);
    $tokenId = (int)$auth['id'];

    if ($route === 'vehicles') {
        $payload = [
            'ok' => true,
            'api_version' => IntegrationApi::API_VERSION,
            'server_at' => gmdate(DATE_ATOM),
            'vehicles' => IntegrationApi::vehicles($pdo),
        ];
        IntegrationApi::audit(
            $pdo,
            $tokenId,
            $method,
            '/api/v1/vehicles',
            200,
            null,
            (int)round((microtime(true) - $started) * 1000)
        );
        $respond($payload);
    }

    if ($route === 'vehicle_status') {
        $vehicleId = max(0, (int)($_GET['vehicle_id'] ?? 0));
        $vehicle = IntegrationApi::vehicleStatus($pdo, $vehicleId);
        if ($vehicle === null) {
            throw new IntegrationApiException(404, 'vehicle_not_found', 'Fahrzeug wurde nicht gefunden.');
        }
        $payload = [
            'ok' => true,
            'api_version' => IntegrationApi::API_VERSION,
            'server_at' => gmdate(DATE_ATOM),
            'vehicle' => $vehicle,
        ];
        IntegrationApi::audit(
            $pdo,
            $tokenId,
            $method,
            '/api/v1/vehicles/' . $vehicleId . '/status',
            200,
            null,
            (int)round((microtime(true) - $started) * 1000)
        );
        $respond($payload);
    }

    throw new IntegrationApiException(404, 'endpoint_not_found', 'API-Endpunkt wurde nicht gefunden.');
} catch (IntegrationApiException $e) {
    if ($e->statusCode === 401) {
        header('WWW-Authenticate: Bearer realm="TrakFog Integration API"');
    }
    if ($e->statusCode === 429) {
        header('Retry-After: 60');
    }
    IntegrationApi::audit(
        $pdo,
        $tokenId,
        $method,
        $route !== '' ? '/api/v1/' . $route : '/api/v1',
        $e->statusCode,
        $e->errorCode,
        (int)round((microtime(true) - $started) * 1000)
    );
    $respond([
        'ok' => false,
        'api_version' => IntegrationApi::API_VERSION,
        'error' => $e->errorCode,
        'message' => $e->getMessage(),
    ], $e->statusCode);
} catch (Throwable $e) {
    $ref = AppLogger::exception($e, ['integration_api_route'=>$route,'integration_api_method'=>$method]);
    IntegrationApi::audit(
        $pdo,
        $tokenId,
        $method,
        $route !== '' ? '/api/v1/' . $route : '/api/v1',
        500,
        'internal_error',
        (int)round((microtime(true) - $started) * 1000)
    );
    $respond([
        'ok' => false,
        'api_version' => IntegrationApi::API_VERSION,
        'error' => 'internal_error',
        'message' => 'Interner API-Fehler.',
        'error_id' => $ref,
    ], 500);
}
