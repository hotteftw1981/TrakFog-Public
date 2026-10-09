<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireAdmin();

$message = $error = null;

$getSetting = static function(PDO $pdo, string $key, ?string $default = null): ?string {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string)$value : $default;
};

$setSetting = static function(PDO $pdo, string $key, ?string $value): void {
    $pdo->prepare(
        'INSERT INTO settings(setting_key,setting_value) VALUES(?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
    )->execute([$key, $value]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'enable') {
                $setSetting($pdo, 'worker_enabled', '1');
                $message = 'TrakFog Engine aktiviert.';
            } elseif ($action === 'disable') {
                $setSetting($pdo, 'worker_enabled', '0');
                $message = 'TrakFog Engine pausiert.';
            }
        } catch (Throwable $e) {
            $error = report_exception($e, 'Engine-Einstellung konnte nicht gespeichert werden.');
        }
    }
}

$enabled = $getSetting($pdo, 'worker_enabled', '1') === '1';
$heartbeat = $getSetting($pdo, 'worker_last_heartbeat');
$state = $getSetting($pdo, 'worker_state', 'unbekannt') ?? 'unbekannt';
$lastError = $getSetting($pdo, 'worker_last_error');
$lastResultRaw = $getSetting($pdo, 'worker_last_result');
$costSyncEnabled = $getSetting($pdo, 'tesla_charging_cost_sync_enabled', '1') === '1';
$costSyncLastAt = $getSetting($pdo, 'tesla_charging_cost_sync_last_at');
$costSyncLastError = $getSetting($pdo, 'tesla_charging_cost_sync_last_error');
$costSyncSummaryRaw = $getSetting($pdo, 'tesla_charging_cost_sync_last_summary');
$costSyncSummary = [];
if ($costSyncSummaryRaw) {
    try {
        $decodedCost = json_decode($costSyncSummaryRaw, true, 64, JSON_THROW_ON_ERROR);
        if (is_array($decodedCost)) $costSyncSummary = $decodedCost;
    } catch (Throwable) {
        $costSyncSummary = [];
    }
}
$lastResult = [];

if ($lastResultRaw) {
    try {
        $decoded = json_decode($lastResultRaw, true, 64, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) $lastResult = $decoded;
    } catch (Throwable) {
        $lastResult = [];
    }
}

$heartbeatAge = null;
if ($heartbeat) {
    $ts = strtotime($heartbeat . ' UTC');
    if ($ts !== false) {
        $heartbeatAge = max(0, time() - $ts);
    }
}

$processAlive = $heartbeatAge !== null && $heartbeatAge <= 120;
$stateBroken = in_array(strtolower((string)$state), ['error','stopped'], true);
$healthy = $processAlive && !$stateBroken;
$docker = RuntimeConfig::docker();

$streamRows = [];
try {
    $streamRows = $pdo->query(
        "SELECT s.*, v.display_name,
                x.recorded_at AS sample_at,
                x.speed_kmh AS sample_speed_kmh,
                x.soc AS sample_soc,
                x.power_kw AS sample_power_kw,
                x.latitude AS sample_latitude,
                x.longitude AS sample_longitude,
                x.heading AS sample_heading,
                t.id AS active_trip_id,
                t.started_at AS active_trip_started_at,
                t.distance_km AS active_trip_distance_km,
                t.max_speed_kmh AS active_trip_max_speed_kmh,
                t.sample_count AS active_trip_sample_count,
                c.id AS active_charge_id,
                c.energy_added_kwh AS active_charge_energy_kwh,
                c.max_power_kw AS active_charge_max_power_kw,
                c.last_state AS active_charge_state
         FROM vehicle_stream_status s
         JOIN vehicles v ON v.id=s.vehicle_id
         LEFT JOIN vehicle_stream_samples x
           ON x.id = (
               SELECT x2.id
               FROM vehicle_stream_samples x2
               WHERE x2.vehicle_id=s.vehicle_id
               ORDER BY x2.id DESC
               LIMIT 1
           )
         LEFT JOIN trips t
           ON t.id = (
               SELECT t2.id
               FROM trips t2
               WHERE t2.vehicle_id=s.vehicle_id
                 AND t2.ended_at IS NULL
               ORDER BY t2.id DESC
               LIMIT 1
           )
         LEFT JOIN charges c
           ON c.id = (
               SELECT c2.id
               FROM charges c2
               WHERE c2.vehicle_id=s.vehicle_id
                 AND c2.ended_at IS NULL
               ORDER BY c2.id DESC
               LIMIT 1
           )
         ORDER BY v.display_name,v.id"
    )->fetchAll();
} catch (Throwable) {
    $streamRows = [];
}

$timezoneName = (string)(getenv('TRAKFOG_TIMEZONE') ?: 'Europe/Berlin');
try {
    $displayTimezone = new DateTimeZone($timezoneName);
} catch (Throwable) {
    $displayTimezone = new DateTimeZone('UTC');
}

$formatUtcForDisplay = static function(?string $value) use ($displayTimezone): string {
    if ($value === null || trim($value) === '') {
        return '–';
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone($displayTimezone)->format('d.m.Y H:i:s');
    } catch (Throwable) {
        return $value;
    }
};

render_header('TrakFog Engine', 'system');
?>
<div class="page wrap" data-live-region="page-engine" data-live-poll="5000">
  <div class="page-head">
    <div>
      <span class="kicker">TrakFog Engine</span>
      <h1>Hintergrunddienste.</h1>
      <p>Die Engine sammelt Tesla-Daten, hält den Live-Stream offen und bildet die Basis für automatische Fahrten, Ladevorgänge und Statistiken.</p>
    </div>
    <div class="split-actions">
      <span class="pill <?= $docker ? 'ok' : 'warn' ?>"><?= $docker ? '🐳 Docker' : 'Webspace' ?></span>
      <span class="pill <?= $healthy ? 'ok' : 'warn' ?>"><?= $healthy ? '● Engine aktiv' : 'Engine nicht erreichbar' ?></span>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <div class="grid-2">
    <section class="panel">
      <div class="panel-head"><h3>🤖 Status</h3><span class="pill <?= $enabled ? 'ok' : '' ?>"><?= $enabled ? 'aktiv' : 'pausiert' ?></span></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>Runtime</span><b><?= $docker ? 'Docker / Portainer' : 'Legacy Webspace' ?></b></div>
          <div class="data-row"><span>Status</span><b><?= e($state) ?></b></div>
          <div class="data-row"><span>Heartbeat</span><b><?= e($formatUtcForDisplay($heartbeat)) ?></b></div>
          <div class="data-row"><span>Alter Heartbeat</span><b><?= $heartbeatAge !== null ? $heartbeatAge . ' s' : '–' ?></b></div>
          <div class="data-row"><span>Online Poll</span><b><?= e((string)(getenv('TRAKFOG_WORKER_POLL_ONLINE_SECONDS') ?: '30')) ?> s</b></div>
          <div class="data-row"><span>Sleep Poll</span><b><?= e((string)(getenv('TRAKFOG_WORKER_POLL_SLEEP_SECONDS') ?: '60')) ?> s</b></div>
          <div class="data-row"><span>Tesla Charging History</span><b><?= $costSyncEnabled ? '✅ aktiv' : 'deaktiviert' ?></b></div>
          <div class="data-row"><span>Letzter Kosten-Sync</span><b><?= e($formatUtcForDisplay($costSyncLastAt)) ?></b></div>
          <div class="data-row"><span>Kosten übernommen</span><b><?= isset($costSyncSummary['updated']) ? (int)$costSyncSummary['updated'] : '–' ?></b></div>
          <?php if ($costSyncLastError): ?><div class="data-row"><span>Kosten-Sync Fehler</span><b class="warn"><?= e((string)$costSyncLastError) ?></b></div><?php endif; ?>
        </div>

        <div class="connector-actions">
          <?php if ($enabled): ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="disable"><button class="btn btn-danger" type="submit">Engine pausieren</button></form>
          <?php else: ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="enable"><button class="btn btn-primary" type="submit">Engine starten</button></form>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>📡 Letzter Poll</h3><span class="pill <?= empty($lastResult['errors']) ? 'ok' : 'warn' ?>"><?= empty($lastResult['errors']) ? 'ok' : 'mit Fehlern' ?></span></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>Fahrzeuge</span><b><?= (int)($lastResult['vehicles'] ?? 0) ?></b></div>
          <div class="data-row"><span>Online</span><b><?= (int)($lastResult['online'] ?? 0) ?></b></div>
          <div class="data-row"><span>Snapshots</span><b><?= (int)($lastResult['snapshots'] ?? 0) ?></b></div>
          <div class="data-row"><span>Polled at</span><b><?= e($formatUtcForDisplay(isset($lastResult['polled_at']) ? (string)$lastResult['polled_at'] : null)) ?></b></div>
          <div class="data-row"><span>Fehler</span><b><?= count($lastResult['errors'] ?? []) ?></b></div>
        </div>

        <?php if ($lastError): ?>
          <div class="alert alert-warn" style="margin-top:14px"><?= e($lastError) ?></div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <section class="panel" style="margin-top:16px">
    <div class="panel-head"><h3>📡 Tesla Driving Stream</h3><span class="pill ok">Beta aktiv</span></div>
    <div class="panel-body">
      <?php if (!$streamRows): ?>
        <div class="empty">Noch kein Stream-Fahrzeug aktiv.<br><span class="small">Nach der nächsten Fahrzeugsynchronisation startet die Engine den Tesla-WebSocket automatisch.</span></div>
      <?php else: ?>
        <?php foreach ($streamRows as $stream): ?>
          <?php
            $streamState = (string)($stream['status'] ?? 'unbekannt');
            $connectionOk = in_array($streamState, ['connected','waiting_data','streaming'], true);
            $connectionClass = $connectionOk
                ? 'ok'
                : (in_array($streamState, ['waiting_vehicle','offline'], true) ? 'sleep' : 'warn');
            $connectionLabels = [
                'connecting' => 'verbindet …',
                'connected' => 'verbunden',
                'waiting_data' => 'verbunden',
                'streaming' => 'verbunden',
                'waiting_vehicle' => 'verbunden · Fahrzeug nicht verfügbar',
                'offline' => 'verbunden · Fahrzeug offline',
                'reconnecting' => 'verbindet neu …',
                'auth_refresh' => 'Token wird erneuert …',
                'client_error' => 'Client-Fehler',
                'vehicle_error' => 'Fahrzeugfehler',
                'error' => 'Fehler',
                'stopped' => 'gestoppt',
            ];
            $connectionLabel = $connectionLabels[$streamState] ?? $streamState;

            $sampleAge = null;
            if (!empty($stream['sample_at'])) {
                $sampleTs = strtotime((string)$stream['sample_at'] . ' UTC');
                if ($sampleTs !== false) {
                    $sampleAge = max(0, time() - $sampleTs);
                }
            }

            if ($sampleAge === null) {
                $dataLabel = ((int)($stream['update_count'] ?? 0) > 0) ? 'letztes Paket unbekannt' : 'noch keine Daten';
                $dataClass = '';
            } elseif ($sampleAge <= 15) {
                $dataLabel = '● live';
                $dataClass = 'ok';
            } elseif ($sampleAge < 60) {
                $dataLabel = 'letztes Paket vor ' . $sampleAge . ' s';
                $dataClass = '';
            } elseif ($sampleAge < 3600) {
                $dataLabel = 'letztes Paket vor ' . max(1, (int)floor($sampleAge / 60)) . ' min';
                $dataClass = 'sleep';
            } else {
                $dataLabel = 'letztes Paket ' . $formatUtcForDisplay((string)$stream['sample_at']);
                $dataClass = 'sleep';
            }
          ?>
          <div class="data-list" style="margin-bottom:14px">
            <div class="data-row"><span>Fahrzeug</span><b><?= e((string)($stream['display_name'] ?? 'Tesla')) ?></b></div>
            <div class="data-row"><span>Verbindung</span><span class="pill <?= e($connectionClass) ?>"><?= e($connectionLabel) ?></span></div>
            <div class="data-row"><span>Datenstatus</span><span class="pill <?= e($dataClass) ?>"><?= e($dataLabel) ?></span></div>
            <div class="data-row"><span>Datenpakete</span><b><?= number_format((int)($stream['update_count'] ?? 0), 0, ',', '.') ?></b></div>
            <div class="data-row"><span>Letztes Paket</span><b><?= e($formatUtcForDisplay(isset($stream['sample_at']) ? (string)$stream['sample_at'] : null)) ?></b></div>
            <div class="data-row"><span>Gang</span><b><?= e((string)($stream['shift_state'] ?? '–')) ?></b></div>
            <div class="data-row"><span>Tempo</span><b><?= $stream['sample_speed_kmh'] !== null ? number_format((float)$stream['sample_speed_kmh'], 0, ',', '.') . ' km/h' : '–' ?></b></div>
            <div class="data-row"><span>Akku</span><b><?= $stream['sample_soc'] !== null ? number_format((float)$stream['sample_soc'], 0, ',', '.') . '%' : '–' ?></b></div>
            <div class="data-row"><span>Leistung</span><b><?= $stream['sample_power_kw'] !== null ? number_format((float)$stream['sample_power_kw'], 1, ',', '.') . ' kW' : '–' ?></b></div>
            <div class="data-row"><span>Position</span><b class="mono"><?= $stream['sample_latitude'] !== null && $stream['sample_longitude'] !== null ? e((string)$stream['sample_latitude'] . ' · ' . (string)$stream['sample_longitude']) : '–' ?></b></div>
            <div class="data-row">
              <span>Fahrterkennung</span>
              <?php if (!empty($stream['active_trip_id'])): ?>
                <b>● Fahrt #<?= (int)$stream['active_trip_id'] ?> · <?= number_format((float)($stream['active_trip_distance_km'] ?? 0),1,',','.') ?> km</b>
              <?php else: ?>
                <b>bereit</b>
              <?php endif; ?>
            </div>
            <div class="data-row">
              <span>Ladeerkennung</span>
              <?php if (!empty($stream['active_charge_id'])): ?>
                <b>⚡ Session #<?= (int)$stream['active_charge_id'] ?> · <?= number_format((float)($stream['active_charge_energy_kwh'] ?? 0),2,',','.') ?> kWh</b>
              <?php else: ?>
                <b>bereit</b>
              <?php endif; ?>
            </div>
            <div class="data-row"><span>Letzter Fehler</span><b><?= e((string)($stream['last_error'] ?? '–')) ?></b></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel" style="margin-top:16px">
    <div class="panel-head"><h3>🚀 Nächster Ausbau</h3><span class="pill">Driving Stream aktiv</span></div>
    <div class="panel-body">
      <div class="data-list">
        <div class="data-row"><span>TrakFog Engine</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Tesla Status Polling</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Online Detail-Snapshots</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Tesla WebSocket Streaming</span><b>✅ aktiv · Beta</b></div>
        <div class="data-row"><span>Stream-Samples</span><b>✅ GPS · Speed · SoC · Power · Gang</b></div>
        <div class="data-row"><span>Automatische Fahrterkennung</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Automatische Ladeerkennung</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Live-UI ohne F5</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Charts & Verläufe</span><b>✅ Akku · Range · Temperatur · Speed · Power</b></div>
        <div class="data-row"><span>Ladekurven</span><b>✅ pro Ladesession</b></div>
        <div class="data-row"><span>Tages-/Monats-/Jahresstatistik</span><b>✅ aktiv</b></div>
        <div class="data-row"><span>Ladepreise & Kosten</span><b>✅ Gesamtpreis · Tarife · automatische Tesla Supercharger-Abrechnung</b></div>
        <div class="data-row"><span>Phantom Drain / Sleep</span><b>✅ Zustände · Schlafphasen · Drain-Rate</b></div>
        <div class="data-row"><span>Map & Geo</span><b>✅ Routen · Ladeorte · Adressen · Radius/Polygon-Geofences</b></div>
        <div class="data-row"><span>Heatmaps</span><b>✅ Fahrdichte · Lade-Hotspots</b></div>
        <div class="data-row"><span>Reisen & Touren</span><b>✅ Auto-Zuordnung · Karte · Timeline · Kosten</b></div>
        <div class="data-row"><span>LiveView</span><b>✅ Visual Dashboard · Swipe · Live Map · Tesla-NerdView · Night Map</b></div>
        <div class="data-row"><span>LiveView Community</span><b>vorbereitet · Standortfreigabe · andere Teslas · Orte</b></div>
      </div>
    </div>
  </section>
</div>
<?php render_footer(); ?>
