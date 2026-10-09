<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$user = Auth::user($pdo);
$canManage = in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
$message = $error = $warning = null;

$setSetting = static function (PDO $pdo, string $key, mixed $value): void {
    $stmt = $pdo->prepare(
        "INSERT INTO settings(setting_key,setting_value)
         VALUES(?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
    );
    $stmt->execute([$key,$value]);
};

$redirectSystem = static function (string $query = '', string $anchor = ''): never {
    $url = 'system.php' . ($query !== '' ? '?' . $query : '') . ($anchor !== '' ? '#' . $anchor : '');
    header('Location: ' . $url);
    exit;
};

$integration = TeslaService::integration($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen. Bitte erneut versuchen.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'save_tesla') {
                TeslaService::saveTokens(
                    $pdo,
                    $config,
                    trim((string)($_POST['access_token'] ?? '')),
                    trim((string)($_POST['refresh_token'] ?? '')),
                    (int)Auth::id()
                );
                $test = TeslaService::testConnection($pdo, $config);
                $count = TeslaService::syncVehicles($pdo, $config);
                $redirectSystem('tesla=connected&count=' . $count . ($test['refreshed'] ? '&refreshed=1' : ''), 'tesla');
            }

            if ($action === 'test_tesla') {
                $test = TeslaService::testConnection($pdo, $config);
                $redirectSystem('tesla=tested&count=' . (int)$test['vehicle_count'], 'tesla');
            }

            if ($action === 'refresh_tesla') {
                TeslaService::refreshTokens($pdo, $config);
                $redirectSystem('tesla=refreshed', 'tesla');
            }

            if ($action === 'sync_tesla') {
                $count = TeslaService::syncVehicles($pdo, $config);
                $redirectSystem('tesla=synced&count=' . $count, 'tesla');
            }

            if ($action === 'disconnect_tesla') {
                if ($integration) {
                    $pdo->prepare(
                        "UPDATE integrations
                         SET status='disconnected',
                             access_token_enc=NULL,
                             refresh_token_enc=NULL,
                             token_expires_at=NULL,
                             last_error=NULL
                         WHERE id=?"
                    )->execute([$integration['id']]);
                }
                $redirectSystem('tesla=disconnected', 'tesla');
            }

            if ($action === 'worker_enable' || $action === 'worker_disable') {
                $setSetting($pdo, 'worker_enabled', $action === 'worker_enable' ? '1' : '0');
                $redirectSystem('worker=' . ($action === 'worker_enable' ? 'enabled' : 'disabled'), 'engine');
            }

            if ($action === 'save_liveview') {
                $mode = (string)($_POST['liveview_mode'] ?? 'disabled');
                $rememberDays = max(1, min(365, (int)($_POST['remember_days'] ?? 90)));
                $defaultVehicleId = max(0, (int)($_POST['default_vehicle_id'] ?? 0));
                $newPin = trim((string)($_POST['new_pin'] ?? ''));

                if (!in_array($mode, ['disabled','pin','open'], true)) {
                    $mode = 'disabled';
                }

                if ($defaultVehicleId > 0) {
                    $stmt = $pdo->prepare('SELECT id FROM vehicles WHERE id=? LIMIT 1');
                    $stmt->execute([$defaultVehicleId]);
                    if (!$stmt->fetchColumn()) {
                        throw new RuntimeException('Das gewählte Standardfahrzeug wurde nicht gefunden.');
                    }
                }

                $pinChanged = false;
                if ($newPin !== '') {
                    if (!preg_match('/^\d{6}$/', $newPin)) {
                        throw new RuntimeException('Die LiveView-PIN muss genau sechs Ziffern haben.');
                    }
                    $setSetting($pdo, 'liveview_pin_hash', password_hash($newPin, PASSWORD_DEFAULT));
                    $pinChanged = true;
                }

                if ($mode === 'pin' && !$pinChanged && !LiveViewAuth::pinConfigured($pdo)) {
                    throw new RuntimeException('Für den PIN-Modus zuerst eine sechsstellige PIN festlegen.');
                }

                $setSetting($pdo, 'liveview_mode', $mode);
                $setSetting($pdo, 'liveview_remember_days', (string)$rememberDays);
                $setSetting($pdo, 'liveview_default_vehicle_id', $defaultVehicleId > 0 ? (string)$defaultVehicleId : null);

                if ($pinChanged) {
                    LiveViewAuth::revokeAllDevices($pdo);
                }

                $redirectSystem('live=saved' . ($pinChanged ? '&pin=changed' : ''), 'liveview');
            }

            if ($action === 'revoke_liveview_device') {
                LiveViewAuth::revokeDevice($pdo, max(0, (int)($_POST['device_id'] ?? 0)));
                $redirectSystem('live=device_revoked', 'liveview');
            }

            if ($action === 'revoke_all_liveview_devices') {
                LiveViewAuth::revokeAllDevices($pdo);
                $redirectSystem('live=all_revoked', 'liveview');
            }

            if ($action === 'save_tesla_cost_sync') {
                $enabled = isset($_POST['tesla_cost_sync_enabled']) ? '1' : '0';
                $interval = max(15, min(360, (int)($_POST['tesla_cost_sync_interval'] ?? 90)));
                $setSetting($pdo, 'tesla_charging_cost_sync_enabled', $enabled);
                $setSetting($pdo, 'tesla_charging_cost_sync_interval_minutes', (string)$interval);
                $redirectSystem('costsync=saved', 'sync');
            }

            if ($action === 'trigger_tesla_cost_sync') {
                $setSetting($pdo, 'tesla_charging_cost_sync_force', '1');
                $redirectSystem('costsync=triggered', 'sync');
            }

            if ($action === 'create_integration_api_token') {
                $created = IntegrationApi::createToken(
                    $pdo,
                    trim((string)($_POST['api_token_name'] ?? 'VoltCore')),
                    (int)Auth::id(),
                    [IntegrationApi::SCOPE_VEHICLE_READ],
                    max(10, min(600, (int)($_POST['api_rate_limit'] ?? 120)))
                );
                $_SESSION['integration_api_token_once'] = [
                    'id' => (int)$created['id'],
                    'name' => (string)$created['name'],
                    'token' => (string)$created['token'],
                    'scope' => IntegrationApi::SCOPE_VEHICLE_READ,
                ];
                $redirectSystem('api=created', 'integration-api');
            }

            if ($action === 'revoke_integration_api_token') {
                IntegrationApi::revokeToken($pdo, max(0, (int)($_POST['api_token_id'] ?? 0)));
                $redirectSystem('api=revoked', 'integration-api');
            }

            if ($action === 'save_update_settings') {
                if (isset($_POST['clear_github_token'])) {
                    UpdateService::saveGithubToken($pdo, $config, null);
                } else {
                    $githubToken = trim((string)($_POST['github_token'] ?? ''));
                    if ($githubToken !== '') {
                        UpdateService::saveGithubToken($pdo, $config, $githubToken);
                    }
                }

                $redirectSystem('update=settings_saved', 'updates');
            }

            if ($action === 'check_updates') {
                $result = UpdateService::status($pdo, $config, true);
                $redirectSystem(
                    'update=' . (!empty($result['error']) ? 'check_error' : 'checked'),
                    'updates'
                );
            }

            if ($action === 'install_update') {
                $targetVersion = trim((string)($_POST['target_version'] ?? ''));
                $result = UpdateService::triggerUpdate(
                    $pdo,
                    $config,
                    $targetVersion,
                    (int)Auth::id()
                );

                if ((string)($_SERVER['HTTP_X_TRAKFOG_UPDATE'] ?? '') === '1') {
                    json_response([
                        'ok' => true,
                        'status' => (string)($result['status'] ?? 'accepted'),
                        'target_version' => $targetVersion,
                        'message' => 'Der integrierte Docker-Updater hat das Update angenommen.',
                    ]);
                }

                $redirectSystem('update=triggered&target=' . rawurlencode($targetVersion), 'updates');
            }
        } catch (Throwable $e) {
            $ref = AppLogger::exception($e, ['system_action' => $action]);
            $public = $e instanceof TeslaApiException || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'System-Aktion fehlgeschlagen.';

            if ($action === 'install_update' && (string)($_SERVER['HTTP_X_TRAKFOG_UPDATE'] ?? '') === '1') {
                json_response(['ok' => false, 'error' => $public . ' · Fehler-ID: ' . $ref], 400);
            }

            if ($e instanceof TeslaApiException && in_array($e->statusCode, [403,408,412,429], true)) {
                $warning = $public . ' · Die gespeicherte Tesla-Verbindung bleibt aktiv. · Fehler-ID: ' . $ref;
                $integrationForError = TeslaService::integration($pdo);
                $isDiscoveryIssue = str_contains($public, 'Fahrzeugliste') || str_contains(strtolower($public), 'products');
                if ($integrationForError && !empty($integrationForError['access_token_enc'])) {
                    $pdo->prepare(
                        "UPDATE integrations SET status='connected',last_error=? WHERE id=?"
                    )->execute([$isDiscoveryIssue ? null : $public, $integrationForError['id']]);
                }
            } else {
                $error = $public . ' · Fehler-ID: ' . $ref;
            }
        }
    }
}

$message = $message ?: match ((string)($_GET['tesla'] ?? '')) {
    'connected' => 'Tesla verbunden · ' . (int)($_GET['count'] ?? 0) . ' Fahrzeug(e) erkannt.',
    'tested' => 'Tesla-Verbindung erfolgreich getestet · ' . (int)($_GET['count'] ?? 0) . ' Fahrzeug(e) erreichbar.',
    'refreshed' => 'Access Token wurde über den Refresh Token erneuert.',
    'synced' => (int)($_GET['count'] ?? 0) . ' Fahrzeug(e) synchronisiert.',
    'disconnected' => 'Tesla-Verbindung getrennt. Die lokale Historie bleibt erhalten.',
    default => null,
};

$message = $message ?: match ((string)($_GET['worker'] ?? '')) {
    'enabled' => 'Datendienst aktiviert.',
    'disabled' => 'Datendienst pausiert.',
    default => null,
};

$message = $message ?: match ((string)($_GET['live'] ?? '')) {
    'saved' => isset($_GET['pin'])
        ? 'LiveView gespeichert. Neue PIN aktiv; bisherige Geräte wurden abgemeldet.'
        : 'LiveView-Einstellungen gespeichert.',
    'device_revoked' => 'LiveView-Zugang für das Gerät wurde entzogen.',
    'all_revoked' => 'Alle vertrauenswürdigen LiveView-Geräte wurden abgemeldet.',
    default => null,
};

$message = $message ?: match ((string)($_GET['costsync'] ?? '')) {
    'saved' => 'Supercharger-Kostensynchronisierung gespeichert.',
    'triggered' => 'Kosten-Synchronisierung für den nächsten Datendienst-Tick vorgemerkt.',
    default => null,
};

$message = $message ?: match ((string)($_GET['api'] ?? '')) {
    'created' => 'Integration-API-Zugang erstellt. Den Token jetzt einmalig kopieren.',
    'revoked' => 'Integration-API-Zugang wurde gesperrt.',
    default => null,
};

$message = $message ?: match ((string)($_GET['update'] ?? '')) {
    'settings_saved' => 'Update-Einstellungen gespeichert.',
    'checked' => 'GitHub Releases wurden neu geprüft.',
    'triggered' => 'Update wurde an den integrierten Docker-Updater übergeben.',
    'completed' => 'TrakFog ist nach dem Update wieder erreichbar.',
    default => null,
};

if ((string)($_GET['update'] ?? '') === 'check_error') {
    $warning = 'Update-Prüfung konnte nicht erfolgreich abgeschlossen werden. Details stehen im Update-Bereich.';
}

$apiTokens = IntegrationApi::tokens($pdo);
$apiStats = IntegrationApi::stats($pdo);
$apiTokenOnce = is_array($_SESSION['integration_api_token_once'] ?? null)
    ? $_SESSION['integration_api_token_once']
    : null;
unset($_SESSION['integration_api_token_once']);

$apiConfiguredBaseUrl = rtrim(trim((string)(getenv('TRAKFOG_BASE_URL') ?: '')), '/');
if ($apiConfiguredBaseUrl !== '') {
    $apiBaseUrl = $apiConfiguredBaseUrl;
} else {
    $apiForwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $apiScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $apiForwardedProto === 'https'
        ? 'https'
        : 'http';
    $apiHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $apiBaseUrl = $apiScheme . '://' . $apiHost;
}

$updateStatus = UpdateService::status($pdo, $config, false);
$updateRelease = is_array($updateStatus['release'] ?? null) ? $updateStatus['release'] : [];
$updateAvailable = (bool)($updateStatus['update_available'] ?? false);
$updateLatest = (string)($updateStatus['latest_version'] ?? '');
$updateInstalled = (string)($updateStatus['installed_version'] ?? app_version());
$updateGithubTokenConfigured = UpdateService::hasGithubToken($pdo);
$updateRuntime = UpdateService::runtimeState($pdo, $config);
$updatePending = is_array($updateRuntime['pending'] ?? null) ? $updateRuntime['pending'] : [];
$updateLastResult = is_array($updateRuntime['last_result'] ?? null) ? $updateRuntime['last_result'] : [];
$updateUpdater = is_array($updateRuntime['updater'] ?? null) ? $updateRuntime['updater'] : [];
$updateUpdaterAvailable = (bool)($updateUpdater['available'] ?? false);
$updateUpdaterBusy = (bool)($updateUpdater['busy'] ?? false);
$updateReleaseTag = trim((string)($updateRelease['tag'] ?? ''));

$integration = TeslaService::integration($pdo);
$connected = ($integration['status'] ?? '') === 'connected' && !empty($integration['access_token_enc']);
$expiresAt = $integration['token_expires_at'] ?? null;
$expiresTs = $expiresAt ? strtotime((string)$expiresAt . ' UTC') : false;
$tokenExpired = $expiresTs !== false && $expiresTs <= time();
$tokenSoon = $expiresTs !== false && !$tokenExpired && ($expiresTs - time()) < 86400;

$vehicles = $pdo->query(
    "SELECT
        v.id,v.display_name,v.vin,v.state,v.last_seen_at,v.battery_level,v.rated_range_km,
        s.status AS stream_status,
        s.last_event_at AS stream_last_event_at,
        (
            SELECT MAX(x.recorded_at)
            FROM vehicle_snapshots x
            WHERE x.vehicle_id=v.id
        ) AS snapshot_at
     FROM vehicles v
     LEFT JOIN vehicle_stream_status s ON s.vehicle_id=v.id
     ORDER BY v.display_name,v.id"
)->fetchAll();

$utcTimestamp = static function (mixed $value): ?int {
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    $ts = strtotime((string)$value . ' UTC');
    return $ts === false ? null : $ts;
};

$formatAge = static function (?int $seconds): string {
    if ($seconds === null) {
        return 'Zeit unbekannt';
    }
    if ($seconds < 60) {
        return 'gerade eben';
    }
    if ($seconds < 3600) {
        return 'vor ' . max(1, (int)floor($seconds / 60)) . ' Min.';
    }
    if ($seconds < 86400) {
        return 'vor ' . max(1, (int)floor($seconds / 3600)) . ' Std.';
    }
    return 'vor ' . max(1, (int)floor($seconds / 86400)) . ' Tg.';
};

foreach ($vehicles as &$vehicleRow) {
    $timestamps = array_values(array_filter([
        $utcTimestamp($vehicleRow['last_seen_at'] ?? null),
        $utcTimestamp($vehicleRow['stream_last_event_at'] ?? null),
        $utcTimestamp($vehicleRow['snapshot_at'] ?? null),
    ], static fn(mixed $value): bool => is_int($value)));

    $latestTs = $timestamps ? max($timestamps) : null;
    $dataAge = $latestTs !== null ? max(0, time() - $latestTs) : null;
    $streamStatus = strtolower((string)($vehicleRow['stream_status'] ?? ''));
    $storedState = strtolower((string)($vehicleRow['state'] ?? 'unknown'));

    if (in_array($streamStatus, ['offline','waiting_vehicle'], true) && ($dataAge === null || $dataAge > 120)) {
        $effectiveState = 'schläft / wartet';
    } elseif (in_array($storedState, ['asleep','offline'], true)) {
        $effectiveState = 'schläft';
    } elseif ($dataAge !== null && $dataAge <= 180) {
        $effectiveState = $storedState === 'online' ? 'online' : ($storedState ?: 'aktuell');
    } elseif ($dataAge !== null && $dataAge > 300) {
        $effectiveState = 'Daten veraltet';
    } else {
        $effectiveState = $storedState ?: 'unbekannt';
    }

    $vehicleRow['_effective_state'] = $effectiveState;
    $vehicleRow['_data_age'] = $dataAge;
    $vehicleRow['_data_age_label'] = $formatAge($dataAge);
}
unset($vehicleRow);

$productsIssueRaw = setting($pdo, 'tesla_products_last_error', null);
$productsIssue = [];
if (is_string($productsIssueRaw) && trim($productsIssueRaw) !== '') {
    $decoded = json_decode($productsIssueRaw, true);
    $productsIssue = is_array($decoded) ? $decoded : ['message' => $productsIssueRaw];
}
$productsRestricted = !empty($productsIssue);
$productsIssueMessage = trim((string)($productsIssue['message'] ?? ''));
$productsIssueAt = trim((string)($productsIssue['at'] ?? ''));
$forbiddenRefreshAt = setting($pdo, 'tesla_403_refresh_last_at', null);
$forbiddenRefreshResult = setting($pdo, 'tesla_403_refresh_last_result', null);

$apiDiagnosticRaw = setting($pdo, 'tesla_api_last_diagnostic', null);
$apiDiagnostic = [];
if (is_string($apiDiagnosticRaw) && trim($apiDiagnosticRaw) !== '') {
    $decoded = json_decode($apiDiagnosticRaw, true);
    $apiDiagnostic = is_array($decoded) ? $decoded : [];
}
$apiDiagnosticStatus = isset($apiDiagnostic['status']) && is_numeric($apiDiagnostic['status'])
    ? (int)$apiDiagnostic['status']
    : null;
$apiDiagnosticResolved = (bool)($apiDiagnostic['resolved'] ?? false);

$workerEnabled = setting($pdo, 'worker_enabled', '1') === '1';
$workerHeartbeat = setting($pdo, 'worker_last_heartbeat', null);
$workerState = strtolower((string)setting($pdo, 'worker_state', 'unbekannt'));
$workerLastError = setting($pdo, 'worker_last_error', null);
$workerLastResultRaw = setting($pdo, 'worker_last_result', null);
$workerLastResult = [];

if (is_string($workerLastResultRaw) && trim($workerLastResultRaw) !== '') {
    $decoded = json_decode($workerLastResultRaw, true);
    $workerLastResult = is_array($decoded) ? $decoded : [];
}

$workerAge = null;
if ($workerHeartbeat) {
    $ts = strtotime((string)$workerHeartbeat . ' UTC');
    if ($ts !== false) {
        $workerAge = max(0, time() - $ts);
    }
}
$workerProcessAlive = $workerEnabled && $workerAge !== null && $workerAge <= 120;
$workerStateBroken = in_array($workerState, ['error','stopped'], true);
$workerHealthy = $workerProcessAlive && !$workerStateBroken;
$workerNeedsAttention = $productsRestricted || in_array($workerState, ['degraded','stale'], true);

$workerStatusLabel = match ($workerState) {
    'online' => 'Läuft · Live',
    'sleeping' => 'Läuft · Tesla schläft',
    'stale' => 'Läuft · Daten warten',
    'degraded' => 'Eingeschränkt',
    'waiting_for_tesla' => 'Wartet auf Tesla',
    'starting' => 'Startet',
    'busy' => 'Beschäftigt',
    'paused' => 'Pausiert',
    'error' => 'Fehler',
    'stopped' => 'Gestoppt',
    default => $workerHealthy ? 'Läuft' : 'Prüfen',
};

$staleVehicleCount = count(array_filter(
    $vehicles,
    static fn(array $row): bool => ($row['_effective_state'] ?? '') === 'Daten veraltet'
));

$streamRows = [];
try {
    $streamRows = $pdo->query(
        "SELECT s.status,s.last_event_at,s.update_count,v.display_name
         FROM vehicle_stream_status s
         JOIN vehicles v ON v.id=s.vehicle_id
         ORDER BY v.display_name,v.id"
    )->fetchAll();
} catch (Throwable) {
    $streamRows = [];
}

$liveMode = LiveViewAuth::mode($pdo);
$livePinConfigured = LiveViewAuth::pinConfigured($pdo);
$rememberDays = max(1, min(365, (int)setting($pdo, 'liveview_remember_days', '90')));
$defaultVehicleId = max(0, (int)setting($pdo, 'liveview_default_vehicle_id', '0'));
$devices = $canManage ? LiveViewAuth::devices($pdo) : [];

$costSyncEnabled = setting($pdo, 'tesla_charging_cost_sync_enabled', '1') !== '0';
$costSyncInterval = max(15, min(360, (int)setting($pdo, 'tesla_charging_cost_sync_interval_minutes', '90')));
$costSyncLastAt = setting($pdo, 'tesla_charging_cost_sync_last_at', null);
$costSyncLastError = setting($pdo, 'tesla_charging_cost_sync_last_error', null);
$costSyncSummaryRaw = setting($pdo, 'tesla_charging_cost_sync_last_summary', null);
$costSyncSummary = [];

if (is_string($costSyncSummaryRaw) && trim($costSyncSummaryRaw) !== '') {
    $decoded = json_decode($costSyncSummaryRaw, true);
    $costSyncSummary = is_array($decoded) ? $decoded : [];
}

$fmtLocal = static function (?string $value, bool $seconds = false): string {
    if (!$value) {
        return '–';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i');
    } catch (Throwable) {
        return (string)$value;
    }
};

$checks = [];
$addCheck = static function (string $name, bool $ok, string $detail, string $group) use (&$checks): void {
    $checks[] = compact('name','ok','detail','group');
};

$addCheck('PHP', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, 'Runtime');
foreach (['pdo_mysql','curl','openssl','json'] as $ext) {
    $addCheck('Extension ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'geladen' : 'fehlt', 'Runtime');
}

try {
    $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $addCheck('MariaDB', true, $dbVersion, 'Datenbank');
} catch (Throwable) {
    $addCheck('MariaDB', false, 'nicht erreichbar', 'Datenbank');
}

$applied = Migrations::applied($pdo);
$migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
$pending = array_filter(
    $migrationFiles,
    static fn(string $file): bool => !in_array(basename($file, '.sql'), $applied, true)
);
$addCheck('Migrationen', count($pending) === 0, count($pending) ? count($pending) . ' offen' : 'aktuell', 'Datenbank');

$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$baseUrl = trim((string)(getenv('TRAKFOG_BASE_URL') ?: ''));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $forwardedProto === 'https'
    || str_starts_with(strtolower($baseUrl), 'https://');
$addCheck('HTTPS', $isHttps, $isHttps ? 'aktiv' : 'nicht erkannt', 'Sicherheit');

$logDir = dirname(__DIR__) . '/storage/logs';
$logWritable = is_dir($logDir) && is_writable($logDir);
$addCheck('Logs', $logWritable, $logWritable ? 'beschreibbar' : 'nicht beschreibbar', 'Runtime');

$addCheck(
    'Tesla Connector',
    $connected || !$integration,
    $integration ? (string)($integration['status'] ?? 'unbekannt') : 'nicht eingerichtet',
    'Tesla'
);
$addCheck(
    'Datendienst',
    !$workerEnabled || $workerHealthy,
    $workerEnabled
        ? ($workerHealthy ? $workerStatusLabel . ' · Heartbeat ' . $workerAge . ' s' : 'Heartbeat/Worker prüfen')
        : 'pausiert',
    'Tesla'
);
$addCheck(
    'Tesla Fahrzeugerkennung',
    !$productsRestricted,
    $productsRestricted
        ? 'products eingeschränkt · bekannte Fahrzeuge werden weiterverwendet'
        : 'products erreichbar',
    'Tesla'
);
$addCheck(
    'Tesla Datenfrische',
    $staleVehicleCount === 0,
    $staleVehicleCount ? $staleVehicleCount . ' Fahrzeug(e) mit veraltetem Status' : 'aktuell oder Schlafzustand erkannt',
    'Tesla'
);
$addCheck(
    'Charging History',
    !$costSyncLastError,
    $costSyncEnabled ? ($costSyncLastError ? 'letzter Lauf mit Fehler' : 'aktiv') : 'deaktiviert',
    'Tesla'
);

$diagGroups = [];
foreach ($checks as $check) {
    $diagGroups[$check['group']][] = $check;
}
$failedChecks = count(array_filter($checks, static fn(array $c): bool => !$c['ok']));

render_header('System', 'system');
?>
<div class="page wrap system-center">
  <div class="page-head">
    <div>
      <span class="kicker">System</span>
      <h1>System & Verbindung.</h1>
      <p>Alles Technische an einer Stelle: Tesla-Zugang, Datendienst, LiveView, Integration API, Kosten-Sync, Diagnose und Account.</p>
    </div>
    <div class="split-actions">
      <span class="pill <?= ($workerHealthy && !$workerNeedsAttention) ? 'ok' : ($workerEnabled ? 'warn' : '') ?>"><?= $workerHealthy ? ($workerNeedsAttention ? '● System läuft · Hinweis' : '● System läuft') : ($workerEnabled ? 'System prüfen' : 'Datendienst pausiert') ?></span>
      <span class="pill">V<?= e(app_version()) ?></span>
    </div>
  </div>

  <nav class="system-local-nav" aria-label="Systembereiche">
    <a href="#overview">Überblick</a>
    <a href="#tesla">Tesla</a>
    <a href="#engine">Datendienst</a>
    <a href="#liveview">LiveView</a>
    <a href="#sync">Kosten & Sync</a>
    <a href="#updates">Updates</a>
    <a href="#integration-api">Integration API</a>
    <a href="#diagnostics">Diagnose</a>
    <a href="migration.php">Datenmigration</a>
    <a href="#info">Info</a>
    <a href="#account">Account</a>
  </nav>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($warning): ?><div class="alert alert-warn">⚠️ <?= e($warning) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <section id="overview" class="system-section">
    <div class="system-section-head">
      <div><span class="kicker">Status</span><h2>Auf einen Blick.</h2></div>
    </div>
    <div class="system-status-grid">
      <a href="#tesla" class="system-status-card">
        <span>🚗 Tesla</span>
        <strong><?= $connected ? 'Verbunden' : ($integration ? e((string)($integration['status'] ?? 'Prüfen')) : 'Nicht eingerichtet') ?></strong>
        <small><?= $productsRestricted ? 'Erkennung eingeschränkt · ' . count($vehicles) . ' bekannt' : count($vehicles) . ' Fahrzeug(e)' ?></small>
      </a>
      <a href="#engine" class="system-status-card">
        <span>◉ Datendienst</span>
        <strong><?= $workerEnabled ? e($workerStatusLabel) : 'Pausiert' ?></strong>
        <small><?= $workerHeartbeat ? 'Heartbeat ' . e($fmtLocal((string)$workerHeartbeat, true)) : 'noch kein Heartbeat' ?></small>
      </a>
      <a href="#liveview" class="system-status-card">
        <span>📺 LiveView</span>
        <strong><?= $liveMode === 'pin' ? 'PIN geschützt' : ($liveMode === 'open' ? 'Offen' : 'Deaktiviert') ?></strong>
        <small><?= count($devices) ?> gespeicherte Geräte</small>
      </a>
      <a href="#diagnostics" class="system-status-card">
        <span>🩺 Diagnose</span>
        <strong><?= $failedChecks ? $failedChecks . ' Hinweis(e)' : 'Alles grün' ?></strong>
        <small><?= count($checks) ?> Checks</small>
      </a>
      <?php if ($canManage): ?>
        <a href="migration.php" class="system-status-card">
          <span>↔ Datenmigration</span>
          <strong>Import &amp; Export</strong>
          <small>TeslaMate + TrakFog aktiv</small>
        </a>
        <a href="#integration-api" class="system-status-card">
          <span>🔗 Integration API</span>
          <strong><?= (int)$apiStats['active_tokens'] ?> Zugang/Zugänge</strong>
          <small>API v1 · vehicle:read</small>
        </a>
      <?php endif; ?>
    </div>
  </section>

  <section id="tesla" class="panel system-section">
    <div class="panel-head">
      <div><h3>🚗 Tesla-Verbindung</h3><span class="small">Tokens, Fahrzeugerkennung und Synchronisation · HTTP/2 + TLS 1.3.</span></div>
      <span class="pill <?= $connected ? 'ok' : (($integration['status'] ?? '') === 'error' ? 'warn' : '') ?>"><?= $connected ? 'verbunden' : e((string)($integration['status'] ?? 'nicht verbunden')) ?></span>
    </div>
    <div class="panel-body">
      <?php if (!$integration || empty($integration['access_token_enc'])): ?>
        <?php if ($canManage): ?>
          <form class="form system-token-form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_tesla">
            <div class="field"><label>Access Token</label><input name="access_token" type="password" autocomplete="off" required placeholder="eyJ…"></div>
            <div class="field"><label>Refresh Token</label><input name="refresh_token" type="password" autocomplete="off" required placeholder="eyJ…"></div>
            <div class="split-actions">
              <button class="btn btn-primary" type="submit">Speichern, testen & Fahrzeuge erkennen</button>
              <a class="btn btn-ghost" href="https://github.com/adriankumpf/tesla_auth/releases/latest" target="_blank" rel="noopener noreferrer">Tesla Auth ↗</a>
            </div>
          </form>
        <?php else: ?>
          <div class="empty compact">Tesla-Verbindung ist noch nicht eingerichtet.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="system-kpi-row">
          <div><span>Verbindung</span><b><?= $connected ? '✓ verbunden' : e((string)($integration['status'] ?? '–')) ?></b></div>
          <div><span>Access Token</span><b><?= $tokenExpired ? 'abgelaufen' : ($tokenSoon ? 'bald fällig' : 'aktiv') ?></b></div>
          <div><span>Fahrzeuge</span><b><?= count($vehicles) ?></b></div>
          <div><span>Letzter Sync</span><b><?= e($fmtLocal($integration['last_sync_at'] ?? null)) ?></b></div>
        </div>

        <?php if ($canManage): ?>
          <div class="connector-actions">
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="test_tesla"><button class="btn btn-primary" type="submit">Verbindung testen</button></form>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="refresh_tesla"><button class="btn btn-ghost" type="submit">Token erneuern</button></form>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="sync_tesla"><button class="btn btn-ghost" type="submit">Fahrzeuge synchronisieren</button></form>
            <form method="post" onsubmit="return confirm('Tesla-Verbindung wirklich trennen?')"><?= Csrf::field() ?><input type="hidden" name="action" value="disconnect_tesla"><button class="btn btn-danger" type="submit">Verbindung trennen</button></form>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($vehicles): ?>
        <div class="system-vehicle-list">
          <?php foreach ($vehicles as $vehicle): ?>
            <a href="vehicle.php?id=<?= (int)$vehicle['id'] ?>">
              <span>🚘</span>
              <div>
                <strong><?= e((string)($vehicle['display_name'] ?: 'Tesla')) ?></strong>
                <small><?= e((string)($vehicle['_effective_state'] ?? 'unbekannt')) ?> · <?= $vehicle['battery_level'] !== null ? number_format((float)$vehicle['battery_level'],0).' %' : 'Akku –' ?> · <?= e((string)($vehicle['_data_age_label'] ?? '')) ?></small>
              </div>
              <b>Fahrzeug →</b>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
        $warningIsProducts = is_string($warning)
            && (str_contains($warning, 'Fahrzeugliste') || str_contains(strtolower($warning), 'products'));
      ?>
      <?php if ($productsRestricted && !$warningIsProducts): ?>
        <div class="alert alert-warn system-inline-alert">
          <strong>Fahrzeugerkennung eingeschränkt.</strong>
          Tesla lehnt <code>/products</code> aktuell ab<?= !empty($productsIssue['status']) ? ' (HTTP ' . (int)$productsIssue['status'] . ')' : '' ?>.
          Bekannte Fahrzeuge und der Driving Stream bleiben unabhängig davon nutzbar.
          <?php if ($productsIssueAt !== ''): ?> · Letzter Versuch <?= e($fmtLocal($productsIssueAt, true)) ?><?php endif; ?>
          <?php if ($forbiddenRefreshAt): ?> · Auto-Refresh <?= e($fmtLocal((string)$forbiddenRefreshAt, true)) ?><?= $forbiddenRefreshResult ? ' (' . e((string)$forbiddenRefreshResult) . ')' : '' ?><?php endif; ?>
        </div>
      <?php endif; ?>

      <?php
        $integrationNotice = trim((string)($integration['last_error'] ?? ''));
        $showIntegrationNotice = $integrationNotice !== ''
            && !str_contains($integrationNotice, 'Fahrzeugliste')
            && !str_contains(strtolower($integrationNotice), 'products')
            && (!is_string($warning) || !str_contains($warning, $integrationNotice));
      ?>
      <?php if ($showIntegrationNotice): ?>
        <div class="alert alert-warn system-inline-alert">Letzter Tesla-Hinweis: <?= e($integrationNotice) ?></div>
      <?php endif; ?>

      <?php if ($apiDiagnostic): ?>
        <details class="system-api-diagnostic">
          <summary>
            <span>🔬 Letzte Tesla-API-Diagnose</span>
            <span class="pill <?= $apiDiagnosticResolved ? 'ok' : ($apiDiagnosticStatus && $apiDiagnosticStatus >= 400 ? 'warn' : '') ?>">
              <?= $apiDiagnosticResolved ? 'behoben' : ($apiDiagnosticStatus ? 'HTTP ' . $apiDiagnosticStatus : 'Hinweis') ?>
            </span>
          </summary>
          <div class="system-api-diagnostic-body">
            <div class="data-list">
              <div class="data-row"><span>Bereich</span><b><?= e((string)($apiDiagnostic['area'] ?? '–')) ?></b></div>
              <div class="data-row"><span>Endpoint</span><b class="mono"><?= e((string)($apiDiagnostic['endpoint'] ?? '–')) ?></b></div>
              <div class="data-row"><span>HTTP</span><b><?= $apiDiagnosticStatus ? 'HTTP ' . $apiDiagnosticStatus : '–' ?><?= !empty($apiDiagnostic['http_version']) ? ' · ' . e((string)$apiDiagnostic['http_version']) : '' ?></b></div>
              <div class="data-row"><span>Tesla</span><b><?= e((string)($apiDiagnostic['tesla_error'] ?? '–')) ?></b></div>
              <div class="data-row"><span>Beschreibung</span><b><?= e((string)($apiDiagnostic['error_description'] ?? '–')) ?></b></div>
              <div class="data-row"><span>txid / Request-ID</span><b class="mono"><?= e((string)($apiDiagnostic['txid'] ?? '–')) ?></b></div>
              <div class="data-row"><span>Token gültig bis</span><b><?= e($fmtLocal(isset($apiDiagnostic['token_expires_at']) ? (string)$apiDiagnostic['token_expires_at'] : null, true)) ?></b></div>
              <div class="data-row"><span>Refresh</span><b><?= !empty($apiDiagnostic['refresh_attempted']) ? (!empty($apiDiagnostic['refresh_recovered']) ? 'versucht · erfolgreich' : 'versucht · ohne Erfolg') : 'nicht versucht' ?></b></div>
              <div class="data-row"><span>Retry</span><b><?= e((string)($apiDiagnostic['retry_result'] ?? '–')) ?></b></div>
              <div class="data-row"><span>Fallback</span><b><?= !empty($apiDiagnostic['fallback_active']) ? 'lokale bekannte Fahrzeuge aktiv' : 'nicht aktiv' ?></b></div>
              <div class="data-row"><span>Zeitpunkt</span><b><?= e($fmtLocal(isset($apiDiagnostic['at']) ? (string)$apiDiagnostic['at'] : null, true)) ?><?= !empty($apiDiagnostic['source']) ? ' · ' . e((string)$apiDiagnostic['source']) : '' ?></b></div>
            </div>
            <?php if (!empty($apiDiagnostic['message'])): ?>
              <div class="system-api-diagnostic-message"><?= e((string)$apiDiagnostic['message']) ?></div>
            <?php endif; ?>
            <small>Diagnose enthält keine Access-/Refresh-Tokens oder Authorization-Header.</small>
          </div>
        </details>
      <?php endif; ?>
    </div>
  </section>

  <section id="engine" class="panel system-section">
    <div class="panel-head">
      <div><h3>◉ Datendienst</h3><span class="small">Polling, Stream und automatische Erkennung.</span></div>
      <span class="pill <?= ($workerHealthy && !$workerNeedsAttention) ? 'ok' : ($workerEnabled ? 'warn' : '') ?>"><?= $workerEnabled ? e($workerStatusLabel) : 'pausiert' ?></span>
    </div>
    <div class="panel-body">
      <div class="system-kpi-row">
        <div><span>Status</span><b><?= e($workerState) ?></b></div>
        <div><span>Heartbeat</span><b><?= e($fmtLocal($workerHeartbeat, true)) ?></b></div>
        <div><span>Online Poll</span><b><?= e((string)(getenv('TRAKFOG_WORKER_POLL_ONLINE_SECONDS') ?: '30')) ?> s</b></div>
        <div><span>Sleep Poll</span><b><?= e((string)(getenv('TRAKFOG_WORKER_POLL_SLEEP_SECONDS') ?: '60')) ?> s</b></div>
      </div>

      <?php if ($canManage): ?>
        <div class="connector-actions">
          <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="<?= $workerEnabled ? 'worker_disable' : 'worker_enable' ?>">
            <button class="btn <?= $workerEnabled ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= $workerEnabled ? 'Datendienst pausieren' : 'Datendienst starten' ?></button>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($workerLastError): ?><div class="alert alert-warn system-inline-alert"><?= e((string)$workerLastError) ?></div><?php endif; ?>

      <div class="system-two-column">
        <div>
          <h4>Letzter Poll</h4>
          <div class="data-list">
            <div class="data-row"><span>Fahrzeuge</span><b><?= (int)($workerLastResult['vehicles'] ?? 0) ?></b></div>
            <div class="data-row"><span>Online</span><b><?= (int)($workerLastResult['online'] ?? 0) ?></b></div>
            <div class="data-row"><span>Snapshots</span><b><?= (int)($workerLastResult['snapshots'] ?? 0) ?></b></div>
            <div class="data-row"><span>Fehler</span><b><?= count($workerLastResult['errors'] ?? []) ?></b></div>
            <div class="data-row"><span>Fahrzeugquelle</span><b><?= e((string)(($workerLastResult['products_source'] ?? '') === 'known_vehicles_fallback' ? 'lokaler Fallback' : 'Tesla /products')) ?></b></div>
          </div>
        </div>
        <div>
          <h4>Driving Stream</h4>
          <?php if (!$streamRows): ?>
            <div class="empty compact">Noch kein Streamstatus.</div>
          <?php else: ?>
            <div class="data-list">
              <?php foreach ($streamRows as $stream): ?>
                <div class="data-row">
                  <span><?= e((string)$stream['display_name']) ?></span>
                  <b><?= e((string)($stream['status'] ?? 'unbekannt')) ?> · <?= (int)($stream['update_count'] ?? 0) ?> Pakete</b>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section id="liveview" class="panel system-section">
    <div class="panel-head">
      <div><h3>📺 LiveView-Zugang</h3><span class="small">Zugang und vertrauenswürdige Geräte – nicht das LiveView-Design selbst.</span></div>
      <span class="pill <?= $liveMode === 'pin' ? 'ok' : ($liveMode === 'open' ? 'warn' : '') ?>"><?= $liveMode === 'pin' ? 'PIN geschützt' : ($liveMode === 'open' ? 'offen' : 'deaktiviert') ?></span>
    </div>
    <div class="panel-body">
      <?php if ($canManage): ?>
        <form method="post" class="settings-liveview-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save_liveview">

          <div class="settings-liveview-modes">
            <?php foreach ([
              'pin'=>['🔐','PIN geschützt','Empfohlen'],
              'open'=>['🌐','Offen','Nur in bewusst geschützten Netzen'],
              'disabled'=>['○','Deaktiviert','Nur Admin-Vorschau']
            ] as $modeKey=>$meta): ?>
              <label class="<?= $liveMode === $modeKey ? 'selected' : '' ?>">
                <input type="radio" name="liveview_mode" value="<?= e($modeKey) ?>" <?= $liveMode === $modeKey ? 'checked' : '' ?>>
                <span><?= $meta[0] ?></span>
                <div><strong><?= e($meta[1]) ?></strong><small><?= e($meta[2]) ?></small></div>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="settings-liveview-grid">
            <label>
              <span>Neue PIN</span>
              <input name="new_pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" placeholder="<?= $livePinConfigured ? '•••••• · leer = unverändert' : '6 Ziffern' ?>">
              <small><?= $livePinConfigured ? 'PIN eingerichtet' : 'noch keine PIN' ?></small>
            </label>
            <label>
              <span>Gerät merken</span>
              <select name="remember_days">
                <?php foreach ([7,30,60,90,180,365] as $days): ?>
                  <option value="<?= $days ?>" <?= $rememberDays === $days ? 'selected' : '' ?>><?= $days ?> Tage</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span>Standardfahrzeug</span>
              <select name="default_vehicle_id">
                <option value="0">automatisch</option>
                <?php foreach ($vehicles as $vehicle): ?>
                  <option value="<?= (int)$vehicle['id'] ?>" <?= $defaultVehicleId === (int)$vehicle['id'] ? 'selected' : '' ?>><?= e((string)($vehicle['display_name'] ?: 'Tesla')) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <div class="split-actions">
            <button class="btn btn-primary" type="submit">LiveView-Zugang speichern</button>
            <a class="btn btn-ghost" href="live.php" target="_blank" rel="noopener">LiveView öffnen ↗</a>
          </div>
        </form>

        <div class="settings-liveview-devices">
          <div class="settings-subhead">
            <div><strong>Vertrauenswürdige Geräte</strong><small>Aktive Browser-Tokens</small></div>
            <?php if ($devices): ?>
              <form method="post" onsubmit="return confirm('Alle LiveView-Geräte wirklich abmelden?')">
                <?= Csrf::field() ?><input type="hidden" name="action" value="revoke_all_liveview_devices">
                <button class="btn btn-ghost" type="submit">Alle abmelden</button>
              </form>
            <?php endif; ?>
          </div>

          <?php if (!$devices): ?>
            <div class="empty compact">Noch kein Gerät gespeichert.</div>
          <?php else: ?>
            <div class="liveview-device-list">
              <?php foreach ($devices as $device): ?>
                <?php $activeDevice = empty($device['revoked_at']) && strtotime((string)$device['expires_at'].' UTC') > time(); ?>
                <div class="liveview-device-row <?= $activeDevice ? '' : 'inactive' ?>">
                  <span class="liveview-device-icon">📱</span>
                  <div><strong><?= e((string)$device['label']) ?></strong><small><?= $activeDevice ? 'aktiv' : 'inaktiv' ?> · zuletzt <?= e($fmtLocal($device['last_used_at'] ?? null)) ?></small></div>
                  <?php if ($activeDevice): ?>
                    <form method="post">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="revoke_liveview_device"><input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
                      <button class="btn btn-ghost" type="submit">Entziehen</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="empty compact">Nur Owner/Admin können den LiveView-Zugang ändern.</div>
      <?php endif; ?>
    </div>
  </section>

  <section id="sync" class="panel system-section">
    <div class="panel-head">
      <div><h3>⚡ Kosten & Synchronisation</h3><span class="small">Supercharger-Kosten aus der Tesla Charging History.</span></div>
      <span class="pill <?= $costSyncEnabled ? 'ok' : '' ?>"><?= $costSyncEnabled ? 'aktiv' : 'deaktiviert' ?></span>
    </div>
    <div class="panel-body">
      <div class="system-kpi-row">
        <div><span>Letzter Lauf</span><b><?= e($fmtLocal($costSyncLastAt)) ?></b></div>
        <div><span>Sessions</span><b><?= isset($costSyncSummary['sessions']) ? (int)$costSyncSummary['sessions'] : '–' ?></b></div>
        <div><span>Übernommen</span><b><?= isset($costSyncSummary['updated']) ? (int)$costSyncSummary['updated'] : '–' ?></b></div>
        <div><span>Nicht zugeordnet</span><b><?= isset($costSyncSummary['no_match']) ? (int)$costSyncSummary['no_match'] : '–' ?></b></div>
      </div>

      <?php if ($canManage): ?>
        <form method="post" class="system-sync-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save_tesla_cost_sync">
          <label class="settings-costsync-toggle">
            <input type="checkbox" name="tesla_cost_sync_enabled" value="1" <?= $costSyncEnabled ? 'checked' : '' ?>>
            <span><strong>Charging History automatisch synchronisieren</strong><small>Kein Fahrzeug-Wakeup; manuell bestätigte Kosten bleiben geschützt.</small></span>
          </label>
          <label class="settings-costsync-interval">
            <span>Prüfintervall</span>
            <select name="tesla_cost_sync_interval">
              <?php foreach ([30,60,90,180,360] as $minutes): ?>
                <option value="<?= $minutes ?>" <?= $costSyncInterval === $minutes ? 'selected' : '' ?>><?= $minutes ?> Minuten</option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="split-actions"><button class="btn btn-primary" type="submit">Speichern</button></div>
        </form>

        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="action" value="trigger_tesla_cost_sync">
          <button class="btn btn-ghost" type="submit">Jetzt synchronisieren</button>
        </form>
      <?php endif; ?>

      <?php if ($costSyncLastError): ?><div class="alert alert-warn system-inline-alert"><?= e((string)$costSyncLastError) ?></div><?php endif; ?>
    </div>
  </section>

  <section id="updates" class="panel system-section">
    <div class="panel-head">
      <div>
        <h3>⬆ Updates</h3>
        <span class="small">Stabile GitHub Releases prüfen und TrakFog sicher auf dem aktuellen Stand halten.</span>
      </div>
      <span class="pill <?= $updatePending ? 'warn' : ($updateAvailable ? 'warn' : (!empty($updateStatus['error']) ? 'warn' : 'ok')) ?>">
        <?= $updatePending ? 'Update läuft' : ($updateAvailable ? 'Update verfügbar' : (!empty($updateStatus['error']) ? 'Prüfung eingeschränkt' : 'Aktuell')) ?>
      </span>
    </div>
    <div class="panel-body">
      <div class="system-kpi-row">
        <div><span>Installiert</span><b>V<?= e($updateInstalled) ?></b></div>
        <div><span>Verfügbar</span><b><?= $updateLatest !== '' ? 'V' . e($updateLatest) : '–' ?></b></div>
        <div><span>Kanal</span><b>Stable Releases</b></div>
        <div><span>Letzte Prüfung</span><b><?= e($fmtLocal(isset($updateStatus['checked_at']) ? (string)$updateStatus['checked_at'] : null, true)) ?></b></div>
      </div>

      <?php if ($updatePending): ?>
        <div class="alert alert-warn system-inline-alert">
          ⏳ Update von V<?= e((string)($updatePending['from_version'] ?? $updateInstalled)) ?>
          auf V<?= e((string)($updatePending['target_version'] ?? '–')) ?> wurde ausgelöst.
          Der interne Docker-Updater ersetzt Web und Datendienst; nach erfolgreichem Containerstart bestätigt TrakFog das Update automatisch.
        </div>
      <?php elseif (($updateLastResult['status'] ?? '') === 'success' && !empty($updateLastResult['completed_at'])): ?>
        <div class="alert alert-ok system-inline-alert">
          ✅ Letztes Update auf V<?= e((string)($updateLastResult['installed_version'] ?? $updateInstalled)) ?>
          erfolgreich · <?= e($fmtLocal((string)$updateLastResult['completed_at'], true)) ?>
        </div>
      <?php elseif (($updateLastResult['status'] ?? '') === 'failed'): ?>
        <div class="alert alert-error system-inline-alert">
          ❌ Letztes Update fehlgeschlagen
          <?php if (!empty($updateLastResult['target_version'])): ?> · Ziel V<?= e((string)$updateLastResult['target_version']) ?><?php endif; ?>
          <?php if (!empty($updateLastResult['message'])): ?> · <?= e((string)$updateLastResult['message']) ?><?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($updateStatus['error'])): ?>
        <div class="alert alert-warn system-inline-alert">
          <?= e((string)$updateStatus['error']) ?>
          <?php if (!empty($updateStatus['stale'])): ?> · Der letzte bekannte Release-Stand bleibt sichtbar.<?php endif; ?>
        </div>
      <?php elseif ($updateAvailable): ?>
        <div class="update-ready">
          <div>
            <span class="kicker">Neue Version</span>
            <h4>TrakFog V<?= e($updateLatest) ?> ist verfügbar.</h4>
            <p>Release gefunden. Installiert wird ausschließlich der frisch verifizierte GitHub-Release-Tag <code><?= e($updateReleaseTag !== '' ? $updateReleaseTag : ('v' . $updateLatest)) ?></code>.</p>
          </div>
          <?php if ($canManage): ?>
            <button class="btn btn-primary" type="button" id="openUpdateModal">Update vorbereiten →</button>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="alert alert-ok system-inline-alert">✅ TrakFog ist auf dem neuesten bekannten Stable Release.</div>
      <?php endif; ?>

      <div class="connector-actions">
        <?php if ($canManage): ?>
          <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="check_updates">
            <button class="btn btn-ghost" type="submit">Jetzt nach Updates suchen</button>
          </form>
        <?php endif; ?>
        <?php if (!empty($updateRelease['html_url'])): ?>
          <a class="btn btn-ghost" href="<?= e((string)$updateRelease['html_url']) ?>" target="_blank" rel="noopener noreferrer">GitHub Release ↗</a>
        <?php endif; ?>
      </div>

      <?php if (!empty($updateRelease['notes'])): ?>
        <details class="update-release-notes">
          <summary>Release Notes V<?= e($updateLatest) ?></summary>
          <div><?= nl2br(e((string)$updateRelease['notes'])) ?></div>
        </details>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <details class="update-settings">
          <summary>Update-Zugang</summary>
          <form method="post" class="form update-settings-form" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_update_settings">
            <div class="data-row update-source-fixed">
              <span>Offizielle Updatequelle</span>
              <b class="mono">hotteftw1981/TrakFog-Public 🔒</b>
            </div>
            <span class="hint">Die offizielle TrakFog-Updatequelle ist fest hinterlegt und kann nicht auf ein fremdes Repository umgestellt werden.</span>

            <div class="field">
              <label>GitHub Token <?= $updateGithubTokenConfigured ? '· gespeichert 🔒' : '· optional' ?></label>
              <input name="github_token" type="password" autocomplete="new-password" placeholder="<?= $updateGithubTokenConfigured ? 'leer lassen = bestehenden Token behalten' : 'nur für private Repositories erforderlich' ?>">
              <span class="hint">Wird verschlüsselt mit dem lokalen TrakFog App-Key gespeichert. Für ein öffentliches Repository ist kein Token erforderlich.</span>
            </div>
            <?php if ($updateGithubTokenConfigured): ?>
              <label class="check-chip"><input type="checkbox" name="clear_github_token" value="1"><span>Gespeicherten GitHub Token entfernen</span></label>
            <?php endif; ?>

            <div class="update-webhook-state">
              <span class="pill <?= $updateUpdaterAvailable ? 'ok' : 'warn' ?>">
                <?= $updateUpdaterAvailable ? ($updateUpdaterBusy ? 'Docker-Updater beschäftigt' : 'Docker-Updater bereit') : 'Docker-Updater nicht erreichbar' ?>
              </span>
              <span>Ein-Klick-Updates laufen direkt über Docker Compose. Portainer, GitOps und Webhooks sind dafür nicht erforderlich.</span>
            </div>

            <div class="split-actions"><button class="btn btn-ghost" type="submit">Update-Einstellungen speichern</button></div>
          </form>
        </details>
      <?php endif; ?>
    </div>
  </section>

  <section id="integration-api" class="panel system-section">
    <div class="panel-head">
      <div><h3>🔗 Integration API</h3><span class="small">Sichere Fahrzeugdaten-Schnittstelle für VoltCore und weitere optionale Systeme.</span></div>
      <span class="pill ok">API v<?= IntegrationApi::API_VERSION ?></span>
    </div>
    <div class="panel-body">
      <?php if ($apiTokenOnce): ?>
        <div class="alert alert-ok api-token-once">
          <strong>API-Token für „<?= e((string)$apiTokenOnce['name']) ?>“</strong>
          <p>Dieser Token wird nur jetzt vollständig angezeigt. Bitte direkt in das verbundene System übernehmen.</p>
          <code><?= e((string)$apiTokenOnce['token']) ?></code>
        </div>
      <?php endif; ?>

      <div class="system-kpi-row">
        <div><span>Aktive Zugänge</span><b><?= (int)$apiStats['active_tokens'] ?></b></div>
        <div><span>Requests 24 h</span><b><?= (int)$apiStats['requests_24h'] ?></b></div>
        <div><span>Fehler 24 h</span><b><?= (int)$apiStats['errors_24h'] ?></b></div>
        <div><span>Aktiver Scope</span><b class="mono">vehicle:read</b></div>
      </div>

      <div class="data-list system-api-endpoints">
        <div class="data-row"><span>Fahrzeuge</span><code><?= e($apiBaseUrl) ?>/api/v1/vehicles</code></div>
        <div class="data-row"><span>Fahrzeugstatus</span><code><?= e($apiBaseUrl) ?>/api/v1/vehicles/{id}/status</code></div>
        <div class="data-row"><span>Authentifizierung</span><code>Authorization: Bearer tfk_…</code></div>
        <div class="data-row"><span>Datenfrische</span><b>fresh · stale · offline · unknown</b></div>
      </div>

      <div class="alert alert-info system-inline-alert">
        TrakFog gibt über API v1 keine Tesla Access-/Refresh-Tokens und keine Passwörter aus. VoltCore erhält nur die freigegebenen Fahrzeugdaten und kann SoC sowie Ziel-SoC anhand der Datenfrische sicher darstellen.
      </div>

      <?php if ($canManage): ?>
        <form method="post" class="form system-api-create-form" autocomplete="off">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create_integration_api_token">
          <div class="grid-2">
            <div class="field">
              <label>Name des Zugangs</label>
              <input name="api_token_name" maxlength="120" value="VoltCore" required>
              <span class="hint">Zum Beispiel VoltCore, Testsystem oder eine andere vertrauenswürdige Integration.</span>
            </div>
            <div class="field">
              <label>Rate-Limit / Minute</label>
              <input name="api_rate_limit" type="number" min="10" max="600" value="120" required>
              <span class="hint">Für SoC-Abfragen reichen 120 Requests pro Minute normalerweise deutlich aus.</span>
            </div>
          </div>
          <div class="data-row">
            <span>Scope</span>
            <b class="mono">vehicle:read</b>
          </div>
          <div class="split-actions"><button class="btn btn-primary" type="submit">API-Zugang erstellen</button></div>
        </form>
      <?php endif; ?>

      <div class="system-api-token-list">
        <div class="panel-head system-subhead">
          <div><h4>API-Zugänge</h4><span class="small">Tokens werden gehasht gespeichert; vollständig sichtbar sind sie nur direkt nach der Erstellung.</span></div>
        </div>
        <?php if (!$apiTokens): ?>
          <div class="empty compact">Noch kein Integration-API-Zugang vorhanden.</div>
        <?php else: ?>
          <div class="data-list">
            <?php foreach ($apiTokens as $apiToken): ?>
              <?php $apiRevoked = !empty($apiToken['revoked_at']); ?>
              <div class="data-row api-token-row">
                <span>
                  <strong><?= e((string)$apiToken['name']) ?></strong><br>
                  <small class="mono"><?= e((string)$apiToken['token_prefix']) ?>… · <?= e((string)$apiToken['scopes']) ?></small>
                </span>
                <span class="api-token-meta">
                  <small><?= $apiToken['last_used_at'] ? 'zuletzt ' . e($fmtLocal((string)$apiToken['last_used_at'], true)) : 'noch nie verwendet' ?></small>
                  <span class="pill <?= $apiRevoked ? '' : 'ok' ?>"><?= $apiRevoked ? 'gesperrt' : 'aktiv' ?></span>
                  <?php if (!$apiRevoked && $canManage): ?>
                    <form method="post">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="revoke_integration_api_token">
                      <input type="hidden" name="api_token_id" value="<?= (int)$apiToken['id'] ?>">
                      <button class="btn btn-danger btn-small" type="submit">Zugang sperren</button>
                    </form>
                  <?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="diagnostics" class="system-section">
    <div class="system-section-head">
      <div><span class="kicker">Diagnose</span><h2>Technischer Zustand.</h2></div>
      <span class="pill <?= $failedChecks ? 'warn' : 'ok' ?>"><?= $failedChecks ? $failedChecks . ' Hinweis(e)' : 'alles grün' ?></span>
    </div>

    <div class="grid-2">
      <?php foreach ($diagGroups as $group=>$items): ?>
        <section class="panel">
          <div class="panel-head"><h3><?= e((string)$group) ?></h3><span class="pill"><?= count($items) ?> Checks</span></div>
          <div class="panel-body">
            <div class="data-list">
              <?php foreach ($items as $item): ?>
                <div class="data-row">
                  <span><?= e((string)$item['name']) ?></span>
                  <span class="split-actions">
                    <span class="small"><?= e((string)$item['detail']) ?></span>
                    <span class="pill <?= $item['ok'] ? 'ok' : 'warn' ?>"><?= $item['ok'] ? '✓' : '!' ?></span>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="info" class="panel system-section">
    <div class="panel-head">
      <div><h3>ⓘ Info & Credits</h3><span class="small">Version, Community-Edition, Lizenz und Projekt-Credits.</span></div>
      <span class="pill">Community Edition</span>
    </div>
    <div class="panel-body">
      <div class="trakfog-system-info">
        <?php render_trakfog_info_content('System'); ?>
      </div>
    </div>
  </section>

  <section id="account" class="panel system-section">
    <div class="panel-head"><div><h3>👤 Account & Oberfläche</h3><span class="small">Benutzerkontext und lokale Darstellung.</span></div></div>
    <div class="panel-body system-two-column">
      <div class="data-list">
        <div class="data-row"><span>Benutzer</span><b><?= e((string)$user['username']) ?></b></div>
        <div class="data-row"><span>E-Mail</span><b><?= e((string)$user['email']) ?></b></div>
        <div class="data-row"><span>Rolle</span><b><?= e((string)$user['role']) ?></b></div>
        <div class="data-row"><span>Version</span><b>V<?= e(app_version()) ?></b></div>
      </div>
      <div class="system-account-actions">
        <button type="button" class="btn btn-ghost" id="systemThemeToggle">◐ Darstellung wechseln</button>
        <a class="btn btn-ghost" href="logout.php">Abmelden</a>
      </div>
    </div>
  </section>
</div>
<script>
document.querySelectorAll('.settings-liveview-modes label').forEach(label=>{
  label.addEventListener('click',()=>{
    document.querySelectorAll('.settings-liveview-modes label').forEach(item=>item.classList.remove('selected'));
    label.classList.add('selected');
  });
});
document.getElementById('systemThemeToggle')?.addEventListener('click',()=>{
  document.getElementById('themeToggle')?.click();
});
</script>
<?php if ($canManage): ?>
<div class="tf-modal" id="updateModal" hidden>
  <div class="tf-modal-backdrop" aria-hidden="true"></div>
  <section class="tf-modal-card update-modal-card" role="dialog" aria-modal="true" aria-labelledby="updateModalTitle">
    <div class="tf-modal-head">
      <div><span class="kicker">TrakFog Update</span><h2 id="updateModalTitle">V<?= e($updateInstalled) ?> → V<?= e($updateLatest) ?></h2></div>
      <button class="tf-modal-close" type="button" data-update-modal-close aria-label="Schließen">×</button>
    </div>
    <div class="tf-modal-body">
      <div class="update-modal-intro">
        <strong>Stable Update V<?= e($updateLatest) ?></strong>
        <p>TrakFog prüft das Release unmittelbar vor der Installation erneut. Der interne <code>trakfog-updater</code> lädt exakt den veröffentlichten GitHub-Release-Tag und baut Web und Datendienst neu. Portainer ist dafür nicht erforderlich. Datenbankvolume und Konfiguration bleiben erhalten; Migrationen laufen beim Neustart automatisch.</p>
      </div>

      <div class="data-list update-preflight">
        <div class="data-row"><span>Aktuell</span><b>V<?= e($updateInstalled) ?></b></div>
        <div class="data-row"><span>Ziel</span><b>V<?= e($updateLatest) ?></b></div>
        <div class="data-row"><span>Docker-Updater</span><b><?= $updateUpdaterAvailable ? ($updateUpdaterBusy ? 'beschäftigt' : 'bereit') : 'nicht erreichbar' ?></b></div>
        <div class="data-row"><span>Release</span><b class="mono"><?= e($updateReleaseTag !== '' ? $updateReleaseTag : ('v' . $updateLatest)) ?></b></div>
      </div>

      <?php if ($updateUpdaterAvailable && !$updateUpdaterBusy && $updateAvailable): ?>
        <form method="post" id="updateInstallForm" class="update-install-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="install_update">
          <input type="hidden" name="target_version" value="<?= e($updateLatest) ?>">
          <div class="update-progress" id="updateProgress" hidden>
            <span class="update-progress-spinner" aria-hidden="true"></span>
            <div><strong id="updateProgressTitle">Update wird ausgelöst …</strong><small id="updateProgressText">Bitte dieses Fenster geöffnet lassen.</small></div>
          </div>
          <div class="split-actions" id="updateModalActions">
            <button class="btn btn-primary" type="submit">V<?= e($updateLatest) ?> jetzt installieren</button>
            <button class="btn btn-ghost" type="button" data-update-modal-close>Abbrechen</button>
          </div>
        </form>
      <?php else: ?>
        <div class="alert alert-warn">
          <?php if (!$updateUpdaterAvailable): ?>
            Der integrierte Docker-Updater ist noch nicht erreichbar. Bei einer bestehenden Installation muss der Stack einmal mit dem aktuellen Compose-Stand neu deployt werden. Danach funktionieren Ein-Klick-Updates unabhängig von Portainer.
          <?php elseif ($updateUpdaterBusy): ?>
            Der Docker-Updater verarbeitet bereits einen Auftrag.
          <?php else: ?>
            Aktuell steht kein neueres Stable Release zur Installation bereit.
          <?php endif; ?>
        </div>
        <div class="split-actions"><button class="btn btn-ghost" type="button" data-update-modal-close>Schließen</button></div>
      <?php endif; ?>

      <details class="update-manual-fallback">
        <summary>Manuell aktualisieren</summary>
        <pre>git fetch origin stable
git switch stable
git pull --ff-only origin stable
docker compose up -d --build</pre>
      </details>
    </div>
  </section>
</div>
<script>
(() => {
  const modal=document.getElementById('updateModal');
  const open=() => {
    if (!modal) return;
    modal.hidden=false;
    document.body.classList.add('modal-open');
    modal.querySelector('[data-update-modal-close]')?.focus();
  };
  const close=() => {
    if (!modal) return;
    modal.hidden=true;
    document.body.classList.remove('modal-open');
  };
  document.getElementById('openUpdateModal')?.addEventListener('click',open);
  document.querySelectorAll('[data-update-modal-close]').forEach(el => el.addEventListener('click',close));
  window.addEventListener('keydown',event => {
    if (event.key==='Escape' && modal && !modal.hidden) close();
  });

  const form=document.getElementById('updateInstallForm');
  const progress=document.getElementById('updateProgress');
  const progressTitle=document.getElementById('updateProgressTitle');
  const progressText=document.getElementById('updateProgressText');
  const actions=document.getElementById('updateModalActions');
  const target=form?.querySelector('input[name="target_version"]')?.value || '';

  const waitForVersion=async() => {
    const deadline=Date.now()+5*60*1000;
    let sawRestart=false;
    while(Date.now()<deadline){
      await new Promise(resolve=>setTimeout(resolve,3000));
      try{
        const response=await fetch('health.php?_update='+Date.now(),{cache:'no-store'});
        const data=await response.json();
        if(data?.ok && String(data.version||'')===target){
          if(progressTitle) progressTitle.textContent='Update erfolgreich';
          if(progressText) progressText.textContent='TrakFog V'+target+' ist wieder erreichbar.';
          window.setTimeout(()=>window.location.assign('system.php?update=completed#updates'),800);
          return;
        }
        if(sawRestart && progressText) progressText.textContent='TrakFog ist wieder da, wartet aber noch auf V'+target+' …';
      }catch(_){
        sawRestart=true;
        if(progressText) progressText.textContent='Container werden neu gestartet …';
      }
    }
    if(progressTitle) progressTitle.textContent='Update noch nicht bestätigt';
    if(progressText) progressText.textContent='Bitte System → Updates und bei Bedarf die Logs von trakfog-updater prüfen.';
  };

  form?.addEventListener('submit',async event=>{
    event.preventDefault();
    if(progress) progress.hidden=false;
    if(actions) actions.hidden=true;
    if(progressTitle) progressTitle.textContent='Stable Release wird verifiziert …';
    if(progressText) progressText.textContent='Danach übernimmt der integrierte Docker-Updater den Neuaufbau.';

    try{
      const response=await fetch('system.php',{
        method:'POST',
        body:new FormData(form),
        credentials:'same-origin',
        headers:{'X-TrakFog-Update':'1','Accept':'application/json'}
      });
      const data=await response.json();
      if(!response.ok || data?.ok===false) throw new Error(data?.error || 'Update konnte nicht ausgelöst werden.');
      if(progressTitle) progressTitle.textContent='Docker-Updater hat das Update angenommen';
      if(progressText) progressText.textContent='Warte auf den Neustart von TrakFog …';
      await waitForVersion();
    }catch(error){
      // A connection loss immediately after triggering can mean that the updater
      // already replaced this container. Continue polling before declaring it failed.
      if(progressTitle) progressTitle.textContent='Verbindung wurde unterbrochen';
      if(progressText) progressText.textContent='Prüfe, ob TrakFog bereits neu startet …';
      await waitForVersion();
    }
  });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
