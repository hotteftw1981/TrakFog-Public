<?php
declare(strict_types=1);

final class TeslaService
{
    public static function integration(PDO $pdo): ?array
    {
        $stmt = $pdo->query("SELECT * FROM integrations WHERE type='tesla_owner_api' ORDER BY id DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    public static function connector(PDO $pdo, array $config): TeslaConnector
    {
        $integration = self::integration($pdo);
        if (!$integration) {
            throw new RuntimeException('Tesla ist noch nicht verbunden.');
        }

        $access = Crypto::decrypt($integration['access_token_enc'], $config);
        $refresh = Crypto::decrypt($integration['refresh_token_enc'], $config);

        if (!$access) {
            throw new RuntimeException('Kein gültiger Access Token gespeichert.');
        }

        return new TeslaConnector($access, $refresh);
    }

    public static function saveTokens(PDO $pdo, array $config, string $access, string $refresh, int $userId): array
    {
        $access = trim($access);
        $refresh = trim($refresh);
        if ($access === '' || $refresh === '') {
            throw new RuntimeException('Bitte Access- und Refresh-Token eintragen.');
        }

        $integration = self::integration($pdo);
        $accessEnc = Crypto::encrypt($access, $config);
        $refreshEnc = Crypto::encrypt($refresh, $config);
        $expiresAt = self::tokenExpiresAt($access);

        if ($integration) {
            $pdo->prepare(
                "UPDATE integrations
                 SET name='Tesla Owner API',status='connected',access_token_enc=?,refresh_token_enc=?,token_expires_at=?,last_error=NULL
                 WHERE id=?"
            )->execute([$accessEnc, $refreshEnc, $expiresAt, $integration['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO integrations(type,name,status,access_token_enc,refresh_token_enc,token_expires_at,created_by)
                 VALUES('tesla_owner_api','Tesla Owner API','connected',?,?,?,?)"
            )->execute([$accessEnc, $refreshEnc, $expiresAt, $userId]);
        }

        AppLogger::log('info', 'Tesla tokens stored', [
            'expires_at' => $expiresAt,
            'access_token_meta' => self::tokenMeta($access),
        ]);

        return self::integration($pdo) ?? [];
    }

    public static function testConnection(PDO $pdo, array $config): array
    {
        $integration = self::integration($pdo);
        if (!$integration) {
            throw new RuntimeException('Tesla ist noch nicht verbunden.');
        }

        [$integration, $refreshed] = self::ensureFreshTokens($pdo, $config, $integration);
        $payload = self::vehicleListWithRecovery($pdo, $config, $integration, $refreshed);

        $products = $payload['response'] ?? [];
        $vehicles = self::vehicleProducts(is_array($products) ? $products : []);
        $count = count($vehicles);

        self::clearProductsIssue($pdo);
        $pdo->prepare(
            "UPDATE integrations
             SET status='connected',last_error=NULL,last_sync_at=UTC_TIMESTAMP()
             WHERE id=?"
        )->execute([$integration['id']]);

        AppLogger::log('info', 'Tesla connection test successful', [
            'vehicle_count' => $count,
            'token_refreshed' => $refreshed,
        ]);

        return [
            'ok' => true,
            'vehicle_count' => $count,
            'refreshed' => $refreshed,
            'expires_at' => (self::integration($pdo)['token_expires_at'] ?? null),
        ];
    }

    public static function refreshTokens(PDO $pdo, array $config): array
    {
        $integration = self::integration($pdo);
        if (!$integration) {
            throw new RuntimeException('Tesla ist noch nicht verbunden.');
        }

        $connector = self::connector($pdo, $config);
        $tokens = $connector->refresh();
        self::storeRefreshedTokens($pdo, $config, $integration, $tokens);

        AppLogger::log('info', 'Tesla token refresh successful', [
            'expires_at' => self::integration($pdo)['token_expires_at'] ?? null,
        ]);

        return self::integration($pdo) ?? [];
    }

    public static function syncVehicles(PDO $pdo, array $config): int
    {
        $integration = self::integration($pdo);
        if (!$integration) {
            throw new RuntimeException('Tesla ist noch nicht verbunden.');
        }

        [$integration, $refreshed] = self::ensureFreshTokens($pdo, $config, $integration);
        $payload = self::vehicleListWithRecovery($pdo, $config, $integration, $refreshed);

        $products = $payload['response'] ?? [];
        $vehicles = self::vehicleProducts(is_array($products) ? $products : []);
        $count = self::upsertVehicles($pdo, (int)$integration['id'], $vehicles);

        self::clearProductsIssue($pdo);
        $pdo->prepare(
            "UPDATE integrations
             SET status='connected',last_error=NULL,last_sync_at=UTC_TIMESTAMP()
             WHERE id=?"
        )->execute([$integration['id']]);

        AppLogger::log('info', 'Tesla vehicles synchronized', [
            'count' => $count,
            'token_refreshed' => $refreshed,
        ]);
        return $count;
    }

    public static function syncVehicleData(PDO $pdo, array $config, int $localVehicleId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id=? LIMIT 1');
        $stmt->execute([$localVehicleId]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            throw new RuntimeException('Fahrzeug nicht gefunden.');
        }

        $integration = self::integration($pdo);
        if (!$integration) {
            throw new RuntimeException('Tesla ist noch nicht verbunden.');
        }

        [$integration] = self::ensureFreshTokens($pdo, $config, $integration);
        $connector = self::connector($pdo, $config);

        try {
            $payload = $connector->vehicleData((string)$vehicle['external_id']);
        } catch (TeslaApiException $e) {
            if ($e->statusCode !== 401) {
                self::rememberApiIssue($pdo, (int)$integration['id'], $e->getMessage(), $e->statusCode, $e->diagnostic);
                throw $e;
            }

            $tokens = $connector->refresh();
            self::storeRefreshedTokens($pdo, $config, $integration, $tokens);
            $connector = self::connector($pdo, $config);
            $payload = $connector->vehicleData((string)$vehicle['external_id']);
        }

        $fetchMode = (string)($payload['_trakfog_fetch_mode'] ?? 'full');
        $d = $payload['response'] ?? [];
        $drive = $d['drive_state'] ?? [];
        $charge = $d['charge_state'] ?? [];
        $state = $d['vehicle_state'] ?? [];

        $milesToKm = static fn($miles): ?float => is_numeric($miles) ? round((float)$miles * 1.609344, 2) : null;
        $mphToKmh = static fn($mph): ?float => is_numeric($mph) ? round((float)$mph * 1.609344, 2) : null;

        $values = [
            'state' => $d['state'] ?? $vehicle['state'],
            'odometer_km' => $milesToKm($state['odometer'] ?? null),
            'battery_level' => isset($charge['battery_level']) ? (float)$charge['battery_level'] : null,
            'usable_battery_level' => isset($charge['usable_battery_level']) ? (float)$charge['usable_battery_level'] : null,
            'rated_range_km' => $milesToKm($charge['battery_range'] ?? null),
            'ideal_range_km' => $milesToKm($charge['ideal_battery_range'] ?? null),
            'latitude' => is_numeric($drive['latitude'] ?? null) ? (float)$drive['latitude'] : null,
            'longitude' => is_numeric($drive['longitude'] ?? null) ? (float)$drive['longitude'] : null,
            'heading' => is_numeric($drive['heading'] ?? null) ? (float)$drive['heading'] : null,
            'speed_kmh' => $mphToKmh($drive['speed'] ?? null),
            'raw_json' => json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '_fetch_mode' => $fetchMode,
        ];

        $stmt = $pdo->prepare(
            'UPDATE vehicles
             SET state=:state,
                 odometer_km=COALESCE(:odometer_km,odometer_km),
                 battery_level=COALESCE(:battery_level,battery_level),
                 usable_battery_level=COALESCE(:usable_battery_level,usable_battery_level),
                 rated_range_km=COALESCE(:rated_range_km,rated_range_km),
                 ideal_range_km=COALESCE(:ideal_range_km,ideal_range_km),
                 latitude=COALESCE(:latitude,latitude),
                 longitude=COALESCE(:longitude,longitude),
                 heading=COALESCE(:heading,heading),
                 speed_kmh=COALESCE(:speed_kmh,speed_kmh),
                 last_seen_at=UTC_TIMESTAMP(),
                 raw_json=:raw_json
             WHERE id=:id'
        );
        $stmt->execute([
            'state' => $values['state'],
            'odometer_km' => $values['odometer_km'],
            'battery_level' => $values['battery_level'],
            'usable_battery_level' => $values['usable_battery_level'],
            'rated_range_km' => $values['rated_range_km'],
            'ideal_range_km' => $values['ideal_range_km'],
            'latitude' => $values['latitude'],
            'longitude' => $values['longitude'],
            'heading' => $values['heading'],
            'speed_kmh' => $values['speed_kmh'],
            'raw_json' => $values['raw_json'],
            'id' => $localVehicleId,
        ]);

        $snap = $pdo->prepare(
            'INSERT INTO vehicle_snapshots(
                vehicle_id,recorded_at,latitude,longitude,speed_kmh,heading,battery_level,usable_battery_level,
                range_km,odometer_km,power_kw,charging_state,raw_json
             ) VALUES(?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?)'
        );
        $snap->execute([
            $localVehicleId,
            $values['latitude'],
            $values['longitude'],
            $values['speed_kmh'],
            $values['heading'],
            $values['battery_level'],
            $values['usable_battery_level'],
            $values['rated_range_km'],
            $values['odometer_km'],
            $drive['power'] ?? null,
            $charge['charging_state'] ?? null,
            $values['raw_json'],
        ]);

        if ($fetchMode !== 'full') {
            AppLogger::log('warning', 'Tesla vehicle_data fallback used', [
                'vehicle_id' => $localVehicleId,
                'fetch_mode' => $fetchMode,
            ]);
        }

        return $values;
    }

    public static function tokenMeta(?string $token): array
    {
        if (!$token) {
            return ['is_jwt' => false, 'expires_at' => null, 'expired' => null];
        }

        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return ['is_jwt' => false, 'expires_at' => null, 'expired' => null];
        }

        $payload = self::decodeJwtPart($parts[1]);
        if (!is_array($payload)) {
            return ['is_jwt' => false, 'expires_at' => null, 'expired' => null];
        }

        $exp = isset($payload['exp']) && is_numeric($payload['exp']) ? (int)$payload['exp'] : null;

        return [
            'is_jwt' => true,
            'expires_at' => $exp ? gmdate('Y-m-d H:i:s', $exp) : null,
            'expired' => $exp ? $exp <= time() : null,
        ];
    }

    private static function tokenExpiresAt(string $accessToken): ?string
    {
        $meta = self::tokenMeta($accessToken);
        return $meta['expires_at'] ?? null;
    }

    private static function vehicleListWithRecovery(
        PDO $pdo,
        array $config,
        array $integration,
        bool &$refreshed
    ): array {
        $connector = self::connector($pdo, $config);

        try {
            return $connector->vehicles();
        } catch (TeslaApiException $e) {
            // A pre-June-2026 access token can still be present after upgrading
            // TrakFog. On 401/403 mint one token over the corrected HTTP/2 +
            // TLS 1.3 auth transport and retry discovery exactly once.
            if (in_array($e->statusCode, [401,403], true)) {
                try {
                    $tokens = $connector->refresh();
                    self::storeRefreshedTokens($pdo, $config, $integration, $tokens);
                    $refreshed = true;
                    $integration = self::integration($pdo) ?? $integration;
                    $connector = self::connector($pdo, $config);
                    $payload = $connector->vehicles();

                    $diagnostic = array_merge($e->diagnostic, [
                        'status' => $e->statusCode,
                        'message' => $e->getMessage(),
                        'source' => 'php',
                        'at' => gmdate('Y-m-d H:i:s'),
                        'refresh_attempted' => true,
                        'refresh_recovered' => true,
                        'retry_result' => 'success',
                        'fallback_active' => false,
                        'resolved' => true,
                        'token_expires_at' => $integration['token_expires_at'] ?? null,
                    ]);
                    self::storeApiDiagnostic($pdo, $diagnostic);

                    AppLogger::log('info', 'Tesla vehicle discovery recovered after token refresh', $diagnostic);
                    return $payload;
                } catch (TeslaApiException $retry) {
                    self::rememberApiIssue(
                        $pdo,
                        (int)$integration['id'],
                        $retry->getMessage(),
                        $retry->statusCode,
                        array_merge($retry->diagnostic, [
                            'initial' => $e->diagnostic,
                            'refresh_attempted' => true,
                            'refresh_recovered' => false,
                            'retry_result' => 'failed',
                            'token_expires_at' => $integration['token_expires_at'] ?? null,
                        ])
                    );
                    throw $retry;
                }
            }

            self::rememberApiIssue(
                $pdo,
                (int)$integration['id'],
                $e->getMessage(),
                $e->statusCode,
                $e->diagnostic
            );
            throw $e;
        }
    }

    private static function ensureFreshTokens(PDO $pdo, array $config, array $integration): array
    {
        $expiresAt = $integration['token_expires_at'] ?? null;
        if (!$expiresAt) {
            return [$integration, false];
        }

        $expiresTs = strtotime((string)$expiresAt . ' UTC');
        if ($expiresTs === false || $expiresTs > (time() + 60)) {
            return [$integration, false];
        }

        $connector = self::connector($pdo, $config);
        $tokens = $connector->refresh();
        self::storeRefreshedTokens($pdo, $config, $integration, $tokens);

        return [self::integration($pdo) ?? $integration, true];
    }

    private static function storeRefreshedTokens(PDO $pdo, array $config, array $integration, array $tokens): void
    {
        $access = trim((string)($tokens['access_token'] ?? ''));
        if ($access === '') {
            throw new RuntimeException('Tesla hat keinen neuen Access Token geliefert.');
        }

        $currentRefresh = Crypto::decrypt($integration['refresh_token_enc'], $config);
        $refresh = trim((string)($tokens['refresh_token'] ?? $currentRefresh ?? ''));

        $pdo->prepare(
            "UPDATE integrations
             SET access_token_enc=?,refresh_token_enc=?,token_expires_at=?,status='connected',last_error=NULL
             WHERE id=?"
        )->execute([
            Crypto::encrypt($access, $config),
            $refresh !== '' ? Crypto::encrypt($refresh, $config) : $integration['refresh_token_enc'],
            self::tokenExpiresAt($access),
            $integration['id'],
        ]);
    }

    private static function vehicleProducts(array $products): array
    {
        return array_values(array_filter(
            $products,
            static fn(mixed $product): bool => is_array($product) && array_key_exists('vehicle_id', $product)
        ));
    }

    private static function upsertVehicles(PDO $pdo, int $integrationId, array $vehicles): int
    {
        $count = 0;

        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }

            $external = (string)($vehicle['id_s'] ?? $vehicle['id'] ?? '');
            if ($external === '') {
                continue;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO vehicles(
                    integration_id,source_type,external_id,vehicle_id,vin,display_name,state,last_seen_at,raw_json
                 ) VALUES(?, 'tesla_owner_api', ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)
                 ON DUPLICATE KEY UPDATE
                    integration_id=VALUES(integration_id),
                    vehicle_id=VALUES(vehicle_id),
                    vin=VALUES(vin),
                    display_name=VALUES(display_name),
                    state=VALUES(state),
                    last_seen_at=VALUES(last_seen_at)"
            );

            $stmt->execute([
                $integrationId,
                $external,
                (string)($vehicle['vehicle_id'] ?? ''),
                $vehicle['vin'] ?? null,
                $vehicle['vehicle_state']['vehicle_name'] ?? $vehicle['display_name'] ?? 'Tesla',
                $vehicle['state'] ?? null,
                json_encode($vehicle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $count++;
        }

        return $count;
    }

    private static function storeApiDiagnostic(PDO $pdo, array $diagnostic): void
    {
        $safe = [
            'status' => $diagnostic['status'] ?? null,
            'area' => $diagnostic['area'] ?? null,
            'endpoint' => $diagnostic['endpoint'] ?? null,
            'tesla_error' => $diagnostic['tesla_error'] ?? null,
            'error_description' => $diagnostic['error_description'] ?? null,
            'txid' => $diagnostic['txid'] ?? null,
            'http_version' => $diagnostic['http_version'] ?? null,
            'message' => $diagnostic['message'] ?? null,
            'source' => $diagnostic['source'] ?? 'php',
            'at' => $diagnostic['at'] ?? gmdate('Y-m-d H:i:s'),
            'token_expires_at' => $diagnostic['token_expires_at'] ?? null,
            'refresh_attempted' => (bool)($diagnostic['refresh_attempted'] ?? false),
            'refresh_recovered' => (bool)($diagnostic['refresh_recovered'] ?? false),
            'retry_result' => $diagnostic['retry_result'] ?? null,
            'fallback_active' => (bool)($diagnostic['fallback_active'] ?? false),
            'resolved' => (bool)($diagnostic['resolved'] ?? false),
            'initial' => is_array($diagnostic['initial'] ?? null) ? $diagnostic['initial'] : null,
        ];

        $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO settings(setting_key,setting_value)
             VALUES('tesla_api_last_diagnostic',?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        )->execute([$json]);
    }

    private static function rememberApiIssue(
        PDO $pdo,
        int $integrationId,
        string $message,
        ?int $statusCode = null,
        array $diagnostic = []
    ): void {
        // /products is vehicle discovery. It is useful, but it is not allowed
        // to masquerade as a global Tesla connection failure once vehicles are
        // already known locally and the driving stream can continue.
        $isProducts = str_contains($message, 'Fahrzeugliste')
            || str_contains(strtolower($message), 'products')
            || (($diagnostic['area'] ?? null) === 'products')
            || (($diagnostic['endpoint'] ?? null) === '/api/1/products');

        $diagnostic = array_merge($diagnostic, [
            'status' => $statusCode,
            'message' => $message,
            'at' => gmdate('Y-m-d H:i:s'),
            'source' => 'php',
            'token_expires_at' => $diagnostic['token_expires_at']
                ?? (self::integration($pdo)['token_expires_at'] ?? null),
            'resolved' => false,
        ]);
        self::storeApiDiagnostic($pdo, $diagnostic);

        if ($isProducts) {
            $issue = json_encode(array_merge($diagnostic, [
                'area' => 'products',
                'fallback_active' => true,
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value)
                 VALUES('tesla_products_last_error',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute([$issue]);

            $pdo->prepare(
                "UPDATE integrations
                 SET last_error=NULL,
                     status=CASE
                        WHEN status='disconnected' THEN status
                        WHEN access_token_enc IS NOT NULL THEN 'connected'
                        ELSE status
                     END
                 WHERE id=?"
            )->execute([$integrationId]);
            return;
        }

        // Other endpoint failures are still retained as integration hints, but
        // never turn a token-backed integration globally red.
        $pdo->prepare(
            "UPDATE integrations
             SET last_error=?,
                 status=CASE
                    WHEN status='disconnected' THEN status
                    WHEN access_token_enc IS NOT NULL THEN 'connected'
                    ELSE status
                 END
             WHERE id=?"
        )->execute([$message, $integrationId]);
    }

    private static function clearProductsIssue(PDO $pdo): void
    {
        $pdo->prepare(
            "INSERT INTO settings(setting_key,setting_value)
             VALUES('tesla_products_last_error',NULL)
             ON DUPLICATE KEY UPDATE setting_value=NULL"
        )->execute();
    }

    private static function decodeJwtPart(string $part): ?array
    {
        $padding = strlen($part) % 4;
        if ($padding) {
            $part .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($part, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
