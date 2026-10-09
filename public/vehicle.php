<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$id = (int)($_GET['id'] ?? $_POST['vehicle_id'] ?? 0);
$message = $error = $warning = null;

$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    ErrorPage::render(404, AppLogger::requestId(), 'Fahrzeug nicht gefunden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            if (($_POST['action'] ?? '') === 'save_vehicle_art') {
                $configForChoice = json_decode((string)($vehicle['raw_json'] ?? ''), true) ?: [];
                $detailForChoice = json_decode((string)($vehicle['detail_raw_json'] ?? ''), true) ?: [];
                $modelForChoice = (string)(
                    $configForChoice['vehicle_config']['car_type']
                    ?? $detailForChoice['vehicle_config']['car_type']
                    ?? ''
                );
                $familyForChoice=TeslaVehicleArt::family($modelForChoice,(string)($vehicle['model_name'] ?? ''));
                if ($familyForChoice===null) {
                    $familyForChoice=TeslaVehicleArt::vinInfo((string)($vehicle['vin'] ?? ''))['model'];
                }
                TeslaVehicleArt::saveOverride(
                    $pdo,$id,(string)($_POST['vehicle_art_variant'] ?? ''),$familyForChoice
                );
                $message='Fahrzeugdarstellung gespeichert – ohne zusätzliche Tesla-Anfrage.';
            } else {
                $syncResult = TeslaService::syncVehicleData($pdo, $config, $id);
            $fetchMode = (string)($syncResult['_fetch_mode'] ?? 'full');
            if ($fetchMode === 'full') {
                $message = 'Frische Fahrzeugdaten wurden gespeichert.';
            } elseif ($fetchMode === 'without_location') {
                $warning = 'Frische Fahrzeugdaten wurden gespeichert. Tesla hat den geschützten Standortteil dieses Abrufs blockiert; der zuletzt gespeicherte Standort bleibt erhalten.';
            } else {
                $warning = 'Frische Fahrzeugdaten wurden gespeichert. Tesla hat nur einen reduzierten Detailabruf erlaubt; vorhandene Werte bleiben erhalten, wenn einzelne Bereiche fehlen.';
            }

                $stmt->execute([$id]);
                $vehicle = $stmt->fetch();
            }
        } catch (TeslaApiException $e) {
            if (in_array($e->statusCode, [408, 429], true)) {
                $warning = $e->getMessage() . ' Die zuletzt bekannten Werte bleiben sichtbar.';
            } else {
                $error = $e->getMessage();
            }
        } catch (Throwable $e) {
            $error = report_exception($e, 'Fahrzeugdaten konnten nicht aktualisiert werden.');
        }
    }
}

$raw = json_decode((string)($vehicle['raw_json'] ?? ''), true) ?: [];

$snap = $pdo->prepare(
    'SELECT recorded_at,battery_level,range_km,odometer_km,speed_kmh,charging_state,power_kw
     FROM vehicle_snapshots
     WHERE vehicle_id=?
     ORDER BY recorded_at DESC
     LIMIT 20'
);
$snap->execute([$id]);
$snapshots = $snap->fetchAll();
$latestSnapshot = $snapshots[0] ?? null;

$latestStream = null;
try {
    $streamStmt = $pdo->prepare(
        'SELECT recorded_at,speed_kmh,odometer_km,soc,power_kw,shift_state,range_km,latitude,longitude,heading
         FROM vehicle_stream_samples
         WHERE vehicle_id=?
         ORDER BY id DESC
         LIMIT 1'
    );
    $streamStmt->execute([$id]);
    $latestStream = $streamStmt->fetch() ?: null;
} catch (Throwable) {
    $latestStream = null;
}

$activeTrip = null;
try {
    $tripStmt = $pdo->prepare(
        'SELECT id,started_at,distance_km,max_speed_kmh,sample_count
         FROM trips
         WHERE vehicle_id=? AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $tripStmt->execute([$id]);
    $activeTrip = $tripStmt->fetch() ?: null;
} catch (Throwable) {
    $activeTrip = null;
}

$activeCharge = null;
try {
    $chargeStmt = $pdo->prepare(
        'SELECT id,started_at,energy_added_kwh,start_battery_percent,end_battery_percent,max_power_kw,last_state,sample_count
         FROM charges
         WHERE vehicle_id=? AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $chargeStmt->execute([$id]);
    $activeCharge = $chargeStmt->fetch() ?: null;
} catch (Throwable) {
    $activeCharge = null;
}

$charge = is_array($raw['charge_state'] ?? null) ? $raw['charge_state'] : [];
$climate = is_array($raw['climate_state'] ?? null) ? $raw['climate_state'] : [];
$drive = is_array($raw['drive_state'] ?? null) ? $raw['drive_state'] : [];
$state = is_array($raw['vehicle_state'] ?? null) ? $raw['vehicle_state'] : [];
$closures = is_array($raw['closures_state'] ?? null) ? $raw['closures_state'] : [];
$configData = is_array($raw['vehicle_config'] ?? null) ? $raw['vehicle_config'] : [];
$detailArtRaw=json_decode((string)($vehicle['detail_raw_json'] ?? ''),true) ?: [];
$detailArtConfig=is_array($detailArtRaw['vehicle_config'] ?? null) ? $detailArtRaw['vehicle_config'] : [];
$artCarType=(string)($configData['car_type'] ?? $detailArtConfig['car_type'] ?? '');
$artOverride=TeslaVehicleArt::override($pdo,$id);
$artResolved=TeslaVehicleArt::resolve(
    $artCarType,(string)($vehicle['model_name'] ?? ''),(string)($vehicle['vin'] ?? ''),$artOverride
);
$artChoices=TeslaVehicleArt::choices($artResolved['family']);

$gui = is_array($raw['gui_settings'] ?? null) ? $raw['gui_settings'] : [];
$softwareUpdate = is_array($state['software_update'] ?? null) ? $state['software_update'] : [];

$currentSoftware = trim((string)($state['car_version'] ?? ''));
$softwareUpdateStatus = strtolower(trim((string)($softwareUpdate['status'] ?? '')));
$softwareUpdateVersion = trim((string)($softwareUpdate['version'] ?? ''));
$softwareUpdateActive = !in_array($softwareUpdateStatus, ['', 'idle', 'none', 'unknown'], true) || $softwareUpdateVersion !== '';
$softwareProgress = is_numeric($softwareUpdate['install_perc'] ?? null)
    ? (float)$softwareUpdate['install_perc']
    : (is_numeric($softwareUpdate['download_perc'] ?? null) ? (float)$softwareUpdate['download_perc'] : null);

$tpms = [
    'VL' => $state['tpms_pressure_fl'] ?? null,
    'VR' => $state['tpms_pressure_fr'] ?? null,
    'HL' => $state['tpms_pressure_rl'] ?? null,
    'HR' => $state['tpms_pressure_rr'] ?? null,
];
$tpmsWarnings = [
    'VL' => (bool)($state['tpms_hard_warning_fl'] ?? $state['tpms_soft_warning_fl'] ?? false),
    'VR' => (bool)($state['tpms_hard_warning_fr'] ?? $state['tpms_soft_warning_fr'] ?? false),
    'HL' => (bool)($state['tpms_hard_warning_rl'] ?? $state['tpms_soft_warning_rl'] ?? false),
    'HR' => (bool)($state['tpms_hard_warning_rr'] ?? $state['tpms_soft_warning_rr'] ?? false),
];

$routeDestination = trim((string)($drive['active_route_destination'] ?? ''));
$routeMiles = is_numeric($drive['active_route_miles_to_arrival'] ?? null) ? (float)$drive['active_route_miles_to_arrival'] : null;
$routeKm = $routeMiles !== null ? round($routeMiles * 1.609344, 1) : null;
$routeMinutes = is_numeric($drive['active_route_minutes_to_arrival'] ?? null) ? (int)$drive['active_route_minutes_to_arrival'] : null;
$routeDelay = is_numeric($drive['active_route_traffic_minutes_delay'] ?? null) ? (int)$drive['active_route_traffic_minutes_delay'] : null;

$extraRow = null;
try {
    $extraStmt = $pdo->prepare('SELECT * FROM tesla_vehicle_extras WHERE vehicle_id=? LIMIT 1');
    $extraStmt->execute([$id]);
    $extraRow = $extraStmt->fetch() ?: null;
} catch (Throwable) {
    $extraRow = null;
}
$decodeExtra = static function (mixed $value): mixed {
    if (!is_string($value) || trim($value) === '') return null;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
};
$vehicleExtras = [
    'nearby_charging' => $decodeExtra($extraRow['nearby_charging_json'] ?? null),
    'recent_alerts' => $decodeExtra($extraRow['recent_alerts_json'] ?? null),
    'release_notes' => $decodeExtra($extraRow['release_notes_json'] ?? null),
    'service_data' => $decodeExtra($extraRow['service_data_json'] ?? null),
];

$flattenTesla = static function (mixed $value, string $prefix = '') use (&$flattenTesla): array {
    $result = [];
    if (!is_array($value)) {
        if ($prefix !== '') $result[$prefix] = $value;
        return $result;
    }
    foreach ($value as $key => $child) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        if (is_array($child)) $result += $flattenTesla($child, $path);
        else $result[$path] = $child;
    }
    return $result;
};
$deepTeslaFields = $flattenTesla($raw);
ksort($deepTeslaFields);
$formatTeslaValue = static function (mixed $value): string {
    if ($value === null) return 'null';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_float($value)) return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    return (string)$value;
};

$get = static function (array $sources, string $key, mixed $default = null): mixed {
    foreach ($sources as $source) {
        if (is_array($source) && array_key_exists($key, $source)) {
            return $source[$key];
        }
    }
    return $default;
};

$fmtNumber = static fn(mixed $value, int $decimals = 0, string $suffix = ''): string =>
    is_numeric($value) ? number_format((float)$value, $decimals, ',', '.') . $suffix : '–';

$fmtBool = static function (mixed $value): string {
    if ($value === null) return '–';
    return (bool)$value ? 'Ja' : 'Nein';
};

$fmtLocal = static function (?string $value, bool $seconds = false): string {
    if (!$value) return '–';
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

$doorKeys = [
    'df' => 'Fahrertür',
    'pf' => 'Beifahrertür',
    'dr' => 'Tür hinten links',
    'pr' => 'Tür hinten rechts',
    'ft' => 'Frunk',
    'rt' => 'Kofferraum',
];

$openings = [];
foreach ($doorKeys as $key => $label) {
    $value = $get([$closures, $state], $key);
    if ($value !== null) {
        $openings[$label] = ((int)$value === 0) ? 'geschlossen' : 'offen';
    }
}

$windows = [];
foreach (['fd_window' => 'Fenster vorne links','fp_window' => 'Fenster vorne rechts','rd_window' => 'Fenster hinten links','rp_window' => 'Fenster hinten rechts'] as $key => $label) {
    $value = $get([$closures, $state], $key);
    if ($value !== null) {
        $windows[$label] = ((int)$value === 0) ? 'geschlossen' : 'offen';
    }
}

$vehicleState = strtolower((string)($vehicle['state'] ?? 'unknown'));
$stateClass = $vehicleState === 'online' ? 'ok' : (($vehicleState === 'offline' || $vehicleState === 'asleep') ? 'sleep' : '');
$chargingState = (string)($charge['charging_state'] ?? $latestSnapshot['charging_state'] ?? '');
$streamAt = $latestStream['recorded_at'] ?? null;
$streamTs = $streamAt ? strtotime((string)$streamAt . ' UTC') : false;
$streamFresh = $streamTs !== false && (time() - $streamTs) <= 120;
$gear = $streamFresh && !empty($latestStream['shift_state'])
    ? (string)$latestStream['shift_state']
    : ($drive['shift_state'] ?? null);
$locked = $state['locked'] ?? null;
$sentry = $state['sentry_mode'] ?? null;
$climateOn = $climate['is_climate_on'] ?? null;
$insideTemp = $climate['inside_temp'] ?? null;
$outsideTemp = $climate['outside_temp'] ?? null;
$chargerPower = $charge['charger_power'] ?? $latestSnapshot['power_kw'] ?? null;
$chargePort = $charge['charge_port_door_open'] ?? null;
$plugged = $charge['charging_state'] ?? null;
$usableBattery = $charge['usable_battery_level'] ?? $vehicle['usable_battery_level'];
$ratedRange = $vehicle['rated_range_km'];
$idealRange = $vehicle['ideal_range_km'];
$speed = $streamFresh && $latestStream['speed_kmh'] !== null
    ? $latestStream['speed_kmh']
    : $vehicle['speed_kmh'];
$lat = $streamFresh && $latestStream['latitude'] !== null
    ? $latestStream['latitude']
    : $vehicle['latitude'];
$lon = $streamFresh && $latestStream['longitude'] !== null
    ? $latestStream['longitude']
    : $vehicle['longitude'];
$dataAt = $latestSnapshot['recorded_at'] ?? null;

render_header(($vehicle['display_name'] ?: 'Fahrzeug') . ' · Drive', 'drive');
?>
<div class="page wrap vehicle-overview" data-live-region="page-vehicle" data-live-poll="5000">
  <div class="page-head">
    <div>
      <span class="kicker">Fahrzeug</span>
      <div class="split-actions vehicle-title-line">
        <h1><?= e($vehicle['display_name'] ?: 'Tesla') ?></h1>
        <span class="pill <?= e($stateClass) ?>"><?= ($vehicleState === 'offline' || $vehicleState === 'asleep') ? '☾ ' : '● ' ?><?= e($vehicleState ?: 'unknown') ?></span>
      </div>
      <p>
        <?= e($vehicle['vin'] ? 'VIN ••••••' . substr((string)$vehicle['vin'], -6) : 'VIN noch nicht geladen') ?>
        · Detaildaten <?= e($fmtLocal($dataAt, true)) ?>
      </p>
    </div>

    <div class="split-actions">
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
        <button class="btn btn-primary" type="submit">↻ Frische Daten abrufen</button>
      </form>
      <a class="btn btn-ghost" href="drive.php">← Drive</a>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($warning): ?><div class="alert alert-warn">😴 <?= e($warning) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <?php if ($softwareUpdateActive): ?>
    <div class="alert alert-ok tesla-software-alert">
      <span>⬆</span>
      <div><strong>Tesla Software-Update<?= $softwareUpdateVersion !== '' ? ' · ' . e($softwareUpdateVersion) : '' ?></strong>
      <small>Status <?= e(strtoupper($softwareUpdateStatus !== '' ? $softwareUpdateStatus : 'verfügbar')) ?><?= $currentSoftware !== '' ? ' · installiert ' . e($currentSoftware) : '' ?><?= $softwareProgress !== null ? ' · ' . number_format($softwareProgress,0,',','.') . ' %' : '' ?></small></div>
    </div>
  <?php endif; ?>

  <?php if (!$dataAt): ?>
    <div class="alert alert-warn">
      <strong>👋 Fahrzeug erkannt, aber noch keine Detaildaten gespeichert.</strong><br>
      Sobald <?= e($vehicle['display_name'] ?: 'der Tesla') ?> wach ist, einmal „Frische Daten abrufen“ drücken.
    </div>
  <?php endif; ?>

  <section class="vehicle-hero panel">
    <div class="vehicle-hero-art" aria-hidden="true">
      <div class="vehicle-silhouette">🚗</div>
      <div class="vehicle-floor-glow"></div>
    </div>

    <div class="vehicle-hero-main">
      <div class="battery-ring" style="--battery:<?= max(0, min(100, (float)($vehicle['battery_level'] ?? 0))) ?>%">
        <div>
          <strong><?= $vehicle['battery_level'] !== null ? number_format((float)$vehicle['battery_level'], 0) . '%' : '–' ?></strong>
          <span>Akku</span>
        </div>
      </div>

      <div class="vehicle-primary-stats">
        <div><span>Reichweite</span><strong><?= $ratedRange !== null ? $fmtNumber($ratedRange, 0, ' km') : '–' ?></strong></div>
        <div><span>Kilometer</span><strong><?= $vehicle['odometer_km'] !== null ? $fmtNumber($vehicle['odometer_km'], 0, ' km') : '–' ?></strong></div>
        <div><span>Tempo</span><strong><?= $speed !== null ? $fmtNumber($speed, 0, ' km/h') : '–' ?></strong></div>
      </div>
    </div>

    <div class="vehicle-status-stack">
      <div class="status-card"><span>Gang</span><strong><?= e($gear ?: '–') ?></strong></div>
      <div class="status-card"><span>Verriegelt</span><strong><?= $locked === null ? '–' : ((bool)$locked ? '🔒 Ja' : '🔓 Nein') ?></strong></div>
      <div class="status-card"><span>Sentry</span><strong><?= $sentry === null ? '–' : ((bool)$sentry ? '● Aktiv' : 'Aus') ?></strong></div>
    </div>
  </section>

  <div class="grid-main vehicle-grid" style="margin-top:16px">
    <div style="display:grid;gap:16px">
      <section class="panel">
        <div class="panel-head">
          <h3>⚡ Batterie & Laden</h3>
          <span class="pill <?= $activeCharge ? 'ok' : (strcasecmp($chargingState, 'Charging') === 0 ? 'ok' : '') ?>"><?= $activeCharge ? '● Lädt · Session #' . (int)$activeCharge['id'] : e($chargingState ?: 'kein Detailstand') ?></span>
        </div>
        <div class="panel-body">
          <div class="vehicle-kpi-grid">
            <div class="vehicle-kpi"><span>Nutzbarer Akku</span><strong><?= $usableBattery !== null ? $fmtNumber($usableBattery, 0, '%') : '–' ?></strong></div>
            <div class="vehicle-kpi"><span>Rated Range</span><strong><?= $ratedRange !== null ? $fmtNumber($ratedRange, 0, ' km') : '–' ?></strong></div>
            <div class="vehicle-kpi"><span>Ideal Range</span><strong><?= $idealRange !== null ? $fmtNumber($idealRange, 0, ' km') : '–' ?></strong></div>
            <div class="vehicle-kpi"><span>Ladeleistung</span><strong><?= $chargerPower !== null ? $fmtNumber($chargerPower, 0, ' kW') : '–' ?></strong></div>
          </div>

          <div class="data-list" style="margin-top:14px">
            <div class="data-row"><span>Aktuelle Ladesession</span><b><?= $activeCharge ? number_format((float)($activeCharge['energy_added_kwh'] ?? 0), 2, ',', '.') . ' kWh · max. ' . number_format((float)($activeCharge['max_power_kw'] ?? 0), 1, ',', '.') . ' kW' : 'keine' ?></b></div>
            <div class="data-row"><span>Ladeanschluss</span><b><?= $chargePort === null ? '–' : ((bool)$chargePort ? 'offen' : 'geschlossen') ?></b></div>
            <div class="data-row"><span>Ladelimit</span><b><?= isset($charge['charge_limit_soc']) ? $fmtNumber($charge['charge_limit_soc'], 0, '%') : '–' ?></b></div>
            <div class="data-row"><span>Hinzugefügt</span><b><?= isset($charge['charge_energy_added']) ? $fmtNumber($charge['charge_energy_added'], 2, ' kWh') : '–' ?></b></div>
            <div class="data-row"><span>Restzeit</span><b><?= isset($charge['minutes_to_full_charge']) && is_numeric($charge['minutes_to_full_charge']) ? $fmtNumber($charge['minutes_to_full_charge'], 0, ' min') : '–' ?></b></div>
            <div class="data-row"><span>Spannung / Strom</span><b><?= isset($charge['charger_voltage']) || isset($charge['charger_actual_current']) ? $fmtNumber($charge['charger_voltage'] ?? null,0,' V') . ' · ' . $fmtNumber($charge['charger_actual_current'] ?? null,0,' A') : '–' ?></b></div>
            <div class="data-row"><span>Phasen</span><b><?= isset($charge['charger_phases']) ? e((string)$charge['charger_phases']) : '–' ?></b></div>
            <div class="data-row"><span>Fast Charger</span><b><?= e((string)($charge['fast_charger_type'] ?? ($charge['fast_charger_brand'] ?? '–'))) ?></b></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h3>🌡️ Klima</h3>
          <span class="pill <?= $climateOn ? 'ok' : '' ?>"><?= $climateOn === null ? 'keine Daten' : ($climateOn ? 'Klima an' : 'Klima aus') ?></span>
        </div>
        <div class="panel-body">
          <div class="vehicle-kpi-grid">
            <div class="vehicle-kpi"><span>Innen</span><strong><?= $insideTemp !== null ? $fmtNumber($insideTemp, 1, ' °C') : '–' ?></strong></div>
            <div class="vehicle-kpi"><span>Außen</span><strong><?= $outsideTemp !== null ? $fmtNumber($outsideTemp, 1, ' °C') : '–' ?></strong></div>
            <div class="vehicle-kpi"><span>Fahrer-Soll</span><strong><?= isset($climate['driver_temp_setting']) ? $fmtNumber($climate['driver_temp_setting'], 1, ' °C') : '–' ?></strong></div>
            <div class="vehicle-kpi"><span>Beifahrer-Soll</span><strong><?= isset($climate['passenger_temp_setting']) ? $fmtNumber($climate['passenger_temp_setting'], 1, ' °C') : '–' ?></strong></div>
          </div>
          <div class="data-list" style="margin-top:14px">
            <div class="data-row"><span>Batterieheizung</span><b><?= $fmtBool($climate['battery_heater'] ?? $climate['battery_heater_on'] ?? null) ?></b></div>
            <div class="data-row"><span>Vorheizen</span><b><?= $fmtBool($charge['preconditioning_enabled'] ?? $climate['preconditioning_enabled'] ?? null) ?></b></div>
            <div class="data-row"><span>Front-Defrost</span><b><?= $fmtBool($climate['is_front_defroster_on'] ?? null) ?></b></div>
            <div class="data-row"><span>Heck-Defrost</span><b><?= $fmtBool($climate['is_rear_defroster_on'] ?? null) ?></b></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h3>🚪 Fahrzeugstatus</h3>
          <span class="pill"><?= count($openings) + count($windows) ?> Zustände</span>
        </div>
        <div class="panel-body">
          <?php if (!$openings && !$windows && $locked === null): ?>
            <div class="empty">Noch keine Detaildaten zu Türen und Fenstern gespeichert.</div>
          <?php else: ?>
            <div class="closure-grid">
              <?php foreach ($openings as $label => $value): ?>
                <div class="closure-item <?= $value === 'geschlossen' ? 'closed' : 'open' ?>"><span><?= e($label) ?></span><strong><?= e($value) ?></strong></div>
              <?php endforeach; ?>
              <?php foreach ($windows as $label => $value): ?>
                <div class="closure-item <?= $value === 'geschlossen' ? 'closed' : 'open' ?>"><span><?= e($label) ?></span><strong><?= e($value) ?></strong></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

      <section class="panel">
        <div class="panel-head"><h3>🛡️ Sicherheit & Service</h3><span class="pill <?= array_filter($tpmsWarnings) ? 'warn' : 'ok' ?>"><?= array_filter($tpmsWarnings) ? 'Reifen prüfen' : 'Status' ?></span></div>
        <div class="panel-body">
          <div class="tesla-tpms-grid">
            <?php foreach ($tpms as $wheel => $pressure): ?>
              <div class="<?= !empty($tpmsWarnings[$wheel]) ? 'warn' : '' ?>"><span><?= e($wheel) ?></span><strong><?= is_numeric($pressure) ? number_format((float)$pressure,2,',','.') . ' bar' : '–' ?></strong></div>
            <?php endforeach; ?>
          </div>
          <div class="data-list" style="margin-top:14px">
            <div class="data-row"><span>Verriegelt</span><b><?= $locked === null ? '–' : ((bool)$locked ? 'Ja' : 'Nein') ?></b></div>
            <div class="data-row"><span>Sentry Mode</span><b><?= $sentry === null ? '–' : ((bool)$sentry ? 'Aktiv' : 'Aus') ?></b></div>
            <div class="data-row"><span>Service Mode</span><b><?= $fmtBool($state['service_mode'] ?? null) ?></b></div>
            <div class="data-row"><span>Valet Mode</span><b><?= $fmtBool($state['valet_mode'] ?? null) ?></b></div>
          </div>
        </div>
      </section>
    </div>

    <div style="display:grid;gap:16px;align-content:start">
      <section class="panel">
        <div class="panel-head"><h3>🧭 Fahrt & Position</h3><span class="pill <?= $activeTrip ? 'ok' : '' ?>"><?= $activeTrip ? '● Live-Fahrt #' . (int)$activeTrip['id'] : e($gear ?: '–') ?></span></div>
        <div class="panel-body">
          <div class="data-list">
            <div class="data-row"><span>Geschwindigkeit</span><b><?= $speed !== null ? $fmtNumber($speed, 0, ' km/h') : '–' ?></b></div>
            <div class="data-row"><span>Aktuelle Fahrt</span><b><?= $activeTrip ? number_format((float)($activeTrip['distance_km'] ?? 0), 1, ',', '.') . ' km · ' . number_format((int)($activeTrip['sample_count'] ?? 0), 0, ',', '.') . ' Pakete' : 'keine' ?></b></div>
            <div class="data-row"><span>Richtung</span><b><?= $vehicle['heading'] !== null ? $fmtNumber($vehicle['heading'], 0, '°') : '–' ?></b></div>
            <div class="data-row"><span>Breite</span><b class="mono"><?= $lat !== null ? e((string)$lat) : '–' ?></b></div>
            <div class="data-row"><span>Länge</span><b class="mono"><?= $lon !== null ? e((string)$lon) : '–' ?></b></div>
            <div class="data-row"><span>Stream-Datenstand</span><b><?= e($fmtLocal($streamAt, true)) ?></b></div>
            <div class="data-row"><span>Navi-Ziel</span><b><?= $routeDestination !== '' ? e($routeDestination) : '–' ?></b></div>
            <div class="data-row"><span>Bis Ziel</span><b><?= $routeKm !== null ? number_format($routeKm,1,',','.') . ' km' : '–' ?><?= $routeMinutes !== null ? ' · ' . number_format($routeMinutes,0,',','.') . ' min' : '' ?></b></div>
            <div class="data-row"><span>Verkehrsverzögerung</span><b><?= $routeDelay !== null ? number_format($routeDelay,0,',','.') . ' min' : '–' ?></b></div>
          </div>
          <?php if ($lat !== null && $lon !== null): ?>
            <a class="btn btn-ghost" style="margin-top:14px;width:100%" href="map.php?vehicle_id=<?= (int)$vehicle['id'] ?>&focus=vehicle">🗺️ Auf Karte öffnen</a>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>ℹ️ Fahrzeug</h3><span class="pill"><?= e($configData['car_type'] ?? 'Tesla') ?></span></div>
        <div class="panel-body">
          <div class="data-list">
            <div class="data-row"><span>Name</span><b><?= e($vehicle['display_name'] ?: 'Tesla') ?></b></div>
            <div class="data-row"><span>VIN</span><b><?= e($vehicle['vin'] ? '••••••' . substr((string)$vehicle['vin'], -6) : '–') ?></b></div>
            <div class="data-row"><span>Status</span><b><?= e($vehicleState ?: 'unknown') ?></b></div>
            <div class="data-row"><span>Datenstand</span><b><?= e($fmtLocal($dataAt, true)) ?></b></div>
            <div class="data-row"><span>TrakFog Engine</span><b>REST + Stream automatisch</b></div>
            <div class="data-row"><span>Tesla Software</span><b><?= e($currentSoftware !== '' ? $currentSoftware : '–') ?></b></div>
            <div class="data-row"><span>Modellkennung</span><b><?= e((string)($configData['car_type'] ?? '–')) ?></b></div>
            <div class="data-row"><span>Trim</span><b><?= e((string)($configData['trim_badging'] ?? '–')) ?></b></div>
            <div class="data-row"><span>Felgen</span><b><?= e((string)($configData['wheel_type'] ?? '–')) ?></b></div>
            <div class="data-row"><span>Farbe</span><b><?= e((string)($configData['exterior_color'] ?? '–')) ?></b></div>
            <div class="data-row"><span>Ladeport-Typ</span><b><?= e((string)($configData['charge_port_type'] ?? '–')) ?></b></div>
            <div class="data-row"><span>Einheiten</span><b><?= e((string)($gui['gui_distance_units'] ?? '–')) ?> · <?= e((string)($gui['gui_temperature_units'] ?? '–')) ?></b></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>📸 Letzte Snapshots</h3><span class="pill"><?= count($snapshots) ?></span></div>
        <div class="panel-body">
          <?php if (!$snapshots): ?>
            <div class="empty">Noch keine Detail-Snapshots.</div>
          <?php else: ?>
            <div class="snapshot-list">
              <?php foreach (array_slice($snapshots, 0, 8) as $s): ?>
                <div class="snapshot-item">
                  <span><?= e($fmtLocal($s['recorded_at'])) ?></span>
                  <strong><?= $s['battery_level'] !== null ? $fmtNumber($s['battery_level'], 0, '%') : '–' ?></strong>
                  <small><?= $s['range_km'] !== null ? $fmtNumber($s['range_km'], 0, ' km') : '–' ?></small>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>

  <section class="panel tesla-art-settings" aria-labelledby="vehicleArtHeading">
    <div class="panel-head">
      <div><h3 id="vehicleArtHeading">🚘 Fahrzeugdarstellung</h3><span class="small">Modellgeneration für Dashboard und LiveView · rein lokal</span></div>
      <span class="pill"><?= e($artResolved['source']) ?></span>
    </div>
    <div class="panel-body">
      <p class="small">Automatische Erkennung über Modellkennung und VIN-Modelljahr. Für Übergangsjahre (Model 3 2023 / Model Y 2025) wird nichts geraten. Eine manuelle Auswahl bleibt dauerhaft bestehen.</p>
      <div class="data-list" style="margin:12px 0">
        <div class="data-row"><span>Ermittelte Darstellung</span><b><?= e($artResolved['label'] ?? 'Generation unklar – bitte auswählen') ?></b></div>
        <div class="data-row"><span>VIN-Jahr (soweit bekannt)</span><b><?= e($artResolved['year']===null?'Nicht bestimmbar':(string)$artResolved['year']) ?></b></div>
        <div class="data-row"><span>Quelle</span><b><?= e($artResolved['source']) ?></b></div>
      </div>
      <form method="post" class="vehicle-art-form" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_vehicle_art">
        <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
        <label style="flex:1 1 240px;display:grid;gap:7px;font-size:12px">
          Fahrzeugoptik
          <select name="vehicle_art_variant" class="input" style="width:100%;min-height:40px" required>
            <?php foreach($artChoices as $choiceValue=>$choiceLabel): ?>
              <option value="<?= e($choiceValue) ?>"<?= $artOverride===$choiceValue?' selected':'' ?>><?= e($choiceLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Darstellung speichern</button>
      </form>
      <small class="small">Die VIN wird dafür nicht an externe Decoder übertragen. Keine Fahrzeug-Weckabfrage.</small>
    </div>
  </section>

  <section class="panel tesla-extra-panel">
    <div class="panel-head"><div><h3>🛰️ Tesla Zusatzdaten</h3><span class="small">Schonend gecacht · nahe Ladeorte, Alerts, Release Notes und Service</span></div><span class="pill"><?= $extraRow && !empty($extraRow['synced_at']) ? e($fmtLocal((string)$extraRow['synced_at'], true)) : 'wartet auf Online-Sync' ?></span></div>
    <div class="panel-body tesla-extra-grid">
      <?php $extraLabels = ['nearby_charging'=>['⚡','Nahe Ladeorte'],'recent_alerts'=>['⚠️','Letzte Alerts'],'release_notes'=>['📝','Release Notes'],'service_data'=>['🛠️','Service']]; ?>
      <?php foreach ($extraLabels as $extraKey => [$extraIcon,$extraLabel]): $payload=$vehicleExtras[$extraKey]; ?>
        <details class="tesla-extra-card"><summary><span><?= $extraIcon ?></span><strong><?= e($extraLabel) ?></strong><small><?= $payload === null ? 'noch keine Daten' : 'gespeichert' ?></small></summary>
          <?php if ($payload === null): ?><div class="empty">Noch keine Daten oder für diesen Zugang nicht freigegeben.</div>
          <?php else: ?><pre><?= e((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
        </details>
      <?php endforeach; ?>
      <?php if ($extraRow && !empty($extraRow['last_error'])): ?><div class="alert alert-warn tesla-extra-error">Einzelne optionale Tesla-Endpunkte waren zuletzt nicht verfügbar. Die normale Telemetrie läuft weiter.</div><?php endif; ?>
    </div>
  </section>

  <section class="panel tesla-deep-data-panel">
    <div class="panel-head"><div><h3>🧬 Tesla Deep Data</h3><span class="small">Alle Felder des letzten Tesla-Datensatzes – automatisch auch für neue Felder.</span></div><span class="pill"><?= number_format(count($deepTeslaFields),0,',','.') ?> Felder</span></div>
    <div class="panel-body">
      <?php if (!$deepTeslaFields): ?><div class="empty">Noch kein vollständiger Tesla-Datensatz gespeichert.</div>
      <?php else: ?><details class="tesla-deep-details"><summary>Alle Tesla-Felder anzeigen</summary><div class="tesla-deep-grid">
        <?php foreach ($deepTeslaFields as $fieldPath=>$fieldValue): ?><div><span><?= e((string)$fieldPath) ?></span><b><?= e($formatTeslaValue($fieldValue)) ?></b></div><?php endforeach; ?>
      </div></details><?php endif; ?>
    </div>
  </section>
</div>
<?php render_footer(); ?>
