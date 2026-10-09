<?php

declare(strict_types=1);

final class UpdateService
{
    private const DEFAULT_REPOSITORY = 'hotteftw1981/TrakFog-Public';
    private const CACHE_SECONDS = 21600;

    public static function status(PDO $pdo, array $config, bool $force = false): array
    {
        $installed = self::currentVersion();
        $repository = self::repository($pdo);
        $cached = self::cachedRelease($pdo);
        $checkedAt = self::getSetting($pdo, 'update_latest_checked_at');
        $checkedTs = $checkedAt ? strtotime((string)$checkedAt . ' UTC') : false;
        $cacheFresh = !$force
            && $checkedTs !== false
            && (time() - $checkedTs) < self::CACHE_SECONDS;

        if ($cacheFresh) {
            if (is_array($cached)) {
                return self::buildStatus($installed, $repository, $cached, false, null);
            }

            if ((string)self::getSetting($pdo, 'update_last_check_status', '') === 'error') {
                return [
                    'installed_version' => $installed,
                    'repository' => $repository,
                    'latest_version' => null,
                    'update_available' => false,
                    'checked_at' => $checkedAt,
                    'stale' => false,
                    'error' => (string)self::getSetting(
                        $pdo,
                        'update_last_check_error',
                        'Update-Prüfung fehlgeschlagen.'
                    ),
                    'release' => null,
                ];
            }
        }

        try {
            $release = self::fetchLatestRelease($pdo, $config, $repository);
            $now = gmdate('Y-m-d H:i:s');

            self::setSetting(
                $pdo,
                'update_latest_release',
                json_encode($release, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            self::setSetting($pdo, 'update_latest_checked_at', $now);
            self::setSetting($pdo, 'update_last_check_status', 'ok');
            self::setSetting($pdo, 'update_last_check_error', null);

            return self::buildStatus($installed, $repository, $release, false, null);
        } catch (Throwable $e) {
            $ref = AppLogger::exception($e, [
                'update_repository' => $repository,
                'update_check' => true,
            ]);

            self::setSetting($pdo, 'update_latest_checked_at', gmdate('Y-m-d H:i:s'));
            self::setSetting($pdo, 'update_last_check_status', 'error');
            self::setSetting(
                $pdo,
                'update_last_check_error',
                self::publicError($e) . ' · Fehler-ID: ' . $ref
            );

            if (is_array($cached)) {
                return self::buildStatus(
                    $installed,
                    $repository,
                    $cached,
                    true,
                    self::publicError($e) . ' · letzter bekannter Release-Stand wird angezeigt'
                );
            }

            return [
                'installed_version' => $installed,
                'repository' => $repository,
                'latest_version' => null,
                'update_available' => false,
                'checked_at' => self::getSetting($pdo, 'update_latest_checked_at'),
                'stale' => false,
                'error' => self::publicError($e),
                'release' => null,
            ];
        }
    }

    public static function repository(PDO $pdo): string
    {
        // Official TrakFog builds always trust the canonical repository.
        // Do not allow the normal UI/database to redirect update checks to a
        // third-party repository.
        return self::DEFAULT_REPOSITORY;
    }

    public static function hasGithubToken(PDO $pdo): bool
    {
        $encrypted = self::getSetting($pdo, 'update_github_token_enc');
        return is_string($encrypted) && trim($encrypted) !== '';
    }

    public static function saveGithubToken(PDO $pdo, array $config, ?string $token): void
    {
        $token = trim((string)$token);
        self::setSetting(
            $pdo,
            'update_github_token_enc',
            $token !== '' ? Crypto::encrypt($token, $config) : null
        );

        // A changed token may immediately make a previously inaccessible
        // private repository reachable, so do not keep a cached failure.
        self::setSetting($pdo, 'update_latest_checked_at', null);
        self::setSetting($pdo, 'update_last_check_status', null);
        self::setSetting($pdo, 'update_last_check_error', null);
    }

    public static function currentVersion(): string
    {
        $version = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));
        return $version !== '' ? $version : 'dev';
    }

    public static function runtimeState(PDO $pdo, array $config): array
    {
        $pending = self::decodeSettingJson($pdo, 'update_pending');
        $lastResult = self::decodeSettingJson($pdo, 'update_last_result');
        $updater = self::updaterStatus();
        $updaterLast = is_array($updater['last'] ?? null) ? $updater['last'] : null;

        $updaterState = (string)($updaterLast['state'] ?? '');
        $updaterVersion = self::normalizeVersion((string)($updaterLast['version'] ?? ''));
        $pendingVersion = self::normalizeVersion((string)($pending['target_version'] ?? ''));
        $updaterUpdatedAt = trim((string)($updaterLast['updated_at'] ?? ''));
        $updaterUpdatedTs = $updaterUpdatedAt !== '' ? strtotime($updaterUpdatedAt) : false;
        $stalled = is_array($pending)
            && is_array($updaterLast)
            && empty($updater['busy'])
            && in_array($updaterState, ['accepted','verifying','downloading','building'], true)
            && $updaterUpdatedTs !== false
            && (time() - $updaterUpdatedTs) > 1800;

        if (
            is_array($pending)
            && is_array($updaterLast)
            && $updaterVersion !== null
            && $updaterVersion === $pendingVersion
            && ($updaterState === 'error' || $stalled)
        ) {
            $message = $updaterState === 'error'
                ? trim((string)($updaterLast['message'] ?? 'Docker-Updater meldete einen Fehler.'))
                : 'Der Docker-Updater-Auftrag wurde nicht abgeschlossen und nach 30 Minuten freigegeben.';
            $failure = array_merge($pending, [
                'status' => 'failed',
                'failed_at' => gmdate('Y-m-d H:i:s'),
                'message' => $message,
            ]);
            self::setSetting(
                $pdo,
                'update_last_result',
                json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            self::setSetting($pdo, 'update_pending', null);
            $pending = null;
            $lastResult = $failure;
        }

        return [
            'pending' => $pending,
            'last_result' => $lastResult,
            'updater' => $updater,
        ];
    }

    public static function triggerUpdate(
        PDO $pdo,
        array $config,
        string $targetVersion,
        int $requestedBy
    ): array {
        $existingPending = self::decodeSettingJson($pdo, 'update_pending');
        if (is_array($existingPending) && !empty($existingPending['target_version'])) {
            throw new RuntimeException(
                'Es ist bereits ein Update auf V' . (string)$existingPending['target_version'] . ' vorgemerkt.'
            );
        }

        $freshStatus = self::status($pdo, $config, true);
        if (!empty($freshStatus['error']) || !empty($freshStatus['stale'])) {
            throw new RuntimeException('Vor der Installation konnte das Stable Release nicht frisch verifiziert werden.');
        }

        $latest = self::normalizeVersion((string)($freshStatus['latest_version'] ?? ''));
        $target = self::normalizeVersion($targetVersion);
        if ($latest === null || $target === null || $latest !== $target) {
            throw new RuntimeException('Die angeforderte Zielversion entspricht nicht dem aktuell veröffentlichten Stable Release.');
        }

        if (empty($freshStatus['update_available'])) {
            throw new RuntimeException('Für diese Installation ist aktuell kein neueres Stable Release verfügbar.');
        }

        $release = is_array($freshStatus['release'] ?? null) ? $freshStatus['release'] : [];
        $tag = trim((string)($release['tag'] ?? ''));
        if ($tag === '' || self::normalizeVersion($tag) !== $target) {
            throw new RuntimeException('Das verifizierte GitHub Release besitzt keinen passenden Release-Tag.');
        }

        $token = self::updaterToken();
        if ($token === null) {
            throw new RuntimeException(
                'Der integrierte Docker-Updater ist noch nicht initialisiert. Bitte den Stack einmal mit dem aktuellen Compose-Stand neu deployen.'
            );
        }

        $updater = self::updaterStatus();
        if (empty($updater['available'])) {
            $detail = trim((string)($updater['message'] ?? ''));
            throw new RuntimeException(
                'Der integrierte Docker-Updater ist nicht erreichbar'
                . ($detail !== '' ? ': ' . $detail : '.')
            );
        }

        $pending = [
            'from_version' => self::currentVersion(),
            'target_version' => $target,
            'release_tag' => $tag,
            'requested_at' => gmdate('Y-m-d H:i:s'),
            'requested_by' => $requestedBy,
            'method' => 'docker_updater',
        ];

        self::setSetting(
            $pdo,
            'update_pending',
            json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        self::setSetting(
            $pdo,
            'update_last_result',
            json_encode(
                array_merge($pending, ['status' => 'triggering']),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        try {
            $response = self::requestUpdater([
                'repository' => self::DEFAULT_REPOSITORY,
                'version' => $target,
                'tag' => $tag,
                'github_token' => self::githubToken($pdo, $config),
            ], $token);
        } catch (Throwable $e) {
            $failure = array_merge($pending, [
                'status' => 'failed',
                'failed_at' => gmdate('Y-m-d H:i:s'),
                'message' => $e->getMessage(),
            ]);
            self::setSetting(
                $pdo,
                'update_last_result',
                json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            self::setSetting($pdo, 'update_pending', null);
            throw $e;
        }

        $accepted = array_merge($pending, [
            'status' => 'accepted',
            'accepted_at' => gmdate('Y-m-d H:i:s'),
            'updater_status' => (string)($response['status'] ?? 'accepted'),
        ]);
        self::setSetting(
            $pdo,
            'update_last_result',
            json_encode($accepted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        AppLogger::log('info', 'TrakFog update accepted by Docker updater', [
            'from_version' => $pending['from_version'],
            'target_version' => $target,
            'requested_by' => $requestedBy,
        ]);

        return $accepted;
    }

    private static function updaterStatus(): array
    {
        $token = self::updaterToken();
        if ($token === null) {
            return [
                'available' => false,
                'ready' => false,
                'message' => 'Updater-Token fehlt.',
            ];
        }

        $ch = curl_init(self::updaterUrl('/status'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'X-TrakFog-Updater-Token: ' . $token,
                'Accept: application/json',
                'User-Agent: TrakFog-Updater-Status/' . self::currentVersion(),
            ],
        ]);

        $body = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $networkError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpStatus < 200 || $httpStatus >= 300) {
            return [
                'available' => false,
                'ready' => false,
                'message' => $body === false && $networkError !== ''
                    ? $networkError
                    : 'Updater antwortet nicht erfolgreich'
                        . ($httpStatus > 0 ? ' (HTTP ' . $httpStatus . ')' : '') . '.',
            ];
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return [
                'available' => false,
                'ready' => false,
                'message' => 'Updater lieferte keine gültige Statusantwort.',
            ];
        }

        $decoded['available'] = !empty($decoded['ok']) && !empty($decoded['ready']);
        return $decoded;
    }

    private static function requestUpdater(array $payload, string $token): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new RuntimeException('Update-Auftrag konnte nicht serialisiert werden.');
        }

        $ch = curl_init(self::updaterUrl('/update'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'X-TrakFog-Updater-Token: ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: TrakFog-Updater-Client/' . self::currentVersion(),
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $networkError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException(
                'Docker-Updater nicht erreichbar'
                . ($networkError !== '' ? ': ' . $networkError : '.')
            );
        }

        $decoded = json_decode((string)$responseBody, true);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = is_array($decoded) ? trim((string)($decoded['error'] ?? '')) : '';
            throw new RuntimeException(
                $message !== ''
                    ? $message
                    : 'Docker-Updater antwortete mit HTTP ' . $httpStatus . '.'
            );
        }

        return is_array($decoded) ? $decoded : ['status' => 'accepted'];
    }

    private static function updaterToken(): ?string
    {
        $file = trim((string)(getenv('TRAKFOG_UPDATER_TOKEN_FILE') ?: '/var/lib/trakfog/updater_token'));
        if ($file === '' || !is_readable($file)) {
            return null;
        }

        $token = trim((string)@file_get_contents($file));
        return $token !== '' ? $token : null;
    }

    private static function updaterUrl(string $path): string
    {
        $base = trim((string)(getenv('TRAKFOG_UPDATER_URL') ?: 'http://updater:8765'));
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private static function decodeSettingJson(PDO $pdo, string $key): ?array
    {
        $raw = self::getSetting($pdo, $key);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function fetchLatestRelease(PDO $pdo, array $config, string $repository): array
    {
        $url = 'https://api.github.com/repos/' . rawurlencode(explode('/', $repository, 2)[0])
            . '/' . rawurlencode(explode('/', $repository, 2)[1])
            . '/releases/latest';

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: TrakFog-Update-Checker/' . self::currentVersion(),
        ];

        $token = self::githubToken($pdo, $config);
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $networkError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException(
                'GitHub ist für die Update-Prüfung nicht erreichbar'
                . ($networkError !== '' ? ': ' . $networkError : '.')
            );
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $githubMessage = is_array($decoded) ? trim((string)($decoded['message'] ?? '')) : '';
            if ($status === 404 && $token === null) {
                throw new RuntimeException(
                    'GitHub Release nicht erreichbar. Bei einem privaten Repository muss ein GitHub-Token hinterlegt werden.'
                );
            }
            if ($status === 403) {
                throw new RuntimeException(
                    'GitHub hat die Update-Prüfung abgelehnt'
                    . ($githubMessage !== '' ? ': ' . $githubMessage : '.')
                );
            }
            throw new RuntimeException(
                'GitHub Release-Prüfung fehlgeschlagen (HTTP ' . $status . ')'
                . ($githubMessage !== '' ? ': ' . $githubMessage : '.')
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub hat keine gültigen Release-Daten geliefert.');
        }

        $tag = trim((string)($decoded['tag_name'] ?? ''));
        $version = self::normalizeVersion($tag);
        if ($version === null) {
            throw new RuntimeException('Das neueste GitHub Release besitzt keine gültige TrakFog-Version.');
        }

        $assets = [];
        foreach (($decoded['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = trim((string)($asset['name'] ?? ''));
            $download = trim((string)($asset['browser_download_url'] ?? ''));
            if ($name === '' || $download === '') {
                continue;
            }
            $assets[] = [
                'name' => $name,
                'download_url' => $download,
                'size' => isset($asset['size']) ? (int)$asset['size'] : null,
            ];
        }

        return [
            'version' => $version,
            'tag' => $tag,
            'name' => trim((string)($decoded['name'] ?? $tag)),
            'notes' => (string)($decoded['body'] ?? ''),
            'published_at' => trim((string)($decoded['published_at'] ?? '')),
            'html_url' => trim((string)($decoded['html_url'] ?? '')),
            'prerelease' => (bool)($decoded['prerelease'] ?? false),
            'draft' => (bool)($decoded['draft'] ?? false),
            'assets' => $assets,
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private static function buildStatus(
        string $installed,
        string $repository,
        array $release,
        bool $stale,
        ?string $error
    ): array {
        $latest = self::normalizeVersion((string)($release['version'] ?? $release['tag'] ?? ''));

        return [
            'installed_version' => $installed,
            'repository' => $repository,
            'latest_version' => $latest,
            'update_available' => $latest !== null
                && self::normalizeVersion($installed) !== null
                && version_compare($latest, $installed, '>'),
            'checked_at' => self::getReleaseCheckedAt($release),
            'stale' => $stale,
            'error' => $error,
            'release' => $release,
        ];
    }

    private static function getReleaseCheckedAt(array $release): ?string
    {
        $value = trim((string)($release['fetched_at'] ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function normalizeVersion(string $value): ?string
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'v')) {
            $value = substr($value, 1);
        }
        return preg_match('/^\d+(?:\.\d+){2,3}(?:[-+][0-9A-Za-z.-]+)?$/', $value)
            ? $value
            : null;
    }

    private static function githubToken(PDO $pdo, array $config): ?string
    {
        $encrypted = self::getSetting($pdo, 'update_github_token_enc');
        if (!is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        $token = Crypto::decrypt($encrypted, $config);
        $token = trim((string)$token);
        return $token !== '' ? $token : null;
    }

    private static function cachedRelease(PDO $pdo): ?array
    {
        $raw = self::getSetting($pdo, 'update_latest_release');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function publicError(Throwable $e): string
    {
        return $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Update-Prüfung fehlgeschlagen.';
    }

    private static function getSetting(PDO $pdo, string $key, mixed $default = null): mixed
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    private static function setSetting(PDO $pdo, string $key, mixed $value): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings(setting_key,setting_value)
             VALUES(?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute([$key, $value]);
    }
}
