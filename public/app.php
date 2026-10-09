<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$user = Auth::user($pdo);
$canManageUpdates = in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
$trakfogUpdate = $canManageUpdates ? UpdateService::status($pdo, $config, false) : null;
$trakfogUpdateAvailable = is_array($trakfogUpdate) && !empty($trakfogUpdate['update_available']);
$trakfogUpdateVersion = $trakfogUpdateAvailable ? (string)($trakfogUpdate['latest_version'] ?? '') : '';

$vehicles = (int)$pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$tripCount = (int)$pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();
$chargeCount = (int)$pdo->query('SELECT COUNT(*) FROM charges')->fetchColumn();
$journeyCount = (int)$pdo->query('SELECT COUNT(*) FROM journeys')->fetchColumn();
$activeJourneyCount = (int)$pdo->query("SELECT COUNT(*) FROM journeys WHERE status='active'")->fetchColumn();
$distance = (float)$pdo->query('SELECT COALESCE(SUM(distance_km),0) FROM trips')->fetchColumn();
$energy = (float)$pdo->query('SELECT COALESCE(SUM(energy_added_kwh),0) FROM charges')->fetchColumn();
$validSleepSessions = (int)$pdo->query("SELECT COUNT(*) FROM sleep_sessions WHERE quality='valid'")->fetchColumn();
$phantomDrainTotal = (float)$pdo->query("SELECT COALESCE(SUM(drain_percent),0) FROM sleep_sessions WHERE quality='valid'")->fetchColumn();

$integration = TeslaService::integration($pdo);

$vehicleRows = $pdo->query(
    "SELECT v.*,
            (SELECT MAX(s.recorded_at) FROM vehicle_snapshots s WHERE s.vehicle_id=v.id) AS detail_data_at,
            (SELECT s.raw_json FROM vehicle_snapshots s WHERE s.vehicle_id=v.id ORDER BY s.recorded_at DESC,s.id DESC LIMIT 1) AS detail_raw_json,
            (SELECT s.charging_state FROM vehicle_snapshots s WHERE s.vehicle_id=v.id ORDER BY s.recorded_at DESC LIMIT 1) AS charging_state,
            (SELECT t.id FROM trips t WHERE t.vehicle_id=v.id AND t.ended_at IS NULL ORDER BY t.id DESC LIMIT 1) AS active_trip_id,
            (SELECT t.distance_km FROM trips t WHERE t.vehicle_id=v.id AND t.ended_at IS NULL ORDER BY t.id DESC LIMIT 1) AS active_trip_distance_km,
            (SELECT c.id FROM charges c WHERE c.vehicle_id=v.id AND c.ended_at IS NULL ORDER BY c.id DESC LIMIT 1) AS active_charge_id,
            (SELECT c.energy_added_kwh FROM charges c WHERE c.vehicle_id=v.id AND c.ended_at IS NULL ORDER BY c.id DESC LIMIT 1) AS active_charge_energy_kwh,
            (SELECT c.max_power_kw FROM charges c WHERE c.vehicle_id=v.id AND c.ended_at IS NULL ORDER BY c.id DESC LIMIT 1) AS active_charge_max_power_kw,
            (SELECT ss.started_at FROM sleep_sessions ss WHERE ss.vehicle_id=v.id AND ss.ended_at IS NULL ORDER BY ss.id DESC LIMIT 1) AS active_sleep_started_at,
            (SELECT j.id FROM journeys j WHERE j.vehicle_id=v.id AND j.status='active' ORDER BY j.started_at DESC,j.id DESC LIMIT 1) AS active_journey_id,
            (SELECT j.title FROM journeys j WHERE j.vehicle_id=v.id AND j.status='active' ORDER BY j.started_at DESC,j.id DESC LIMIT 1) AS active_journey_title
     FROM vehicles v
     ORDER BY v.display_name,v.id"
)->fetchAll();

$softwareUpdates = [];
foreach ($vehicleRows as $vehicleRow) {
    $detailRaw = [];
    if (is_string($vehicleRow['detail_raw_json'] ?? null) && trim((string)$vehicleRow['detail_raw_json']) !== '') {
        $decoded = json_decode((string)$vehicleRow['detail_raw_json'], true);
        $detailRaw = is_array($decoded) ? $decoded : [];
    }
    $vehicleStateRaw = is_array($detailRaw['vehicle_state'] ?? null) ? $detailRaw['vehicle_state'] : [];
    $software = is_array($vehicleStateRaw['software_update'] ?? null) ? $vehicleStateRaw['software_update'] : [];
    $status = strtolower(trim((string)($software['status'] ?? '')));
    $version = trim((string)($software['version'] ?? ''));
    $interesting = !in_array($status, ['', 'idle', 'none', 'unknown'], true) || $version !== '';

    if ($interesting) {
        $softwareUpdates[] = [
            'vehicle_id' => (int)$vehicleRow['id'],
            'vehicle_name' => (string)($vehicleRow['display_name'] ?: 'Tesla'),
            'current_version' => trim((string)($vehicleStateRaw['car_version'] ?? '')),
            'status' => $status !== '' ? $status : 'verfügbar',
            'version' => $version,
            'download' => is_numeric($software['download_perc'] ?? null) ? (float)$software['download_perc'] : null,
            'install' => is_numeric($software['install_perc'] ?? null) ? (float)$software['install_perc'] : null,
        ];
    }
}

$fmtLocal = static function (?string $value): string {
    if (!$value) return '–';
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

$fmtSleepAge = static function (?string $value): string {
    if (!$value) return '–';
    try {
        $start = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $seconds = max(0, time() - $start->getTimestamp());
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours >= 24) {
            return intdiv($hours, 24) . ' T ' . ($hours % 24) . ' h';
        }
        if ($hours > 0) {
            return $hours . ' h ' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' min';
        }
        return max(1, $minutes) . ' min';
    } catch (Throwable) {
        return '–';
    }
};

$dashboardTone = static function (string $value): string {
    $value = strtolower($value);
    return match (true) {
        str_contains($value, 'white'), str_contains($value, 'pearl'), str_contains($value, 'ppsw') => 'white',
        str_contains($value, 'black'), str_contains($value, 'pbsb') => 'black',
        str_contains($value, 'red'), str_contains($value, 'ppmr'), str_contains($value, 'pr01') => 'red',
        str_contains($value, 'blue'), str_contains($value, 'ppsb') => 'blue',
        str_contains($value, 'silver'), str_contains($value, 'grey'), str_contains($value, 'gray'), str_contains($value, 'pmng') => 'silver',
        default => 'white', // Unknown Tesla color: preserve the neutral photo instead of blackening it.
    };
};

$dashboardModel = static function (array $configData): string {
    $type = trim((string)($configData['car_type'] ?? 'Tesla'));
    $trim = trim((string)($configData['trim_badging'] ?? ''));
    $normalized = match (strtolower($type)) {
        'model3', 'model 3', '3' => 'Model 3',
        'modely', 'model y', 'y' => 'Model Y',
        'models', 'model s', 's' => 'Model S',
        'modelx', 'model x', 'x' => 'Model X',
        default => $type !== '' ? $type : 'Tesla',
    };

    // Tesla sometimes exposes internal/numeric trim codes (e.g. "50").
    // Those are useful technically, but ugly in a human-facing dashboard.
    $humanTrim = $trim !== '' && !preg_match('/^\d+$/', $trim) ? $trim : '';
    return trim($normalized . ($humanTrim !== '' ? ' · ' . $humanTrim : ''));
};

$dashboardVehicles = [];
foreach ($vehicleRows as $vehicleRow) {
    $raw = [];
    if (is_string($vehicleRow['detail_raw_json'] ?? null) && trim((string)$vehicleRow['detail_raw_json']) !== '') {
        $decoded = json_decode((string)$vehicleRow['detail_raw_json'], true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    $configData = is_array($raw['vehicle_config'] ?? null) ? $raw['vehicle_config'] : [];
    $resolvedArt = TeslaVehicleArt::resolve(
        (string)($configData['car_type'] ?? ''),(string)($vehicleRow['model_name'] ?? ''),
        (string)($vehicleRow['vin'] ?? ''),
        TeslaVehicleArt::override($pdo,(int)$vehicleRow['id'])
    );
    $modelKey = $resolvedArt['variant'] ?? 'unknown';
    $chargeData = is_array($raw['charge_state'] ?? null) ? $raw['charge_state'] : [];
    $driveData = is_array($raw['drive_state'] ?? null) ? $raw['drive_state'] : [];
    $climateData = is_array($raw['climate_state'] ?? null) ? $raw['climate_state'] : [];
    $vehicleState = is_array($raw['vehicle_state'] ?? null) ? $raw['vehicle_state'] : [];

    $state = strtolower(trim((string)($vehicleRow['state'] ?? 'unknown')));
    $chargingState = strtolower(trim((string)($vehicleRow['charging_state'] ?? $chargeData['charging_state'] ?? '')));
    $isCharging = !empty($vehicleRow['active_charge_id']) || in_array($chargingState, ['charging','starting'], true);
    $shiftState = strtoupper(trim((string)($driveData['shift_state'] ?? '')));
    $isDriving = !empty($vehicleRow['active_trip_id']) || in_array($shiftState, ['D','R','N'], true);
    $isSleeping = !empty($vehicleRow['active_sleep_started_at']) || $state === 'asleep';

    $mode = match (true) {
        $isCharging => 'charging',
        $isDriving => 'driving',
        $isSleeping => 'sleeping',
        $state === 'online' => 'online',
        default => 'offline',
    };

    $statusLabel = match ($mode) {
        'charging' => 'Lädt',
        'driving' => 'Fährt',
        'sleeping' => 'Schläft',
        'online' => 'Online',
        default => 'Offline',
    };

    $minutesToFull = is_numeric($chargeData['minutes_to_full_charge'] ?? null)
        ? max(0, (int)round((float)$chargeData['minutes_to_full_charge']))
        : null;
    $chargeEta = $minutesToFull !== null
        ? ($minutesToFull >= 60
            ? intdiv($minutesToFull, 60) . ' h ' . str_pad((string)($minutesToFull % 60), 2, '0', STR_PAD_LEFT) . ' min'
            : max(1, $minutesToFull) . ' min')
        : null;

    $statusDetail = match ($mode) {
        'charging' => $chargeEta ? 'noch ' . $chargeEta : (!empty($vehicleRow['active_charge_energy_kwh']) ? number_format((float)$vehicleRow['active_charge_energy_kwh'],1,',','.') . ' kWh' : 'aktiv'),
        'driving' => !empty($vehicleRow['active_trip_distance_km']) ? number_format((float)$vehicleRow['active_trip_distance_km'],1,',','.') . ' km live' : 'unterwegs',
        'sleeping' => $fmtSleepAge((string)($vehicleRow['active_sleep_started_at'] ?? '')),
        'online' => 'bereit',
        default => 'wartet auf Daten',
    };

    $power = is_numeric($chargeData['charger_power'] ?? null)
        ? (float)$chargeData['charger_power']
        : (is_numeric($vehicleRow['active_charge_max_power_kw'] ?? null) ? (float)$vehicleRow['active_charge_max_power_kw'] : null);

    $dashboardVehicles[] = [
        'id' => (int)$vehicleRow['id'],
        'name' => (string)($vehicleRow['display_name'] ?: 'Tesla'),
        'model_key' => $modelKey,
        'car_asset' => $resolvedArt['asset'] ?? 'assets/brand/trakfog-icon.svg',
        'model' => $dashboardModel($configData),
        'tone' => $dashboardTone((string)($configData['exterior_color'] ?? '')),
        'mode' => $mode,
        'status' => $statusLabel,
        'status_detail' => $statusDetail,
        'soc' => is_numeric($vehicleRow['battery_level'] ?? null) ? (float)$vehicleRow['battery_level'] : null,
        'range_km' => is_numeric($vehicleRow['rated_range_km'] ?? null) ? (float)$vehicleRow['rated_range_km'] : null,
        'target_soc' => is_numeric($chargeData['charge_limit_soc'] ?? null) ? (float)$chargeData['charge_limit_soc'] : null,
        'power_kw' => $power,
        'sleep_age' => !empty($vehicleRow['active_sleep_started_at']) ? $fmtSleepAge((string)$vehicleRow['active_sleep_started_at']) : null,
        'trip_distance_km' => is_numeric($vehicleRow['active_trip_distance_km'] ?? null) ? (float)$vehicleRow['active_trip_distance_km'] : null,
        'outside_temp_c' => is_numeric($climateData['outside_temp'] ?? null) ? (float)$climateData['outside_temp'] : null,
        'locked' => array_key_exists('locked', $vehicleState) ? (bool)$vehicleState['locked'] : null,
        'updated' => $fmtLocal((string)($vehicleRow['detail_data_at'] ?? $vehicleRow['last_seen_at'] ?? '')),
    ];
}

$dashboardPrimary = $dashboardVehicles[0] ?? null;

// Model-dependent local artwork; never load vehicle imagery from third-party servers.
$renderDashboardCar = static function (string $extraClass = '', string $modelKey = 'y'): void {
    $src = in_array($modelKey,['3-classic','3-highland','y-classic','y-juniper','s','x'],true)
        ? TeslaVehicleArt::asset($modelKey) : 'assets/brand/trakfog-icon.svg';
    ?>
    <img class="dashboard-car-image <?= e($extraClass) ?>"
         src="<?= e($src) ?>?v=<?= e(app_version()) ?>"
         width="900" height="390" alt=""
         loading="eager" decoding="async" draggable="false">
    <?php
};

render_header('Übersicht','home');
?>
<div class="page wrap" data-live-region="page-dashboard" data-live-poll="5000">
  <div class="page-head">
    <div>
      <span class="kicker">Übersicht</span>
      <h1>Heute in TrakFog.</h1>
      <p>Fahrzeugstatus, aktive Vorgänge und die wichtigsten Summen – ohne Systemtechnik dazwischen.</p>
    </div>
    <div class="split-actions">
      <span class="pill <?= (($integration['status'] ?? '') === 'connected') ? 'ok' : '' ?>">
        <?= (($integration['status'] ?? '') === 'connected') ? '● Tesla verbunden' : 'Tesla nicht verbunden' ?>
      </span>
      <?php if ($activeJourneyCount > 0): ?><a class="pill ok" href="journeys.php">🧳 <?= $activeJourneyCount ?> Reise aktiv</a><?php endif; ?>
      <a class="btn btn-primary" href="live.php" target="_blank" rel="noopener">📺 LiveView</a>
      <span class="pill">V<?= e(app_version()) ?></span>
    </div>
  </div>

  <?php if ($trakfogUpdateAvailable && $trakfogUpdateVersion !== ''): ?>
    <a class="dashboard-trakfog-update" href="system.php#updates">
      <span class="dashboard-trakfog-update-icon">⬆</span>
      <span>
        <strong>TrakFog V<?= e($trakfogUpdateVersion) ?> verfügbar</strong>
        <small>Stable Release · installiert V<?= e(app_version()) ?></small>
      </span>
      <b>Update ansehen →</b>
    </a>
  <?php endif; ?>

  <div class="stat-grid">
    <a class="stat tf-stat-link" href="drive.php#vehicle-list" title="Details zu Fahrzeuge" aria-label="Details zu Fahrzeuge öffnen"><strong><?= $vehicles ?></strong><span>Fahrzeuge</span></a>
    <a class="stat tf-stat-link" href="trips.php#trip-list" title="Details zu Fahrten" aria-label="Details zu Fahrten öffnen"><strong><?= $tripCount ?></strong><span>Fahrten</span></a>
    <a class="stat tf-stat-link" href="charges.php#charge-list" title="Details zu Ladevorgänge" aria-label="Details zu Ladevorgänge öffnen"><strong><?= $chargeCount ?></strong><span>Ladevorgänge</span></a>
    <a class="stat tf-stat-link" href="statistics.php#lifetime" title="Details zu erfasste Strecke" aria-label="Details zu erfasste Strecke öffnen"><strong><?= number_format($distance,1,',','.') ?> km</strong><span>erfasste Strecke</span></a>
  </div>

  <?php if ($softwareUpdates): ?>
    <section class="panel dashboard-update-radar">
      <div class="panel-head">
        <div>
          <span class="kicker">Tesla Update-Radar</span>
          <h3>⬆ Software-Update erkannt</h3>
        </div>
        <span class="pill ok"><?= count($softwareUpdates) ?> <?= count($softwareUpdates) === 1 ? 'Fahrzeug' : 'Fahrzeuge' ?></span>
      </div>
      <div class="panel-body dashboard-update-list">
        <?php foreach ($softwareUpdates as $update): ?>
          <?php $progress = $update['install'] ?? $update['download']; ?>
          <a class="dashboard-update-item" href="vehicle.php?id=<?= (int)$update['vehicle_id'] ?>">
            <span class="dashboard-update-icon">⬆</span>
            <span>
              <strong><?= e($update['vehicle_name']) ?> · <?= e($update['version'] !== '' ? $update['version'] : 'Neue Version') ?></strong>
              <small>
                <?= e(strtoupper((string)$update['status'])) ?>
                <?= $update['current_version'] !== '' ? ' · aktuell ' . e($update['current_version']) : '' ?>
                <?= $progress !== null ? ' · ' . number_format((float)$progress,0,',','.') . ' %' : '' ?>
              </small>
            </span>
            <b>Details →</b>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="dashboard-main dashboard-v2" data-dashboard-root>
    <section class="panel dashboard-fleet-panel">
      <div class="panel-head">
        <div>
          <h3>🚗 Fahrzeuge</h3>
          <span class="small">Nebeneinander auswählen · Lieblingsfahrzeug mit ★ markieren</span>
        </div>
        <a class="small" href="drive.php">Fahrzeuge verwalten →</a>
      </div>

      <?php if (!$dashboardVehicles): ?>
        <div class="panel-body">
          <div class="empty">Noch kein Tesla verbunden.<br><br><a class="btn btn-primary" href="system.php#tesla">Tesla verbinden →</a></div>
        </div>
      <?php else: ?>
        <div class="dashboard-vehicle-strip" data-dashboard-vehicle-strip>
          <?php foreach ($dashboardVehicles as $index => $dv): ?>
            <?php
              $soc = $dv['soc'];
              $socWidth = $soc !== null ? max(0, min(100, (float)$soc)) : 0;
              $cardStatusClass = match ($dv['mode']) {
                  'charging', 'online' => 'ok',
                  'sleeping' => 'sleep',
                  'driving' => 'drive',
                  default => 'offline',
              };
            ?>
            <article
              class="dashboard-vehicle-card vehicle-tone-<?= e((string)$dv['tone']) ?><?= $index === 0 ? ' is-selected is-favorite' : '' ?>"
              role="button"
              tabindex="0"
              aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
              data-dashboard-vehicle-select
              data-vehicle-id="<?= (int)$dv['id'] ?>"
              data-name="<?= e((string)$dv['name']) ?>"
              data-model="<?= e((string)$dv['model']) ?>"
              data-car-asset="<?= e((string)$dv['car_asset']) ?>?v=<?= e(app_version()) ?>"
              data-tone="<?= e((string)$dv['tone']) ?>"
              data-mode="<?= e((string)$dv['mode']) ?>"
              data-status="<?= e((string)$dv['status']) ?>"
              data-status-detail="<?= e((string)$dv['status_detail']) ?>"
              data-soc="<?= $dv['soc'] !== null ? e((string)round((float)$dv['soc'])) : '' ?>"
              data-range="<?= $dv['range_km'] !== null ? e((string)round((float)$dv['range_km'])) : '' ?>"
              data-target="<?= $dv['target_soc'] !== null ? e((string)round((float)$dv['target_soc'])) : '' ?>"
              data-power="<?= $dv['power_kw'] !== null ? e(number_format((float)$dv['power_kw'],1,'.','')) : '' ?>"
              data-sleep-age="<?= e((string)($dv['sleep_age'] ?? '')) ?>"
              data-trip-distance="<?= $dv['trip_distance_km'] !== null ? e(number_format((float)$dv['trip_distance_km'],1,'.','')) : '' ?>"
              data-temperature="<?= $dv['outside_temp_c'] !== null ? e(number_format((float)$dv['outside_temp_c'],1,'.','')) : '' ?>"
              data-locked="<?= $dv['locked'] === null ? '' : ($dv['locked'] ? '1' : '0') ?>"
              data-updated="<?= e((string)$dv['updated']) ?>"
            >
              <button
                type="button"
                class="dashboard-favorite-star"
                data-dashboard-favorite
                aria-label="<?= $index === 0 ? 'Lieblingsfahrzeug' : 'Als Lieblingsfahrzeug markieren' ?>"
                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                title="Lieblingsfahrzeug"
              >★</button>

              <div class="dashboard-vehicle-card-copy">
                <strong><?= e((string)$dv['name']) ?></strong>
                <small><?= e((string)$dv['model']) ?></small>
              </div>

              <a class="dashboard-vehicle-thumb dashboard-thumb-link" href="vehicle.php?id=<?= (int)$dv['id'] ?>" title="Fahrzeugdetails öffnen" aria-label="<?= e((string)$dv['name']) ?> – Fahrzeugdetails öffnen">
                <?php $renderDashboardCar('dashboard-card-car', (string)$dv['model_key']); ?>
              </a>

              <div class="dashboard-vehicle-card-bottom">
                <div class="dashboard-mini-battery">
                  <span>▯</span>
                  <strong><?= $soc !== null ? number_format((float)$soc,0,',','.') . ' %' : '–' ?></strong>
                  <i><b style="width:<?= number_format($socWidth,1,'.','') ?>%"></b></i>
                </div>
                <div class="dashboard-card-state <?= e($cardStatusClass) ?>">
                  <strong>
                    <?= match ($dv['mode']) {
                        'charging' => '⚡',
                        'driving' => '➤',
                        'sleeping' => '☾',
                        'online' => '●',
                        default => '○',
                    } ?>
                    <?= e((string)$dv['status']) ?>
                  </strong>
                  <small><?= e((string)$dv['status_detail']) ?></small>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if ($dashboardPrimary): ?>
          <svg class="dashboard-showroom-symbols" aria-hidden="true" width="0" height="0" focusable="false" xmlns="http://www.w3.org/2000/svg">
            <symbol id="ds-icon-battery" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="18" rx="2"/><path d="M10 2h4M9 12h6"/></symbol>
            <symbol id="ds-icon-road" viewBox="0 0 24 24"><path d="M7 2 3 22m14-20 4 20M12 2v4m0 4v4m0 4v4"/></symbol>
            <symbol id="ds-icon-moon" viewBox="0 0 24 24"><path d="M20.8 13A9 9 0 0 1 11 3.2a9 9 0 1 0 9.8 9.8Z"/></symbol>
            <symbol id="ds-icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>
            <symbol id="ds-icon-thermometer" viewBox="0 0 24 24"><path d="M10 14V5a3 3 0 0 1 6 0v9a5 5 0 1 1-6 0Z"/><path d="M13 9v8"/></symbol>
            <symbol id="ds-icon-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M8 15h4"/></symbol>
            <symbol id="ds-icon-bolt" viewBox="0 0 24 24"><path d="m13 2-9 11h7l-1 9 10-12h-7V2Z"/></symbol>
            <symbol id="ds-icon-lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
            <symbol id="ds-icon-gauge" viewBox="0 0 24 24"><path d="M5 18a9 9 0 1 1 14 0M12 13l4-4"/></symbol>
          </svg>
          <section class="dashboard-hero mode-<?= e((string)$dashboardPrimary['mode']) ?>" data-dashboard-hero>
            <div class="dashboard-hero-backdrop" aria-hidden="true">
              <span class="dashboard-hero-mountain mountain-a"></span>
              <span class="dashboard-hero-mountain mountain-b"></span>
              <span class="dashboard-hero-grid"></span>
              <span class="dashboard-hero-fog fog-a"></span>
              <span class="dashboard-hero-fog fog-b"></span>
              <span class="dashboard-energy-line line-a"></span>
              <span class="dashboard-energy-line line-b"></span>
              <span class="dashboard-energy-line line-c"></span>
            </div>

            <div class="dashboard-hero-copy">
              <span class="kicker" data-hero-kicker>Favoriten-Fahrzeug</span>
              <h2><strong data-hero-name><?= e((string)$dashboardPrimary['name']) ?></strong> <span data-hero-verb><?= match ($dashboardPrimary['mode']) {
                'charging' => 'lädt gerade.',
                'driving' => 'ist unterwegs.',
                'sleeping' => 'schläft aktuell.',
                'online' => 'ist wach.',
                default => 'ist offline.',
              } ?></span></h2>
              <p data-hero-lead><?= match ($dashboardPrimary['mode']) {
                'charging' => 'Das Fahrzeug lädt. TrakFog bündelt SoC, Reichweite, Ladelimit und die wichtigsten Live-Daten an einer Stelle.',
                'driving' => 'Das Fahrzeug ist unterwegs. Die Bühne wird dynamischer und zeigt den aktuell aktiven Fahrzustand.',
                'sleeping' => 'Das Fahrzeug ruht. Die Szene bleibt bewusst dunkel und ruhig, bis neue Fahrzeugdaten eintreffen.',
                'online' => 'Das Fahrzeug ist wach und bereit. Aktuelle Werte stehen für LiveView und Auswertungen bereit.',
                default => 'Aktuell kommen keine frischen Fahrzeugdaten. Der letzte bekannte Stand bleibt sichtbar.',
              } ?></p>

              <div class="dashboard-hero-metrics">
                <div>
                  <span class="dashboard-metric-icon"><svg aria-hidden="true"><use href="#ds-icon-battery"></use></svg></span>
                  <p><strong data-hero-metric-a><?= $dashboardPrimary['soc'] !== null ? number_format((float)$dashboardPrimary['soc'],0,',','.') . ' %' : '–' ?></strong><small data-hero-label-a>Batteriestand</small></p>
                </div>
                <div>
                  <span class="dashboard-metric-icon"><svg aria-hidden="true"><use href="#ds-icon-road"></use></svg></span>
                  <p><strong data-hero-metric-b><?= $dashboardPrimary['range_km'] !== null ? number_format((float)$dashboardPrimary['range_km'],0,',','.') . ' km' : '–' ?></strong><small data-hero-label-b>Geschätzte Reichweite</small></p>
                </div>
                <div>
                  <span class="dashboard-metric-icon"><svg aria-hidden="true"><use href="#ds-icon-moon"></use></svg></span>
                  <p><strong data-hero-metric-c><?= $dashboardPrimary['target_soc'] !== null ? number_format((float)$dashboardPrimary['target_soc'],0,',','.') . ' %' : e((string)$dashboardPrimary['status']) ?></strong><small data-hero-label-c><?= $dashboardPrimary['target_soc'] !== null ? 'Ladelimit' : 'Fahrzeugstatus' ?></small></p>
                </div>
                <div>
                  <span class="dashboard-metric-icon" data-hero-icon-d><svg aria-hidden="true"><use href="#ds-icon-<?= e(match ($dashboardPrimary['mode']) {
                      'charging' => 'bolt',
                      'driving' => 'gauge',
                      'sleeping' => 'clock',
                      'online' => 'lock',
                      default => 'moon',
                  }) ?>"></use></svg></span>
                  <p><strong data-hero-metric-d><?= $dashboardPrimary['power_kw'] !== null ? number_format((float)$dashboardPrimary['power_kw'],1,',','.') . ' kW' : e((string)$dashboardPrimary['status_detail']) ?></strong><small data-hero-label-d><?= $dashboardPrimary['power_kw'] !== null ? 'Aktuelle Ladeleistung' : 'Statusdetail' ?></small></p>
                </div>
                <div>
                  <span class="dashboard-metric-icon"><svg aria-hidden="true"><use href="#ds-icon-thermometer"></use></svg></span>
                  <p><strong data-hero-metric-e><?= $dashboardPrimary['outside_temp_c'] !== null ? number_format((float)$dashboardPrimary['outside_temp_c'],1,',','.') . ' °C' : '–' ?></strong><small data-hero-label-e>Außentemperatur</small></p>
                </div>
                <div>
                  <span class="dashboard-metric-icon"><svg aria-hidden="true"><use href="#ds-icon-calendar"></use></svg></span>
                  <p><strong data-hero-metric-f><?= e((string)$dashboardPrimary['updated']) ?></strong><small data-hero-label-f>Letzte Daten</small></p>
                </div>
              </div>

              <div class="dashboard-hero-actions">
                <a class="btn btn-primary" data-hero-details href="vehicle.php?id=<?= (int)$dashboardPrimary['id'] ?>">Auf einen Blick →</a>
                <a class="btn btn-ghost" href="live.php" target="_blank" rel="noopener">▣ LiveView öffnen</a>
              </div>
              <div class="lg-motion-settings" role="group" aria-label="Animationsintensität" data-lg-motion-settings>
                <span class="lg-motion-label">Animation</span>
                <button type="button" data-lg-motion="off" aria-pressed="false">Aus</button>
                <button type="button" data-lg-motion="subtle" aria-pressed="true">Dezent</button>
                <button type="button" data-lg-motion="full" aria-pressed="false">Vollgas</button>
              </div>
            </div>

            <div class="dashboard-hero-stage" aria-hidden="true">
              <div class="lg-atmosphere" aria-hidden="true">
                <span class="lg-horizon"></span><span class="lg-lightwash"></span>
                <span class="lg-haze"></span><span class="lg-ground-halo"></span>
                <span class="lg-ground-ring"></span><span class="lg-speedlines"></span>
                <i class="lg-point one"></i><i class="lg-point two"></i><i class="lg-point three"></i>
              </div>
              <div class="dashboard-secondary-car secondary-1" data-secondary-car="1" hidden><?php $renderDashboardCar('dashboard-scene-car', (string)($dashboardPrimary['model_key'] ?? 'y')); ?></div>
               <div class="dashboard-secondary-car secondary-2" data-secondary-car="2" hidden><?php $renderDashboardCar('dashboard-scene-car', (string)($dashboardPrimary['model_key'] ?? 'y')); ?></div>
               <?php for ($stackIndex = 3; $stackIndex <= 6; $stackIndex++): ?>
               <div class="dashboard-secondary-car secondary-<?= $stackIndex ?>" data-secondary-car="<?= $stackIndex ?>" hidden>
                 <?php $renderDashboardCar('dashboard-scene-car', (string)($dashboardPrimary['model_key'] ?? 'y')); ?>
               </div>
             <?php endfor; ?>
             <div class="dashboard-primary-car vehicle-tone-<?= e((string)$dashboardPrimary['tone']) ?>" data-primary-car>
                <span class="dashboard-wheel-contact" aria-hidden="true"></span>
                <span class="dashboard-chassis-contact" aria-hidden="true"></span>
                <span class="dashboard-car-aura"></span>
                <?php $renderDashboardCar('dashboard-scene-car', (string)($dashboardPrimary['model_key'] ?? 'y')); ?>
                <span class="dashboard-charge-port"></span>
                <span class="dashboard-charge-cable"></span>
              </div>
              <div class="dashboard-stage-floor"></div>
            </div>

            <aside class="dashboard-hero-rail">
              <div class="dashboard-dataflow">
                <span class="small">Datenfluss</span>
                <strong><i></i><b data-hero-flow><?= $dashboardPrimary['mode'] === 'sleeping' ? 'Ruhemodus' : ($dashboardPrimary['mode'] === 'offline' ? 'wartet auf Daten' : 'Aktiv im Hintergrund') ?></b></strong>
                <div class="dashboard-data-wave" aria-hidden="true"><i></i><i></i><i></i></div>
                <ul>
                  <li><span>Fahrzeugdaten</span><b data-flow-status>OK</b></li>
                  <li><span>Standort</span><b data-flow-status>OK</b></li>
                  <li><span>Ladezustand</span><b data-flow-status>OK</b></li>
                  <li><span>Umgebungsdaten</span><b data-flow-status>OK</b></li>
                </ul>
              </div>

              <div class="dashboard-hero-note">
                <span>ⓘ</span>
                <div><strong data-hero-note-title>Alles im Blick.</strong><small data-hero-note-copy>TrakFog zeigt hier immer das ausgewählte Fahrzeug.</small></div>
              </div>
            </aside>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php render_footer(); ?>
