<?php

declare(strict_types=1);

final class TeslaApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly array $diagnostic = []
    ) {
        parent::__construct($message, $statusCode);
    }
}

final class TeslaConnector
{
    private string $apiHost = 'https://owner-api.teslamotors.com';
    private string $authHost = 'https://auth.tesla.com';

    public function __construct(private string $accessToken, private ?string $refreshToken = null)
    {
    }

    public function vehicles(): array
    {
        // Tesla retired the legacy Owner API vehicle-list endpoint for many accounts.
        // TeslaMate uses /api/1/products and filters products containing vehicle_id.
        return $this->request('GET', '/api/1/products');
    }

    public function vehicleData(string $id): array
    {
        $base = '/api/1/vehicles/' . rawurlencode($id) . '/vehicle_data';

        $fullEndpoints = implode(';', [
            'charge_state',
            'climate_state',
            'closures_state',
            'drive_state',
            'gui_settings',
            'location_data',
            'vehicle_config',
            'vehicle_state',
            'vehicle_data_combo',
        ]);

        try {
            $payload = $this->request(
                'GET',
                $base . '?endpoints=' . rawurlencode($fullEndpoints)
            );
            $payload['_trakfog_fetch_mode'] = 'full';
            return $payload;
        } catch (TeslaApiException $e) {
            if ($e->statusCode !== 403) {
                throw $e;
            }
        }

        // Tesla may reject the complete vehicle_data request when the token
        // lacks permission for a single protected data area such as location.
        // Retry with the normal vehicle groups but without location_data.
        $safeEndpoints = implode(';', [
            'charge_state',
            'climate_state',
            'closures_state',
            'drive_state',
            'gui_settings',
            'vehicle_config',
            'vehicle_state',
        ]);

        try {
            $payload = $this->request(
                'GET',
                $base . '?endpoints=' . rawurlencode($safeEndpoints)
            );
            $payload['_trakfog_fetch_mode'] = 'without_location';
            return $payload;
        } catch (TeslaApiException $e) {
            if ($e->statusCode !== 403) {
                throw $e;
            }
        }

        // Final compatibility fallback: let Tesla return the default set of
        // data the current token is allowed to read.
        $payload = $this->request('GET', $base);
        $payload['_trakfog_fetch_mode'] = 'default';
        return $payload;
    }

    public function refresh(): array
    {
        if (!$this->refreshToken) {
            throw new RuntimeException('Kein Refresh Token gespeichert.');
        }

        $payload = json_encode([
            'grant_type' => 'refresh_token',
            'client_id' => 'ownerapi',
            'refresh_token' => $this->refreshToken,
            'scope' => 'openid email offline_access',
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($this->authHost . '/oauth2/v3/token');
        $this->applyTeslaTransport($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $httpVersion = defined('CURLINFO_HTTP_VERSION')
            ? (int)curl_getinfo($ch, CURLINFO_HTTP_VERSION)
            : 0;
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Tesla Auth ist nicht erreichbar: ' . ($error ?: 'unbekannter Netzwerkfehler'));
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Tesla Token-Refresh fehlgeschlagen (HTTP ' . $status . ').');
        }

        $decoded = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        if (empty($decoded['access_token'])) {
            throw new RuntimeException('Tesla hat beim Refresh keinen Access Token geliefert.');
        }

        return $decoded;
    }


    private function applyTeslaTransport(CurlHandle $ch): void
    {
        if (!defined('CURL_HTTP_VERSION_2TLS')) {
            throw new RuntimeException('Der PHP-cURL-Client unterstützt kein HTTP/2. Tesla Owner API benötigt HTTP/2.');
        }

        if (!defined('CURL_SSLVERSION_TLSv1_3')) {
            throw new RuntimeException('Der PHP-cURL-Client unterstützt keine explizite TLS-1.3-Konfiguration.');
        }

        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_3);
    }

    private static function cleanDiagnosticValue(mixed $value, int $limit = 300): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/Bearer\\s+[^\\s]+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/\\b[A-Za-z0-9_-]{20,}\\.[A-Za-z0-9_-]{20,}\\.[A-Za-z0-9_-]{20,}\\b/', '[redacted-jwt]', $text) ?? $text;
        return substr($text, 0, $limit);
    }

    private static function responseDiagnostic(string $body, int $curlHttpVersion): array
    {
        $decoded = json_decode($body, true);
        $teslaError = $description = $txid = null;

        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error)) {
                $teslaError = self::cleanDiagnosticValue(
                    $error['message'] ?? $error['error'] ?? $error['code'] ?? null
                );
                $description = self::cleanDiagnosticValue(
                    $error['description'] ?? $error['error_description'] ?? null
                );
                $txid = self::cleanDiagnosticValue(
                    $error['txid'] ?? $error['request_id'] ?? null,
                    120
                );
            } else {
                $teslaError = self::cleanDiagnosticValue($error);
            }

            $description ??= self::cleanDiagnosticValue(
                $decoded['error_description'] ?? $decoded['message'] ?? $decoded['detail'] ?? null
            );
            $txid ??= self::cleanDiagnosticValue(
                $decoded['txid'] ?? $decoded['request_id'] ?? $decoded['trace_id'] ?? null,
                120
            );
        } else {
            $teslaError = self::cleanDiagnosticValue($body);
        }

        $httpVersion = match ($curlHttpVersion) {
            defined('CURL_HTTP_VERSION_3') ? CURL_HTTP_VERSION_3 : -999 => 'HTTP/3',
            CURL_HTTP_VERSION_2_0 => 'HTTP/2',
            CURL_HTTP_VERSION_1_1 => 'HTTP/1.1',
            CURL_HTTP_VERSION_1_0 => 'HTTP/1.0',
            default => $curlHttpVersion > 0 ? 'curl:' . $curlHttpVersion : null,
        };

        return [
            'tesla_error' => $teslaError,
            'error_description' => $description,
            'txid' => $txid,
            'http_version' => $httpVersion,
        ];
    }

    private function request(string $method, string $path): array
    {
        $ch = curl_init($this->apiHost . $path);
        $this->applyTeslaTransport($ch);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
                'User-Agent: TrakFog/0.1.1.46',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $httpVersion = defined('CURLINFO_HTTP_VERSION')
            ? (int)curl_getinfo($ch, CURLINFO_HTTP_VERSION)
            : 0;
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new TeslaApiException(0, 'Tesla API ist nicht erreichbar: ' . ($error ?: 'unbekannter Netzwerkfehler'), [
                'area' => str_starts_with($path, '/api/1/products') ? 'Fahrzeugliste' : 'API-Bereich',
                'endpoint' => explode('?', $path, 2)[0],
                'tesla_error' => null,
                'error_description' => $error ?: 'unbekannter Netzwerkfehler',
                'txid' => null,
                'http_version' => $httpVersion > 0 ? 'curl:' . $httpVersion : null,
            ]);
        }

        if ($status < 200 || $status >= 300) {
            $area = str_starts_with($path, '/api/1/products')
                ? 'Fahrzeugliste'
                : (str_contains($path, '/vehicle_data') ? 'Fahrzeug-Detaildaten' : 'API-Bereich');
            $diagnostic = self::responseDiagnostic((string)$body, $httpVersion);
            $teslaText = $diagnostic['tesla_error'] ?: $diagnostic['error_description'];

            $message = match ($status) {
                401 => 'Tesla Access Token wurde abgelehnt oder ist abgelaufen'
                    . ($teslaText ? ': ' . $teslaText : '') . '.',
                403 => 'Tesla hat den Bereich „' . $area . '“ abgelehnt (HTTP 403)'
                    . ($teslaText ? ': ' . $teslaText : '') . '.',
                408 => 'Das Fahrzeug schläft oder ist momentan nicht erreichbar (HTTP 408).',
                429 => 'Tesla API limitiert gerade Anfragen (HTTP 429). TrakFog versucht es später automatisch erneut.',
                412 => 'Tesla stellt diesen alten API-Bereich für dein Konto nicht mehr bereit (HTTP 412). Andere TrakFog-Funktionen bleiben aktiv.',
                default => 'Tesla API Fehler (HTTP ' . $status . ') im Bereich „' . $area . '“'
                    . ($teslaText ? ': ' . $teslaText : '') . '.',
            };

            throw new TeslaApiException($status, $message, [
                'area' => $area,
                'endpoint' => explode('?', $path, 2)[0],
                'tesla_error' => $diagnostic['tesla_error'],
                'error_description' => $diagnostic['error_description'],
                'txid' => $diagnostic['txid'],
                'http_version' => $diagnostic['http_version'],
            ]);
        }

        return json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
    }
}
