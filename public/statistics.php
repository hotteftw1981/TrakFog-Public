<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$vehicles = $pdo->query(
    "SELECT id, display_name, vin, state
     FROM vehicles
     ORDER BY display_name, id"
)->fetchAll();

$vehicleIds = array_map(static fn(array $row): int => (int)$row['id'], $vehicles);
$selectedVehicleId = (int)($_GET['vehicle_id'] ?? ($vehicleIds[0] ?? 0));
if ($selectedVehicleId > 0 && !in_array($selectedVehicleId, $vehicleIds, true)) {
    $selectedVehicleId = $vehicleIds[0] ?? 0;
}

$ranges = [
    '24h' => ['label' => '24 Stunden', 'seconds' => 86400, 'bucket' => 120, 'bucket_label' => '2-Minuten-Mittel'],
    '7d' => ['label' => '7 Tage', 'seconds' => 604800, 'bucket' => 900, 'bucket_label' => '15-Minuten-Mittel'],
    '30d' => ['label' => '30 Tage', 'seconds' => 2592000, 'bucket' => 3600, 'bucket_label' => 'Stundenmittel'],
];

$rangeKey = (string)($_GET['range'] ?? '24h');
if (!isset($ranges[$rangeKey])) {
    $rangeKey = '24h';
}
$range = $ranges[$rangeKey];
$sinceUtc = gmdate('Y-m-d H:i:s', time() - (int)$range['seconds']);
$bucketSeconds = (int)$range['bucket'];

$selectedVehicle = null;
foreach ($vehicles as $vehicleRow) {
    if ((int)$vehicleRow['id'] === $selectedVehicleId) {
        $selectedVehicle = $vehicleRow;
        break;
    }
}

$snapshotCount = 0;
$streamCount = 0;
$tripCount = 0;
$chargeCount = 0;
$distance = 0.0;
$tripEnergy = 0.0;
$chargeEnergy = 0.0;
$chargeCost = 0.0;
$confirmedCostCharges = 0;
$costedChargeEnergy = 0.0;
$lastSnapshotAt = null;
$lastStreamAt = null;
$snapshotRows = [];
$streamRows = [];
$analyticsWarnings = [];

if ($selectedVehicleId > 0) {
    try {
        $countStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM vehicle_snapshots WHERE vehicle_id=?) AS snapshots,
                (SELECT COUNT(*) FROM vehicle_stream_samples WHERE vehicle_id=?) AS stream_samples,
                (SELECT COUNT(*) FROM trips WHERE vehicle_id=?) AS trips,
                (SELECT COUNT(*) FROM charges WHERE vehicle_id=?) AS charges,
                (SELECT COALESCE(SUM(distance_km),0) FROM trips WHERE vehicle_id=?) AS distance_km,
                (SELECT COALESCE(SUM(energy_kwh),0) FROM trips WHERE vehicle_id=?) AS trip_energy_kwh,
                (SELECT COALESCE(SUM(energy_added_kwh),0) FROM charges WHERE vehicle_id=?) AS charge_energy_kwh,
                (SELECT MAX(recorded_at) FROM vehicle_snapshots WHERE vehicle_id=?) AS last_snapshot_at,
                (SELECT MAX(recorded_at) FROM vehicle_stream_samples WHERE vehicle_id=?) AS last_stream_at"
        );
        $countStmt->execute(array_fill(0, 9, $selectedVehicleId));
        $totals = $countStmt->fetch() ?: [];

        $snapshotCount = (int)($totals['snapshots'] ?? 0);
        $streamCount = (int)($totals['stream_samples'] ?? 0);
        $tripCount = (int)($totals['trips'] ?? 0);
        $chargeCount = (int)($totals['charges'] ?? 0);
        $distance = (float)($totals['distance_km'] ?? 0);
        $tripEnergy = (float)($totals['trip_energy_kwh'] ?? 0);
        $chargeEnergy = (float)($totals['charge_energy_kwh'] ?? 0);
        $lastSnapshotAt = $totals['last_snapshot_at'] ?? null;
        $lastStreamAt = $totals['last_stream_at'] ?? null;
    } catch (Throwable $e) {
        $analyticsWarnings[] = report_exception($e, 'Grunddaten der Statistik konnten nicht vollständig geladen werden.');
    }

    try {
        $costStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(cost_amount),0) AS charge_cost,
                COUNT(cost_amount) AS confirmed_cost_charges,
                COALESCE(SUM(CASE WHEN cost_amount IS NOT NULL THEN energy_added_kwh ELSE 0 END),0) AS costed_charge_energy
             FROM charges
             WHERE vehicle_id=?"
        );
        $costStmt->execute([$selectedVehicleId]);
        $costTotals = $costStmt->fetch() ?: [];
        $chargeCost = (float)($costTotals['charge_cost'] ?? 0);
        $confirmedCostCharges = (int)($costTotals['confirmed_cost_charges'] ?? 0);
        $costedChargeEnergy = (float)($costTotals['costed_charge_energy'] ?? 0);
    } catch (Throwable $e) {
        $analyticsWarnings[] = report_exception($e, 'Kostenwerte konnten nicht geladen werden.');
    }

    try {
        $snapshotStmt = $pdo->prepare(
            "SELECT
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / ?) * ?) AS bucket_at,
                AVG(battery_level) AS battery_level,
                AVG(range_km) AS range_km,
                AVG(
                    CASE WHEN JSON_VALID(raw_json) THEN
                        CAST(
                            NULLIF(
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.climate_state.inside_temp')), 'null'),
                                ''
                            ) AS DECIMAL(8,2)
                        )
                    ELSE NULL END
                ) AS inside_temp,
                AVG(
                    CASE WHEN JSON_VALID(raw_json) THEN
                        CAST(
                            NULLIF(
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.climate_state.outside_temp')), 'null'),
                                ''
                            ) AS DECIMAL(8,2)
                        )
                    ELSE NULL END
                ) AS outside_temp
             FROM vehicle_snapshots
             WHERE vehicle_id=? AND recorded_at>=?
             GROUP BY bucket_at
             ORDER BY bucket_at"
        );
        $snapshotStmt->execute([$bucketSeconds, $bucketSeconds, $selectedVehicleId, $sinceUtc]);
        $snapshotRows = $snapshotStmt->fetchAll();
    } catch (Throwable $e) {
        $analyticsWarnings[] = report_exception($e, 'REST-Verläufe konnten nicht geladen werden.');
    }

    try {
        $streamStmt = $pdo->prepare(
            "SELECT
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / ?) * ?) AS bucket_at,
                -- Highest recorded speed per bucket; averaging underreports the observed peak.
                MAX(CASE WHEN speed_kmh BETWEEN 0 AND 350 THEN speed_kmh END) AS speed_kmh,
                COUNT(CASE WHEN speed_kmh BETWEEN 0 AND 350 THEN 1 END) AS speed_samples,
                AVG(power_kw) AS power_kw,
                COUNT(power_kw) AS power_samples,
                AVG(soc) AS soc,
                AVG(range_km) AS range_km
             FROM vehicle_stream_samples
             WHERE vehicle_id=? AND recorded_at>=?
             GROUP BY bucket_at
             ORDER BY bucket_at"
        );
        $streamStmt->execute([$bucketSeconds, $bucketSeconds, $selectedVehicleId, $sinceUtc]);
        $streamRows = $streamStmt->fetchAll();
    } catch (Throwable $e) {
        $analyticsWarnings[] = report_exception($e, 'Stream-Verläufe konnten nicht geladen werden.');
    }
}

$avgConsumption = $distance > 0.05 ? ($tripEnergy * 100 / $distance) : 0.0;
$costPer100Km = $distance > 0.05 && $confirmedCostCharges > 0 ? ($chargeCost * 100 / $distance) : null;
$avgChargePrice = $costedChargeEnergy > 0.001 && $confirmedCostCharges > 0 ? ($chargeCost / $costedChargeEnergy) : null;

$displayTimezone = new DateTimeZone(date_default_timezone_get());
$fmtLocal = static function (?string $value, bool $seconds = false) use ($displayTimezone): string {
    if (!$value) return '–';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone($displayTimezone)->format($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

$toPoints = static function (array $rows, string $field): array {
    $points = [];
    foreach ($rows as $row) {
        if (!isset($row['bucket_at']) || $row['bucket_at'] === null || !is_numeric($row[$field] ?? null)) {
            continue;
        }
        try {
            $moment = new DateTimeImmutable((string)$row['bucket_at'], new DateTimeZone('UTC'));
            $points[] = [
                'x' => $moment->getTimestamp() * 1000,
                'y' => round((float)$row[$field], 3),
            ];
        } catch (Throwable) {
            continue;
        }
    }
    return $points;
};

$chartJson = static function (array $config): string {
    return e(json_encode(
        $config,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    ));
};

$batteryPoints = $toPoints($snapshotRows, 'battery_level');
$rangePoints = $toPoints($snapshotRows, 'range_km');
$insideTempPoints = $toPoints($snapshotRows, 'inside_temp');
$outsideTempPoints = $toPoints($snapshotRows, 'outside_temp');
$speedPoints = $toPoints($streamRows, 'speed_kmh');
$powerPoints = $toPoints($streamRows, 'power_kw');
$rawSpeedSamples = array_sum(array_map(static fn(array $r): int => (int)($r['speed_samples'] ?? 0), $streamRows));
$rawPowerSamples = array_sum(array_map(static fn(array $r): int => (int)($r['power_samples'] ?? 0), $streamRows));
$streamGapMs = match ($rangeKey) {
    '7d' => 45 * 60 * 1000,
    '30d' => 3 * 60 * 60 * 1000,
    default => 10 * 60 * 1000,
};

$batteryChart = [
    'unit' => '%',
    'decimals' => 0,
    'min' => 0,
    'max' => 100,
    'series' => [
        ['label' => 'Akku', 'key' => 'green', 'points' => $batteryPoints],
    ],
];

$rangeChart = [
    'unit' => 'km',
    'decimals' => 0,
    'zeroFloor' => true,
    'series' => [
        ['label' => 'Rated Range', 'key' => 'blue', 'points' => $rangePoints],
    ],
];

$temperatureChart = [
    'unit' => '°C',
    'decimals' => 1,
    'series' => [
        ['label' => 'Innen', 'key' => 'amber', 'points' => $insideTempPoints],
        ['label' => 'Außen', 'key' => 'blue', 'points' => $outsideTempPoints],
    ],
];

$speedChart = [
    'unit' => 'km/h',
    'decimals' => 0,
    'zeroFloor' => true,
    'observedRange' => true,
    'rawSampleCount' => $rawSpeedSamples,
    'maxGapMs' => $streamGapMs,
    'series' => [
        ['label' => 'Geschwindigkeit', 'key' => 'green', 'points' => $speedPoints],
    ],
];

$powerChart = [
    'unit' => 'kW',
    'decimals' => 1,
    'includeZero' => true,
    'rawSampleCount' => $rawPowerSamples,
    'maxGapMs' => $streamGapMs,
    'series' => [
        ['label' => 'Leistung', 'key' => 'brand', 'points' => $powerPoints],
    ],
];


$periodBuckets = [
    'days' => [],
    'months' => [],
    'years' => [],
];

$nowLocal = new DateTimeImmutable('now', $displayTimezone);
$todayLocal = $nowLocal->setTime(0, 0);
$currentMonthLocal = $todayLocal->modify('first day of this month');
$currentYearLocal = $todayLocal->setDate((int)$todayLocal->format('Y'), 1, 1);

$makeBucket = static function (string $key, string $label): array {
    return [
        'key' => $key,
        'label' => $label,
        'trips' => 0,
        'distance_km' => 0.0,
        'drive_energy_kwh' => 0.0,
        'charges' => 0,
        'charge_energy_kwh' => 0.0,
        'cost_amount' => 0.0,
        'confirmed_costs' => 0,
    ];
};

for ($i = 13; $i >= 0; $i--) {
    $date = $todayLocal->modify('-' . $i . ' days');
    $key = $date->format('Y-m-d');
    $periodBuckets['days'][$key] = $makeBucket($key, $date->format('d.m.'));
}
for ($i = 11; $i >= 0; $i--) {
    $date = $currentMonthLocal->modify('-' . $i . ' months');
    $key = $date->format('Y-m');
    $periodBuckets['months'][$key] = $makeBucket($key, $date->format('m/Y'));
}
for ($i = 4; $i >= 0; $i--) {
    $date = $currentYearLocal->modify('-' . $i . ' years');
    $key = $date->format('Y');
    $periodBuckets['years'][$key] = $makeBucket($key, $key);
}

if ($selectedVehicleId > 0) {
    $historyStartLocal = $currentYearLocal->modify('-4 years');
    $historyStartUtc = $historyStartLocal
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    try {
        $tripHistoryStmt = $pdo->prepare(
            "SELECT started_at,distance_km,energy_kwh
             FROM trips
             WHERE vehicle_id=? AND started_at>=?
             ORDER BY started_at"
        );
        $tripHistoryStmt->execute([$selectedVehicleId, $historyStartUtc]);
        foreach ($tripHistoryStmt->fetchAll() as $row) {
            try {
                $moment = new DateTimeImmutable((string)$row['started_at'], new DateTimeZone('UTC'));
                $local = $moment->setTimezone($displayTimezone);
            } catch (Throwable) {
                continue;
            }

            $distanceValue = is_numeric($row['distance_km'] ?? null) ? (float)$row['distance_km'] : 0.0;
            $energyValue = is_numeric($row['energy_kwh'] ?? null) ? (float)$row['energy_kwh'] : 0.0;
            $keys = [
                'days' => $local->format('Y-m-d'),
                'months' => $local->format('Y-m'),
                'years' => $local->format('Y'),
            ];

            foreach ($keys as $group => $key) {
                if (!isset($periodBuckets[$group][$key])) continue;
                $periodBuckets[$group][$key]['trips']++;
                $periodBuckets[$group][$key]['distance_km'] += $distanceValue;
                $periodBuckets[$group][$key]['drive_energy_kwh'] += $energyValue;
            }
        }
    } catch (Throwable $e) {
        $analyticsWarnings[] = report_exception($e, 'Fahrtenauswertung konnte nicht geladen werden.');
    }

    try {
        $chargeHistoryStmt = $pdo->prepare(
            "SELECT started_at,energy_added_kwh,cost_amount
             FROM charges
             WHERE vehicle_id=? AND started_at>=?
             ORDER BY started_at"
        );
        $chargeHistoryStmt->execute([$selectedVehicleId, $historyStartUtc]);
        foreach ($chargeHistoryStmt->fetchAll() as $row) {
            try {
                $moment = new DateTimeImmutable((string)$row['started_at'], new DateTimeZone('UTC'));
                $local = $moment->setTimezone($displayTimezone);
            } catch (Throwable) {
                continue;
            }

            $energyValue = is_numeric($row['energy_added_kwh'] ?? null) ? (float)$row['energy_added_kwh'] : 0.0;
            $costConfirmed = $row['cost_amount'] !== null;
            $costValue = $costConfirmed && is_numeric($row['cost_amount']) ? (float)$row['cost_amount'] : 0.0;
            $keys = [
                'days' => $local->format('Y-m-d'),
                'months' => $local->format('Y-m'),
                'years' => $local->format('Y'),
            ];

            foreach ($keys as $group => $key) {
                if (!isset($periodBuckets[$group][$key])) continue;
                $periodBuckets[$group][$key]['charges']++;
                $periodBuckets[$group][$key]['charge_energy_kwh'] += $energyValue;
                $periodBuckets[$group][$key]['cost_amount'] += $costValue;
                if ($costConfirmed) $periodBuckets[$group][$key]['confirmed_costs']++;
            }
        }
    } catch (Throwable $e) {
        $analyticsWarnings[] = report_exception($e, 'Ladeauswertung konnte nicht geladen werden.');
    }
}

foreach ($periodBuckets as $group => $rows) {
    foreach ($rows as $key => $row) {
        $periodBuckets[$group][$key]['consumption_kwh_100'] =
            $row['distance_km'] > 0.05
                ? ($row['drive_energy_kwh'] * 100 / $row['distance_km'])
                : null;
    }
}

$periodCurrent = [
    'today' => $periodBuckets['days'][$todayLocal->format('Y-m-d')] ?? $makeBucket('', 'Heute'),
    'month' => $periodBuckets['months'][$currentMonthLocal->format('Y-m')] ?? $makeBucket('', 'Monat'),
    'year' => $periodBuckets['years'][$currentYearLocal->format('Y')] ?? $makeBucket('', 'Jahr'),
];

$barWidth = static function (float $value, float $max): string {
    if ($max <= 0.0 || $value <= 0.0) return '0%';
    return number_format(max(2.0, min(100.0, ($value / $max) * 100)), 2, '.', '') . '%';
};

$periodMaxima = [];
foreach ($periodBuckets as $group => $rows) {
    $periodMaxima[$group] = [
        'distance' => max(0.0, ...array_values(array_map(static fn(array $row): float => (float)$row['distance_km'], $rows))),
        'charge' => max(0.0, ...array_values(array_map(static fn(array $row): float => (float)$row['charge_energy_kwh'], $rows))),
    ];
}

render_header('Statistik','statistics');
?>
<div class="page wrap" data-live-region="page-statistics" data-chart-page data-live-poll="10000">
  <div class="page-head">
    <div>
      <span class="kicker">Auswertung</span>
      <h1>Statistik.</h1>
      <p>Akku, Reichweite und Temperatur aus den schlaf-freundlichen REST-Snapshots; Geschwindigkeit und Leistung aus dem Tesla Driving Stream.</p>
    </div>
    <div class="split-actions">
      <span class="pill ok">● Live aus TrakFog-Daten</span>
      <span class="pill"><?= e((string)$range['label']) ?></span>
    </div>
  </div>

  <?php if ($analyticsWarnings): ?>
    <div class="alert alert-warn analytics-warning">
      <b>Ein Teil der Statistik konnte nicht geladen werden.</b>
      <span><?= e(implode(' · ', array_unique($analyticsWarnings))) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!$vehicles): ?>
    <section class="panel">
      <div class="empty">Noch kein Tesla vorhanden. Sobald ein Fahrzeug verbunden ist, entstehen hier automatisch die ersten Verläufe.</div>
    </section>
  <?php else: ?>
    <section class="analytics-toolbar panel">
      <form method="get" class="analytics-filter">
        <label>
          <span>Fahrzeug</span>
          <select name="vehicle_id">
            <?php foreach ($vehicles as $vehicle): ?>
              <option value="<?= (int)$vehicle['id'] ?>" <?= (int)$vehicle['id'] === $selectedVehicleId ? 'selected' : '' ?>>
                <?= e((string)($vehicle['display_name'] ?: 'Tesla')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Zeitraum</span>
          <select name="range">
            <?php foreach ($ranges as $key => $option): ?>
              <option value="<?= e($key) ?>" <?= $key === $rangeKey ? 'selected' : '' ?>><?= e((string)$option['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Anzeigen</button>
        <div class="analytics-filter-meta">
          <span><?= e((string)$range['bucket_label']) ?></span>
          <span>REST zuletzt <?= e($fmtLocal($lastSnapshotAt)) ?></span>
          <span>Stream zuletzt <?= e($fmtLocal($lastStreamAt)) ?></span>
        </div>
      </form>
    </section>

    <div class="stat-grid analytics-stats">
      <a class="stat tf-stat-link" href="statistics.php#rest-charts" title="Details zu REST-Snapshots" aria-label="Details zu REST-Snapshots öffnen"><strong><?= number_format($snapshotCount,0,',','.') ?></strong><span>REST-Snapshots</span></a>
      <a class="stat tf-stat-link" href="statistics.php#stream-charts" title="Details zu Stream-Samples" aria-label="Details zu Stream-Samples öffnen"><strong><?= number_format($streamCount,0,',','.') ?></strong><span>Stream-Samples</span></a>
      <a class="stat tf-stat-link" href="trips.php#trip-list" title="Details zu Fahrstrecke" aria-label="Details zu Fahrstrecke öffnen"><strong><?= number_format($distance,1,',','.') ?> km</strong><span>Fahrstrecke</span></a>
      <a class="stat tf-stat-link" href="statistics.php#lifetime" title="Details zu Ø / 100 km" aria-label="Details zu Ø / 100 km öffnen"><strong><?= $avgConsumption > 0 ? number_format($avgConsumption,1,',','.').' kWh' : '–' ?></strong><span>Ø / 100 km</span></a>
    </div>

    <div class="analytics-chart-grid">
      <section class="panel analytics-chart-panel" id="rest-charts">
        <div class="panel-head">
          <div><h3>🔋 Akkuverlauf</h3><span class="small">State of Charge</span></div>
          <span class="pill">REST</span>
        </div>
        <div class="panel-body">
          <div class="tf-chart" data-chart="<?= $chartJson($batteryChart) ?>"></div>
        </div>
      </section>

      <section class="panel analytics-chart-panel">
        <div class="panel-head">
          <div><h3>🛣️ Reichweite</h3><span class="small">Rated Range</span></div>
          <span class="pill">REST</span>
        </div>
        <div class="panel-body">
          <div class="tf-chart" data-chart="<?= $chartJson($rangeChart) ?>"></div>
        </div>
      </section>

      <section class="panel analytics-chart-panel analytics-chart-wide">
        <div class="panel-head">
          <div><h3>🌡️ Temperatur</h3><span class="small">Innenraum & Außenluft</span></div>
          <span class="pill">REST</span>
        </div>
        <div class="panel-body">
          <div class="tf-chart" data-chart="<?= $chartJson($temperatureChart) ?>"></div>
        </div>
      </section>

      <section class="panel analytics-chart-panel">
        <div class="panel-head">
          <div><h3 id="stream-charts">💨 Geschwindigkeit</h3><span class="small">Driving Stream</span></div>
          <span class="pill ok" title="Nur gemessene Spitzen, kein garantiertes Fahrtmaximum">Stream · gemessen</span>
        </div>
        <div class="panel-body">
          <div class="tf-chart" data-chart="<?= $chartJson($speedChart) ?>"></div>
          <p class="analytics-data-note">Höchster <strong>erfasster</strong> Tesla-Stream-Wert je Zeitfenster (<?= number_format((int)$bucketSeconds / 60,0,',','.') ?> Minuten). <?= number_format($rawSpeedSamples,0,',','.') ?> Geschwindigkeitsmessungen im gewählten Zeitraum. Lücken bleiben sichtbar; nicht aufgezeichnete Geschwindigkeitsspitzen lassen sich nicht zuverlässig bestimmen.</p>
        </div>
      </section>

      <section class="panel analytics-chart-panel">
        <div class="panel-head">
          <div><h3>⚡ Leistung</h3><span class="small">Vortrieb / Rekuperation</span></div>
          <span class="pill ok">Stream</span>
        </div>
        <div class="panel-body">
          <div class="tf-chart" data-chart="<?= $chartJson($powerChart) ?>"></div>
        </div>
      </section>
    </div>


    <section class="period-summary-section">
      <div class="period-section-head">
        <div>
          <span class="kicker">Zeitraumvergleich</span>
          <h2>Tage, Monate & Jahre.</h2>
          <p>Fahrten und Ladevorgänge werden in deiner lokalen TrakFog-Zeitzone zusammengefasst.</p>
        </div>
      </div>

      <div class="period-current-grid">
        <?php foreach ([
          'today' => ['label' => 'Heute', 'icon' => '☀'],
          'month' => ['label' => 'Dieser Monat', 'icon' => '▦'],
          'year' => ['label' => 'Dieses Jahr', 'icon' => '◫'],
        ] as $key => $meta): ?>
          <?php $summary = $periodCurrent[$key]; ?>
          <article class="panel period-current-card">
            <div class="period-current-title"><span><?= e($meta['icon']) ?></span><strong><?= e($meta['label']) ?></strong></div>
            <div class="period-current-metrics">
              <div><span>Strecke</span><b><?= number_format((float)$summary['distance_km'],1,',','.') ?> km</b></div>
              <div><span>Fahrten</span><b><?= number_format((int)$summary['trips'],0,',','.') ?></b></div>
              <div><span>Geladen</span><b><?= number_format((float)$summary['charge_energy_kwh'],2,',','.') ?> kWh</b></div>
              <div><span>Ladungen</span><b><?= number_format((int)$summary['charges'],0,',','.') ?></b></div>
              <div><span>Kosten</span><b><?= (int)$summary['confirmed_costs'] > 0 ? number_format((float)$summary['cost_amount'],2,',','.').' €' : '–' ?></b></div>
              <div><span>Verbrauch</span><b><?= $summary['consumption_kwh_100'] !== null ? number_format((float)$summary['consumption_kwh_100'],1,',','.').' kWh/100' : '–' ?></b></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="period-compare-grid">
        <?php foreach ([
          'days' => ['title' => 'Letzte 14 Tage', 'subtitle' => 'Tagesansicht'],
          'months' => ['title' => 'Letzte 12 Monate', 'subtitle' => 'Monatsansicht'],
          'years' => ['title' => 'Letzte 5 Jahre', 'subtitle' => 'Jahresansicht'],
        ] as $group => $meta): ?>
          <section class="panel period-panel">
            <div class="panel-head">
              <div><h3><?= e($meta['title']) ?></h3><span class="small"><?= e($meta['subtitle']) ?></span></div>
              <span class="pill"><?= count($periodBuckets[$group]) ?> Perioden</span>
            </div>
            <div class="panel-body">
              <div class="period-bars">
                <?php foreach ($periodBuckets[$group] as $row): ?>
                  <div class="period-bar-row">
                    <span class="period-label"><?= e((string)$row['label']) ?></span>
                    <div class="period-bar-metric">
                      <span>Strecke</span>
                      <div class="period-track"><i class="distance" style="width:<?= e($barWidth((float)$row['distance_km'], (float)$periodMaxima[$group]['distance'])) ?>"></i></div>
                      <b><?= number_format((float)$row['distance_km'],1,',','.') ?> km</b>
                    </div>
                    <div class="period-bar-metric">
                      <span>Laden</span>
                      <div class="period-track"><i class="charge" style="width:<?= e($barWidth((float)$row['charge_energy_kwh'], (float)$periodMaxima[$group]['charge'])) ?>"></i></div>
                      <b><?= number_format((float)$row['charge_energy_kwh'],1,',','.') ?> kWh</b>
                    </div>
                    <div class="period-bar-meta">
                      <span><?= (int)$row['trips'] ?> Fahrt<?= (int)$row['trips'] === 1 ? '' : 'en' ?></span>
                      <span><?= (int)$row['charges'] ?> Ladung<?= (int)$row['charges'] === 1 ? '' : 'en' ?></span>
                      <span><?= $row['consumption_kwh_100'] !== null ? number_format((float)$row['consumption_kwh_100'],1,',','.').' kWh/100' : '–' ?></span>
                      <span><?= (int)$row['confirmed_costs'] > 0 ? number_format((float)$row['cost_amount'],2,',','.').' €' : 'Kosten offen' ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="grid-2 analytics-lifetime" id="lifetime">
      <section class="panel">
        <div class="panel-head"><h3>📊 Lifetime · <?= e((string)($selectedVehicle['display_name'] ?? 'Tesla')) ?></h3></div>
        <div class="panel-body">
          <div class="data-list">
            <div class="data-row"><span>Fahrten</span><b><?= number_format($tripCount,0,',','.') ?></b></div>
            <div class="data-row"><span>Ladevorgänge</span><b><?= number_format($chargeCount,0,',','.') ?></b></div>
            <div class="data-row"><span>Fahrenergie</span><b><?= number_format($tripEnergy,2,',','.') ?> kWh</b></div>
            <div class="data-row"><span>Geladene Energie</span><b><?= number_format($chargeEnergy,2,',','.') ?> kWh</b></div>
            <div class="data-row"><span>Ladekosten</span><b><?= $confirmedCostCharges > 0 ? number_format($chargeCost,2,',','.').' €' : '–' ?></b></div>
            <div class="data-row"><span>Ø effektiver Ladepreis</span><b><?= $avgChargePrice !== null ? number_format($avgChargePrice,3,',','.').' €/kWh' : '–' ?></b></div>
            <div class="data-row"><span>Ø Verbrauch</span><b><?= $avgConsumption > 0 ? number_format($avgConsumption,1,',','.').' kWh/100 km' : '–' ?></b></div>
            <div class="data-row"><span>Kosten / 100 km</span><b><?= $costPer100Km !== null ? number_format($costPer100Km,2,',','.').' €' : '–' ?></b></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>🧠 Datenlogik</h3><span class="pill ok">sleep-friendly</span></div>
        <div class="panel-body">
          <div class="data-list">
            <div class="data-row"><span>Akku / Range / Temperatur</span><b>REST-Snapshots</b></div>
            <div class="data-row"><span>Speed / Power</span><b>Driving Stream</b></div>
            <div class="data-row"><span>Browser-Liveupdate</span><b>nur Datenbank</b></div>
            <div class="data-row"><span>Tesla wird für Charts geweckt</span><b>Nein ✅</b></div>
          </div>
          <p class="analytics-note">Wenn das Fahrzeug schläft, bleibt die Kurve bewusst stehen. Neue Punkte entstehen erst wieder, wenn Tesla selbst Daten liefert oder die Engine beim ohnehin wachen Fahrzeug pollt.</p>
        </div>
      </section>
    </div>
  <?php endif; ?>
</div>
<script src="assets/js/charts.js?v=<?= e(app_version()) ?>"></script>
<?php render_footer(); ?>
