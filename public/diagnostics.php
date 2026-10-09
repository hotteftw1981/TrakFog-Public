<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireAdmin();

$checks = [];

$addCheck = static function (string $name, bool $ok, string $detail, string $group = 'System') use (&$checks): void {
    $checks[] = [
        'group' => $group,
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
};

$addCheck('PHP-Version', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, 'PHP');
foreach (['pdo', 'pdo_mysql', 'curl', 'openssl', 'json'] as $extension) {
    $addCheck(
        'Extension ' . $extension,
        extension_loaded($extension),
        extension_loaded($extension) ? 'geladen' : 'fehlt',
        'PHP'
    );
}

$dbOk = false;
$dbVersion = 'nicht erreichbar';
try {
    $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $pdo->query('SELECT 1')->fetchColumn();
    $dbOk = true;
} catch (Throwable $e) {
    AppLogger::exception($e, ['diagnostic' => 'database']);
}
$addCheck('Datenbank', $dbOk, $dbVersion, 'Datenbank');

$schemaVersion = (string)setting($pdo, 'schema_version', 'unbekannt');
$applied = Migrations::applied($pdo);
$migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
$pending = [];
foreach ($migrationFiles as $file) {
    $version = basename($file, '.sql');
    if (!in_array($version, $applied, true)) {
        $pending[] = $version;
    }
}
$addCheck(
    'Schema',
    count($pending) === 0,
    'V' . $schemaVersion . (count($pending) ? ' · ' . count($pending) . ' Migration(en) offen' : ' · aktuell'),
    'Datenbank'
);

try {
    $geoResolved = (int)$pdo->query("SELECT COUNT(*) FROM geo_locations WHERE status='resolved'")->fetchColumn();
    $geoQueued = (int)$pdo->query("SELECT COUNT(*) FROM geo_locations WHERE status IN ('pending','processing','retry')")->fetchColumn();
    $geoFailed = (int)$pdo->query("SELECT COUNT(*) FROM geo_locations WHERE status='failed'")->fetchColumn();
    $geofenceCount = (int)$pdo->query("SELECT COUNT(*) FROM geofences WHERE active=1")->fetchColumn();
    $addCheck(
        'Geo-Cache',
        true,
        $geoResolved . ' aufgelöst · ' . $geoQueued . ' offen · ' . $geoFailed . ' fehlgeschlagen · ' . $geofenceCount . ' Geobereiche',
        'Geo'
    );
} catch (Throwable $e) {
    AppLogger::exception($e, ['diagnostic' => 'geo_cache']);
    $addCheck('Geo-Cache', false, 'nicht verfügbar', 'Geo');
}

$geocoderEnabled = !in_array(
    strtolower(trim((string)(getenv('TRAKFOG_GEOCODER_ENABLED') ?: '1'))),
    ['0','false','no','off'],
    true
);
$geocoderUrl = trim((string)(getenv('TRAKFOG_GEOCODER_URL') ?: 'https://nominatim.openstreetmap.org'));
$addCheck(
    'Reverse Geocoding',
    true,
    $geocoderEnabled ? ('aktiv · ' . $geocoderUrl) : 'deaktiviert',
    'Geo'
);

$configFile = dirname(__DIR__) . '/config/local.php';
if (RuntimeConfig::docker()) {
    $addCheck('Runtime-Konfiguration', true, 'Docker Environment', 'Dateisystem');
} else {
    $addCheck('Lokale Konfiguration', is_file($configFile) && is_readable($configFile), is_readable($configFile) ? 'lesbar' : 'nicht lesbar', 'Dateisystem');
}

$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
$logWritable = is_dir($logDir) && is_writable($logDir);
$addCheck('Log-Verzeichnis', $logWritable, $logWritable ? 'beschreibbar' : 'nicht beschreibbar', 'Dateisystem');

$freeBytes = @disk_free_space(dirname(__DIR__));
$addCheck(
    'Freier Speicher',
    $freeBytes === false || $freeBytes > 50 * 1024 * 1024,
    $freeBytes === false ? 'nicht ermittelbar' : number_format($freeBytes / 1024 / 1024 / 1024, 2, ',', '.') . ' GB',
    'Dateisystem'
);

$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$baseUrl = trim((string)(getenv('TRAKFOG_BASE_URL') ?: ''));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $forwardedProto === 'https'
    || str_starts_with(strtolower($baseUrl), 'https://');
$httpsDetail = $isHttps
    ? ($forwardedProto === 'https' ? 'aktiv · Reverse Proxy' : 'aktiv')
    : 'nicht erkannt';
$addCheck('HTTPS', $isHttps, $httpsDetail, 'Sicherheit');

$cookie = session_get_cookie_params();
$addCheck('Session HttpOnly', (bool)$cookie['httponly'], (bool)$cookie['httponly'] ? 'aktiv' : 'inaktiv', 'Sicherheit');
$addCheck('Session Secure', !$isHttps || (bool)$cookie['secure'], (bool)$cookie['secure'] ? 'aktiv' : 'inaktiv', 'Sicherheit');
$addCheck('SameSite', strtolower((string)($cookie['samesite'] ?? '')) === 'lax', (string)($cookie['samesite'] ?? 'nicht gesetzt'), 'Sicherheit');

try {
    $liveMode = LiveViewAuth::mode($pdo);
    $livePin = LiveViewAuth::pinConfigured($pdo);
    $liveDevices = (int)$pdo->query(
        "SELECT COUNT(*) FROM liveview_devices
         WHERE revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()"
    )->fetchColumn();
    $liveOk = $liveMode !== 'pin' || $livePin;
    $liveDetail = match ($liveMode) {
        'pin' => ($livePin ? 'PIN aktiv' : 'PIN fehlt') . ' · ' . $liveDevices . ' vertrauenswürdige Geräte',
        'open' => 'offener Zugang · ' . $liveDevices . ' gespeicherte Geräte',
        default => 'deaktiviert · Admin-Vorschau möglich',
    };
    $addCheck('LiveView-Zugang', $liveOk, $liveDetail, 'LiveView');
} catch (Throwable $e) {
    AppLogger::exception($e, ['diagnostic' => 'liveview']);
    $addCheck('LiveView-Zugang', false, 'nicht verfügbar', 'LiveView');
}

$addCheck(
    'LiveView Datenquelle',
    true,
    'liest ausschließlich lokale TrakFog-Daten · kein zusätzlicher Tesla-Wakeup',
    'LiveView'
);

$integration = TeslaService::integration($pdo);
$addCheck(
    'Tesla Connector',
    true,
    $integration ? ('Status: ' . ($integration['status'] ?? 'unbekannt')) : 'noch nicht eingerichtet',
    'Tesla'
);
$workerEnabled = setting($pdo, 'worker_enabled', '1') === '1';
$workerHeartbeat = setting($pdo, 'worker_last_heartbeat', null);
$workerState = (string)setting($pdo, 'worker_state', 'unbekannt');
$workerOk = !$workerEnabled;
$workerDetail = $workerEnabled ? 'aktiv · noch kein Heartbeat' : 'pausiert';

if ($workerEnabled && $workerHeartbeat) {
    $lastTs = strtotime((string)$workerHeartbeat . ' UTC');
    $age = $lastTs === false ? null : time() - $lastTs;
    $workerOk = $age !== null && $age <= 120;
    $workerDetail = $workerOk
        ? $workerState . ' · Heartbeat vor ' . max(0, (int)$age) . ' s'
        : 'Heartbeat zu alt';
}

$addCheck('TrakFog Engine', $workerOk, $workerDetail, 'Tesla');

$costSyncEnabled = setting($pdo, 'tesla_charging_cost_sync_enabled', '1') === '1';
$costSyncLastAt = setting($pdo, 'tesla_charging_cost_sync_last_at', null);
$costSyncLastError = setting($pdo, 'tesla_charging_cost_sync_last_error', null);
$costSyncDetail = $costSyncEnabled ? 'aktiv' : 'deaktiviert';
$costSyncOk = true;
if ($costSyncEnabled) {
    if ($costSyncLastAt) {
        $costSyncDetail .= ' · zuletzt ' . $costSyncLastAt . ' UTC';
    } else {
        $costSyncDetail .= ' · noch kein Lauf';
    }
    if ($costSyncLastError) {
        $costSyncOk = false;
        $costSyncDetail .= ' · letzter Lauf mit Fehler';
    }
}
$addCheck('Tesla Charging History', $costSyncOk, $costSyncDetail, 'Tesla');

$groups = [];
foreach ($checks as $check) {
    $groups[$check['group']][] = $check;
}
$failed = count(array_filter($checks, static fn(array $check): bool => !$check['ok']));
AppLogger::log('info', 'System diagnostics opened', ['failed_checks' => $failed]);

render_header('Systemdiagnose', 'system');
?>
<div class="page wrap">
  <div class="page-head">
    <div>
      <span class="kicker">TrakFog Diagnose</span>
      <h1>Systemcheck.</h1>
      <p>Damit aus „HTTP 500 :-D“ künftig eine brauchbare Fehler-ID wird. 😄</p>
    </div>
    <div class="split-actions">
      <span class="pill <?= $failed ? 'warn' : 'ok' ?>"><?= $failed ? $failed . ' Hinweis(e)' : 'alles grün' ?></span>
      <span class="pill">Request <?= e(AppLogger::requestId()) ?></span>
    </div>
  </div>

  <div class="grid-2">
    <?php foreach ($groups as $group => $items): ?>
      <section class="panel">
        <div class="panel-head"><h3><?= e($group) ?></h3><span class="pill"><?= count($items) ?> Checks</span></div>
        <div class="panel-body">
          <div class="data-list">
            <?php foreach ($items as $item): ?>
              <div class="data-row">
                <span><?= e($item['name']) ?></span>
                <span class="split-actions">
                  <span class="small"><?= e($item['detail']) ?></span>
                  <span class="pill <?= $item['ok'] ? 'ok' : 'warn' ?>"><?= $item['ok'] ? '✓' : '!' ?></span>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <section class="panel" style="margin-top:16px">
    <div class="panel-head"><h3>Fehlerdiagnose</h3><span class="pill ok">Logging aktiv</span></div>
    <div class="panel-body">
      <p class="small">Interne Fehler werden mit einer zufälligen Fehler-ID in <span class="mono">storage/logs/</span> protokolliert. Tokens, Passwörter, Cookies und andere erkannte Geheimnisse werden aus Log-Kontexten entfernt.</p>
      <div class="data-list">
        <div class="data-row"><span>Aktuelles Log</span><b class="mono"><?= e(basename(AppLogger::path())) ?></b></div>
        <div class="data-row"><span>App-Version</span><b>V<?= e(app_version()) ?></b></div>
        <div class="data-row"><span>Schema-Version</span><b>V<?= e($schemaVersion) ?></b></div>
        <div class="data-row"><span>Zeitzone</span><b><?= e(date_default_timezone_get()) ?></b></div>
      </div>
      <div class="split-actions" style="margin-top:16px">
        <a class="btn btn-primary" href="system.php#diagnostics">← System</a>
      </div>
    </div>
  </section>
</div>
<?php render_footer(); ?>
