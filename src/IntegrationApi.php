<?php

declare(strict_types=1);

final class IntegrationApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

final class IntegrationApi
{
    public const API_VERSION = 1;
    public const SCOPE_VEHICLE_READ = 'vehicle:read';
    private const TOKEN_PREFIX = 'tfk_';
    private const DEFAULT_RATE_LIMIT = 120;

    public static function createToken(
        PDO $pdo,
        string $name,
        int $createdBy,
        array $scopes = [self::SCOPE_VEHICLE_READ],
        int $rateLimitPerMinute = self::DEFAULT_RATE_LIMIT
    ): array {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('Bitte einen Namen mit maximal 120 Zeichen angeben.');
        }

        $scopes = self::normalizeScopes($scopes);
        if (!$scopes) {
            throw new RuntimeException('Mindestens ein gültiger API-Scope ist erforderlich.');
        }

        $rateLimitPerMinute = max(10, min(600, $rateLimitPerMinute));
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $prefix = substr($token, 0, 16);

        $stmt = $pdo->prepare(
            'INSERT INTO integration_api_tokens
                (name,token_prefix,token_hash,scopes,rate_limit_per_minute,created_by)
             VALUES(?,?,?,?,?,?)'
        );
        $stmt->execute([
            $name,
            $prefix,
            $hash,
            implode(' ', $scopes),
            $rateLimitPerMinute,
            $createdBy > 0 ? $createdBy : null,
        ]);

        return [
            'id' => (int)$pdo->lastInsertId(),
            'name' => $name,
            'token' => $token,
            'token_prefix' => $prefix,
            'scopes' => $scopes,
            'rate_limit_per_minute' => $rateLimitPerMinute,
        ];
    }

    public static function tokens(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT t.id,t.name,t.token_prefix,t.scopes,t.rate_limit_per_minute,
                    t.last_used_at,t.created_at,t.revoked_at,u.username AS created_by_name
             FROM integration_api_tokens t
             LEFT JOIN users u ON u.id=t.created_by
             ORDER BY (t.revoked_at IS NULL) DESC,t.id DESC"
        )->fetchAll();
    }

    public static function revokeToken(PDO $pdo, int $id): void
    {
        if ($id <= 0) {
            throw new RuntimeException('API-Zugang wurde nicht gefunden.');
        }
        $stmt = $pdo->prepare(
            'UPDATE integration_api_tokens
             SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())
             WHERE id=?'
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            $check = $pdo->prepare('SELECT id FROM integration_api_tokens WHERE id=? LIMIT 1');
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('API-Zugang wurde nicht gefunden.');
            }
        }
    }

    public static function authenticate(PDO $pdo, string $requiredScope): array
    {
        $rawToken = self::bearerToken();
        if ($rawToken === null) {
            throw new IntegrationApiException(401, 'missing_token', 'Bearer Token fehlt.');
        }
        if (!str_starts_with($rawToken, self::TOKEN_PREFIX) || strlen($rawToken) < 40 || strlen($rawToken) > 100) {
            throw new IntegrationApiException(401, 'invalid_token', 'Bearer Token ist ungültig.');
        }

        $hash = hash('sha256', $rawToken);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT *
                 FROM integration_api_tokens
                 WHERE token_hash=? AND revoked_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$hash]);
            $token = $stmt->fetch();
            if (!$token) {
                $pdo->rollBack();
                throw new IntegrationApiException(401, 'invalid_token', 'Bearer Token ist ungültig oder wurde gesperrt.');
            }

            $scopes = self::normalizeScopes(preg_split('/[\s,]+/', (string)$token['scopes']) ?: []);
            if (!in_array($requiredScope, $scopes, true)) {
                $pdo->rollBack();
                throw new IntegrationApiException(403, 'insufficient_scope', 'Der API-Zugang besitzt den benötigten Scope nicht.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $windowAt = trim((string)($token['rate_window_started_at'] ?? ''));
            $window = $windowAt !== ''
                ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $windowAt, new DateTimeZone('UTC'))
                : false;
            $count = (int)($token['rate_window_count'] ?? 0);
            if (!$window || ($now->getTimestamp() - $window->getTimestamp()) >= 60) {
                $window = $now;
                $count = 0;
            }
            $count++;
            $limit = max(1, (int)($token['rate_limit_per_minute'] ?? self::DEFAULT_RATE_LIMIT));

            $pdo->prepare(
                'UPDATE integration_api_tokens
                 SET rate_window_started_at=?,rate_window_count=?,last_used_at=UTC_TIMESTAMP()
                 WHERE id=?'
            )->execute([$window->format('Y-m-d H:i:s'),$count,(int)$token['id']]);
            $pdo->commit();

            if ($count > $limit) {
                throw new IntegrationApiException(429, 'rate_limited', 'API-Limit für diesen Zugang überschritten.');
            }

            $token['scopes_array'] = $scopes;
            return $token;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function vehicles(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT id,display_name,vin,source_type,state,battery_level,rated_range_km,last_seen_at
             FROM vehicles
             ORDER BY display_name,id"
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $status = self::vehicleStatus($pdo, (int)$row['id']);
            if ($status !== null) {
                $result[] = [
                    'id' => (int)$status['id'],
                    'name' => $status['name'],
                    'vin' => $status['vin'],
                    'source_type' => $status['source_type'],
                    'state' => $status['state'],
                    'soc' => $status['battery']['soc'],
                    'target_soc' => $status['battery']['target_soc'],
                    'charging' => $status['charging']['active'],
                    'freshness' => $status['freshness'],
                ];
            }
        }
        return $result;
    }

    public static function vehicleStatus(PDO $pdo, int $vehicleId): ?array
    {
        if ($vehicleId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id=? LIMIT 1');
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch();
        if (!$vehicle) {
            return null;
        }

        $streamStmt = $pdo->prepare(
            'SELECT * FROM vehicle_stream_samples WHERE vehicle_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1'
        );
        $streamStmt->execute([$vehicleId]);
        $stream = $streamStmt->fetch() ?: null;

        $snapshotStmt = $pdo->prepare(
            'SELECT * FROM vehicle_snapshots WHERE vehicle_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1'
        );
        $snapshotStmt->execute([$vehicleId]);
        $snapshot = $snapshotStmt->fetch() ?: null;

        $streamStatusStmt = $pdo->prepare(
            'SELECT status,last_event_at FROM vehicle_stream_status WHERE vehicle_id=? LIMIT 1'
        );
        $streamStatusStmt->execute([$vehicleId]);
        $streamStatus = $streamStatusStmt->fetch() ?: null;

        $chargeStmt = $pdo->prepare(
            'SELECT id,started_at,energy_added_kwh FROM charges
             WHERE vehicle_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $chargeStmt->execute([$vehicleId]);
        $activeCharge = $chargeStmt->fetch() ?: null;

        $streamTs = self::sqlTimestamp($stream['recorded_at'] ?? null);
        $snapshotTs = self::sqlTimestamp($snapshot['recorded_at'] ?? null);
        $vehicleTs = self::sqlTimestamp($vehicle['last_seen_at'] ?? null);
        $streamStatusTs = self::sqlTimestamp($streamStatus['last_event_at'] ?? null);
        $latestTs = max(array_filter([$streamTs,$snapshotTs,$vehicleTs,$streamStatusTs], static fn($v): bool => is_int($v)) ?: [0]);
        $ageSeconds = $latestTs > 0 ? max(0, time() - $latestTs) : null;
        $freshness = match (true) {
            $ageSeconds === null => 'unknown',
            $ageSeconds <= 120 => 'fresh',
            $ageSeconds <= 900 => 'stale',
            default => 'offline',
        };

        $streamFresh = $streamTs !== null && (time() - $streamTs) <= 120;
        $snapshotFresh = $snapshotTs !== null && (time() - $snapshotTs) <= 180;

        $snapshotRaw = self::decodeRawJson($snapshot['raw_json'] ?? null);
        $chargeRaw = is_array($snapshotRaw['charge_state'] ?? null) ? $snapshotRaw['charge_state'] : [];

        $soc = $streamFresh && is_numeric($stream['soc'] ?? null)
            ? (float)$stream['soc']
            : (is_numeric($vehicle['battery_level'] ?? null) ? (float)$vehicle['battery_level'] : null);
        $usableSoc = is_numeric($snapshot['usable_battery_level'] ?? null)
            ? (float)$snapshot['usable_battery_level']
            : (is_numeric($vehicle['usable_battery_level'] ?? null) ? (float)$vehicle['usable_battery_level'] : null);
        $targetSoc = is_numeric($chargeRaw['charge_limit_soc'] ?? null)
            ? (float)$chargeRaw['charge_limit_soc']
            : null;
        $rangeKm = $streamFresh && is_numeric($stream['range_km'] ?? null)
            ? (float)$stream['range_km']
            : (is_numeric($vehicle['rated_range_km'] ?? null) ? (float)$vehicle['rated_range_km'] : null);
        $odometerKm = is_numeric($stream['odometer_km'] ?? null)
            ? (float)$stream['odometer_km']
            : (is_numeric($snapshot['odometer_km'] ?? null)
                ? (float)$snapshot['odometer_km']
                : (is_numeric($vehicle['odometer_km'] ?? null) ? (float)$vehicle['odometer_km'] : null));

        $chargingState = trim((string)($snapshot['charging_state'] ?? $chargeRaw['charging_state'] ?? ''));
        $chargerPower = is_numeric($chargeRaw['charger_power'] ?? null)
            ? (float)$chargeRaw['charger_power']
            : ($snapshotFresh && is_numeric($snapshot['power_kw'] ?? null) ? (float)$snapshot['power_kw'] : null);
        $chargingActive = $activeCharge !== null
            || in_array(strtolower($chargingState), ['charging','starting'], true);

        return [
            'id' => (int)$vehicle['id'],
            'name' => (string)($vehicle['display_name'] ?: 'Tesla'),
            'vin' => trim((string)($vehicle['vin'] ?? '')) ?: null,
            'source_type' => (string)($vehicle['source_type'] ?? 'unknown'),
            'state' => (string)($vehicle['state'] ?? 'unknown'),
            'battery' => [
                'soc' => $soc,
                'usable_soc' => $usableSoc,
                'target_soc' => $targetSoc,
                'range_km' => $rangeKm,
            ],
            'charging' => [
                'active' => $chargingActive,
                'state' => $chargingState !== '' ? $chargingState : null,
                'power_kw' => $chargerPower,
                'session_id' => $activeCharge !== null ? (int)$activeCharge['id'] : null,
                'started_at' => self::sqlToAtom($activeCharge['started_at'] ?? null),
                'energy_added_kwh' => is_numeric($activeCharge['energy_added_kwh'] ?? null)
                    ? (float)$activeCharge['energy_added_kwh']
                    : null,
            ],
            'odometer_km' => $odometerKm,
            'freshness' => [
                'status' => $freshness,
                'age_seconds' => $ageSeconds,
                'updated_at' => $latestTs > 0 ? gmdate(DATE_ATOM, $latestTs) : null,
                'stream_status' => trim((string)($streamStatus['status'] ?? '')) ?: null,
            ],
        ];
    }

    public static function audit(
        PDO $pdo,
        ?int $tokenId,
        string $method,
        string $route,
        int $statusCode,
        ?string $errorCode,
        int $durationMs
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO integration_api_requests
                    (token_id,method,route,status_code,error_code,duration_ms)
                 VALUES(?,?,?,?,?,?)'
            );
            $stmt->execute([
                $tokenId && $tokenId > 0 ? $tokenId : null,
                substr(strtoupper($method), 0, 10),
                substr($route, 0, 190),
                $statusCode,
                $errorCode !== null ? substr($errorCode, 0, 64) : null,
                max(0, $durationMs),
            ]);
        } catch (Throwable $e) {
            AppLogger::log('warning', 'Integration API audit write failed', ['exception' => $e::class]);
        }
    }

    public static function stats(PDO $pdo): array
    {
        $tokens = (int)$pdo->query(
            'SELECT COUNT(*) FROM integration_api_tokens WHERE revoked_at IS NULL'
        )->fetchColumn();
        $last24h = (int)$pdo->query(
            'SELECT COUNT(*) FROM integration_api_requests WHERE requested_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY'
        )->fetchColumn();
        $errors24h = (int)$pdo->query(
            'SELECT COUNT(*) FROM integration_api_requests WHERE requested_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY AND status_code >= 400'
        )->fetchColumn();
        return ['active_tokens'=>$tokens,'requests_24h'=>$last24h,'errors_24h'=>$errors24h];
    }

    private static function bearerToken(): ?string
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        return trim((string)$m[1]);
    }

    private static function normalizeScopes(array $scopes): array
    {
        $allowed = [self::SCOPE_VEHICLE_READ];
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (in_array($scope, $allowed, true)) {
                $normalized[$scope] = true;
            }
        }
        return array_keys($normalized);
    }

    private static function decodeRawJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function sqlTimestamp(mixed $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' UTC');
        return $ts === false ? null : $ts;
    }

    private static function sqlToAtom(mixed $value): ?string
    {
        $ts = self::sqlTimestamp($value);
        return $ts !== null ? gmdate(DATE_ATOM, $ts) : null;
    }
}
