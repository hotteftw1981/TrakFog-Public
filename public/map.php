<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$user = Auth::user($pdo);
$isAdmin = in_array($user['role'], ['owner','admin'], true);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save_geofence') {
                $id = max(0, (int)($_POST['geofence_id'] ?? 0));
                $name = trim((string)($_POST['name'] ?? ''));
                $kind = trim((string)($_POST['kind'] ?? 'place'));
                $shapeType = trim((string)($_POST['shape_type'] ?? 'circle'));
                $latitude = str_replace(',', '.', trim((string)($_POST['latitude'] ?? '')));
                $longitude = str_replace(',', '.', trim((string)($_POST['longitude'] ?? '')));
                $radius = max(5, min(5000, (int)($_POST['radius_m'] ?? 75)));
                $polygonRaw = trim((string)($_POST['polygon_json'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $active = isset($_POST['active']) ? 1 : 0;

                $allowedKinds = ['home','work','charging','travel','place'];
                if ($name === '') throw new RuntimeException('Bitte einen Namen für den Geobereich angeben.');
                if (!in_array($kind, $allowedKinds, true)) $kind = 'place';
                if (!in_array($shapeType, ['circle','polygon'], true)) $shapeType = 'circle';

                $polygonJson = null;
                if ($shapeType === 'polygon') {
                    try {
                        $decoded = json_decode($polygonRaw, true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        throw new RuntimeException('Die gezeichnete Fläche konnte nicht gelesen werden.');
                    }

                    if (!is_array($decoded) || count($decoded) < 3) {
                        throw new RuntimeException('Eine freie Fläche braucht mindestens drei Eckpunkte.');
                    }

                    $points = [];
                    foreach ($decoded as $point) {
                        if (!is_array($point)) continue;
                        $pointLat = $point['lat'] ?? ($point[0] ?? null);
                        $pointLon = $point['lon'] ?? ($point['lng'] ?? ($point[1] ?? null));
                        if (!is_numeric($pointLat) || !is_numeric($pointLon)) continue;
                        $pointLat = (float)$pointLat;
                        $pointLon = (float)$pointLon;
                        if ($pointLat < -90 || $pointLat > 90 || $pointLon < -180 || $pointLon > 180) continue;
                        $points[] = [
                            'lat' => round($pointLat, 7),
                            'lon' => round($pointLon, 7),
                        ];
                    }

                    if (count($points) < 3) {
                        throw new RuntimeException('Eine freie Fläche braucht mindestens drei gültige Eckpunkte.');
                    }

                    $lat = array_sum(array_column($points, 'lat')) / count($points);
                    $lon = array_sum(array_column($points, 'lon')) / count($points);
                    $polygonJson = json_encode($points, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                } else {
                    if (!is_numeric($latitude) || !is_numeric($longitude)) {
                        throw new RuntimeException('Bitte gültige Koordinaten angeben.');
                    }
                    $lat = (float)$latitude;
                    $lon = (float)$longitude;
                }

                if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                    throw new RuntimeException('Die Koordinaten liegen außerhalb des gültigen Bereichs.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE geofences
                         SET name=?,kind=?,shape_type=?,latitude=?,longitude=?,radius_m=?,polygon_json=?,active=?,notes=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $name,$kind,$shapeType,$lat,$lon,$radius,$polygonJson,$active,
                        $notes !== '' ? $notes : null,$id
                    ]);
                    $message = 'Geobereich gespeichert.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO geofences(name,kind,shape_type,latitude,longitude,radius_m,polygon_json,active,notes)
                         VALUES(?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $name,$kind,$shapeType,$lat,$lon,$radius,$polygonJson,$active,
                        $notes !== '' ? $notes : null
                    ]);
                    $message = 'Geobereich angelegt.';
                }
            } elseif ($action === 'delete_geofence') {
                $id = max(0, (int)($_POST['geofence_id'] ?? 0));
                if ($id > 0) {
                    $stmt = $pdo->prepare('DELETE FROM geofences WHERE id=?');
                    $stmt->execute([$id]);
                    $message = 'Geobereich gelöscht.';
                }
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : report_exception($e, 'Geobereich konnte nicht gespeichert werden.');
        }
    }
}

$periods = [
    '24h' => ['label' => '24 Stunden', 'modifier' => '-24 hours'],
    '7d' => ['label' => '7 Tage', 'modifier' => '-7 days'],
    '30d' => ['label' => '30 Tage', 'modifier' => '-30 days'],
    '90d' => ['label' => '90 Tage', 'modifier' => '-90 days'],
    'all' => ['label' => 'Gesamt', 'modifier' => null],
];
$period = (string)($_GET['period'] ?? '7d');
if (!isset($periods[$period])) $period = '7d';

$focusTripId = max(0, (int)($_GET['trip_id'] ?? 0));
$focusChargeId = max(0, (int)($_GET['charge_id'] ?? 0));
$vehicleFilter = max(0, (int)($_GET['vehicle_id'] ?? 0));
$focusVehicleId = ((string)($_GET['focus'] ?? '') === 'vehicle') ? $vehicleFilter : 0;

if ($focusTripId > 0) {
    $stmt = $pdo->prepare('SELECT vehicle_id FROM trips WHERE id=? LIMIT 1');
    $stmt->execute([$focusTripId]);
    $vehicleFilter = (int)($stmt->fetchColumn() ?: $vehicleFilter);
    $period = 'all';
} elseif ($focusChargeId > 0) {
    $stmt = $pdo->prepare('SELECT vehicle_id FROM charges WHERE id=? LIMIT 1');
    $stmt->execute([$focusChargeId]);
    $vehicleFilter = (int)($stmt->fetchColumn() ?: $vehicleFilter);
    $period = 'all';
}

$allVehicles = $pdo->query(
    "SELECT id,display_name,state,latitude,longitude,battery_level,rated_range_km,last_seen_at
     FROM vehicles
     ORDER BY display_name,id"
)->fetchAll();

$cutoff = null;
if ($periods[$period]['modifier'] !== null) {
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify($periods[$period]['modifier'])
        ->format('Y-m-d H:i:s');
}

$conditions = [];
$params = [];
if ($vehicleFilter > 0) {
    $conditions[] = 't.vehicle_id=?';
    $params[] = $vehicleFilter;
}
if ($cutoff !== null) {
    $conditions[] = 't.started_at>=?';
    $params[] = $cutoff;
}
$whereTrips = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$tripStmt = $pdo->prepare(
    "SELECT t.*,v.display_name,
            " . TripSoc::CHARGE_OVERLAP_SQL . " AS soc_charge_overlap
     FROM trips t
     JOIN vehicles v ON v.id=t.vehicle_id
     {$whereTrips}
     ORDER BY t.started_at DESC
     LIMIT 60"
);
$tripStmt->execute($params);
$trips = $tripStmt->fetchAll();

$chargeConditions = [];
$chargeParams = [];
if ($vehicleFilter > 0) {
    $chargeConditions[] = 'c.vehicle_id=?';
    $chargeParams[] = $vehicleFilter;
}
if ($cutoff !== null) {
    $chargeConditions[] = 'c.started_at>=?';
    $chargeParams[] = $cutoff;
}
$whereCharges = $chargeConditions ? 'WHERE ' . implode(' AND ', $chargeConditions) : '';

$chargeStmt = $pdo->prepare(
    "SELECT c.*,v.display_name
     FROM charges c
     JOIN vehicles v ON v.id=c.vehicle_id
     {$whereCharges}
     ORDER BY c.started_at DESC
     LIMIT 100"
);
$chargeStmt->execute($chargeParams);
$charges = $chargeStmt->fetchAll();

$locations = Geo::loadLocations($pdo);
$geofences = Geo::loadGeofences($pdo, false);
$activeGeofences = array_values(array_filter($geofences, static fn(array $row): bool => (int)($row['active'] ?? 0) === 1));

$routePoints = Geo::routePoints(
    $pdo,
    array_map(static fn(array $row): int => (int)$row['id'], $trips),
    420
);

$driveHeatRows = [];
$chargeHeatRows = [];
$driveHeatSamples = 0;
$chargeHeatSessions = 0;

try {
    $heatConditions = [
        's.latitude IS NOT NULL',
        's.longitude IS NOT NULL',
        's.latitude BETWEEN -90 AND 90',
        's.longitude BETWEEN -180 AND 180',
        'NOT (s.latitude=0 AND s.longitude=0)',
        "(s.shift_state IN ('D','R') OR COALESCE(s.speed_kmh,0)>2)",
    ];
    $heatParams = [];
    if ($vehicleFilter > 0) {
        $heatConditions[] = 's.vehicle_id=?';
        $heatParams[] = $vehicleFilter;
    }
    if ($cutoff !== null) {
        $heatConditions[] = 's.recorded_at>=?';
        $heatParams[] = $cutoff;
    }

    $heatStmt = $pdo->prepare(
        "SELECT
            ROUND(s.latitude,4) AS latitude,
            ROUND(s.longitude,4) AS longitude,
            COUNT(*) AS samples,
            AVG(NULLIF(s.speed_kmh,0)) AS avg_speed_kmh
         FROM vehicle_stream_samples s
         WHERE " . implode(' AND ', $heatConditions) . "
         GROUP BY ROUND(s.latitude,4),ROUND(s.longitude,4)
         ORDER BY samples DESC
         LIMIT 2500"
    );
    $heatStmt->execute($heatParams);
    $driveHeatRows = $heatStmt->fetchAll();
    foreach ($driveHeatRows as $row) {
        $driveHeatSamples += (int)($row['samples'] ?? 0);
    }

    $chargeHeatConditions = [
        'c.latitude IS NOT NULL',
        'c.longitude IS NOT NULL',
        'c.latitude BETWEEN -90 AND 90',
        'c.longitude BETWEEN -180 AND 180',
        'NOT (c.latitude=0 AND c.longitude=0)',
    ];
    $chargeHeatParams = [];
    if ($vehicleFilter > 0) {
        $chargeHeatConditions[] = 'c.vehicle_id=?';
        $chargeHeatParams[] = $vehicleFilter;
    }
    if ($cutoff !== null) {
        $chargeHeatConditions[] = 'c.started_at>=?';
        $chargeHeatParams[] = $cutoff;
    }

    $chargeHeatStmt = $pdo->prepare(
        "SELECT
            ROUND(c.latitude,4) AS latitude,
            ROUND(c.longitude,4) AS longitude,
            COUNT(*) AS sessions,
            SUM(COALESCE(c.energy_added_kwh,0)) AS energy_kwh
         FROM charges c
         WHERE " . implode(' AND ', $chargeHeatConditions) . "
         GROUP BY ROUND(c.latitude,4),ROUND(c.longitude,4)
         ORDER BY sessions DESC
         LIMIT 1000"
    );
    $chargeHeatStmt->execute($chargeHeatParams);
    $chargeHeatRows = $chargeHeatStmt->fetchAll();
    foreach ($chargeHeatRows as $row) {
        $chargeHeatSessions += (int)($row['sessions'] ?? 0);
    }
} catch (Throwable $e) {
    AppLogger::exception($e, ['page' => 'map', 'section' => 'heatmap']);
    $driveHeatRows = [];
    $chargeHeatRows = [];
    $driveHeatSamples = 0;
    $chargeHeatSessions = 0;
}

$displayTimezone = new DateTimeZone(date_default_timezone_get());
$fmtLocal = static function (?string $value) use ($displayTimezone): string {
    if (!$value) return '–';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone($displayTimezone)
            ->format('d.m.Y H:i');
    } catch (Throwable) {
        return (string)$value;
    }
};

$tripPayload = [];
foreach ($trips as $trip) {
    $start = Geo::label($trip['start_latitude'] ?? null, $trip['start_longitude'] ?? null, $locations, $activeGeofences);
    $end = Geo::label($trip['end_latitude'] ?? null, $trip['end_longitude'] ?? null, $locations, $activeGeofences);

    $points = $routePoints[(int)$trip['id']] ?? [];
    if (!$points && is_numeric($trip['start_latitude'] ?? null) && is_numeric($trip['start_longitude'] ?? null)) {
        $points[] = ['lat' => (float)$trip['start_latitude'], 'lon' => (float)$trip['start_longitude'], 'at' => (string)$trip['started_at']];
    }
    if (is_numeric($trip['end_latitude'] ?? null) && is_numeric($trip['end_longitude'] ?? null)) {
        $last = $points ? $points[count($points)-1] : null;
        $endLat = (float)$trip['end_latitude'];
        $endLon = (float)$trip['end_longitude'];
        if (!$last || abs((float)$last['lat'] - $endLat) > 0.00001 || abs((float)$last['lon'] - $endLon) > 0.00001) {
            $points[] = ['lat' => $endLat, 'lon' => $endLon, 'at' => (string)($trip['ended_at'] ?? $trip['last_sample_at'] ?? '')];
        }
    }

    $tripPayload[] = [
        'id' => (int)$trip['id'],
        'vehicle_id' => (int)$trip['vehicle_id'],
        'vehicle' => (string)($trip['display_name'] ?: 'Tesla'),
        'started_at' => $fmtLocal((string)$trip['started_at']),
        'ended_at' => $trip['ended_at'] ? $fmtLocal((string)$trip['ended_at']) : 'läuft',
        'distance_km' => $trip['distance_km'] !== null ? (float)$trip['distance_km'] : null,
        'energy_kwh' => $trip['energy_kwh'] !== null ? (float)$trip['energy_kwh'] : null,
        'start_soc' => !TripSoc::chargeOverlaps($trip) && $trip['start_soc'] !== null ? (float)$trip['start_soc'] : null,
        'end_soc' => !TripSoc::chargeOverlaps($trip) && $trip['end_soc'] !== null ? (float)$trip['end_soc'] : null,
        'soc_charge_overlap' => TripSoc::chargeOverlaps($trip),
        'start_label' => $start['label'],
        'end_label' => $end['label'],
        'start_source' => $start['source'],
        'end_source' => $end['source'],
        'points' => $points,
    ];
}

$chargePayload = [];
foreach ($charges as $charge) {
    if (!is_numeric($charge['latitude'] ?? null) || !is_numeric($charge['longitude'] ?? null)) continue;
    $label = Geo::label(
        $charge['latitude'],
        $charge['longitude'],
        $locations,
        $activeGeofences,
        (string)($charge['location_name'] ?? '')
    );
    $chargePayload[] = [
        'id' => (int)$charge['id'],
        'vehicle_id' => (int)$charge['vehicle_id'],
        'vehicle' => (string)($charge['display_name'] ?: 'Tesla'),
        'lat' => (float)$charge['latitude'],
        'lon' => (float)$charge['longitude'],
        'label' => $label['label'],
        'source' => $label['source'],
        'started_at' => $fmtLocal((string)$charge['started_at']),
        'energy_kwh' => $charge['energy_added_kwh'] !== null ? (float)$charge['energy_added_kwh'] : null,
        'cost' => $charge['cost_amount'] !== null ? (float)$charge['cost_amount'] : null,
        'currency' => (string)($charge['cost_currency'] ?: 'EUR'),
    ];
}

$vehiclePayload = [];
foreach ($allVehicles as $vehicle) {
    if ($vehicleFilter > 0 && (int)$vehicle['id'] !== $vehicleFilter) continue;
    if (!is_numeric($vehicle['latitude'] ?? null) || !is_numeric($vehicle['longitude'] ?? null)) continue;
    $label = Geo::label($vehicle['latitude'], $vehicle['longitude'], $locations, $activeGeofences);
    $vehiclePayload[] = [
        'id' => (int)$vehicle['id'],
        'name' => (string)($vehicle['display_name'] ?: 'Tesla'),
        'state' => (string)($vehicle['state'] ?: 'unknown'),
        'battery' => $vehicle['battery_level'] !== null ? (float)$vehicle['battery_level'] : null,
        'range_km' => $vehicle['rated_range_km'] !== null ? (float)$vehicle['rated_range_km'] : null,
        'lat' => (float)$vehicle['latitude'],
        'lon' => (float)$vehicle['longitude'],
        'label' => $label['label'],
        'last_seen' => $fmtLocal((string)($vehicle['last_seen_at'] ?? '')),
    ];
}

$geoResolved = 0;
$geoPending = 0;
$geocoderEnabled = !in_array(
    strtolower(trim((string)(getenv('TRAKFOG_GEOCODER_ENABLED') ?: '1'))),
    ['0','false','no','off'],
    true
);
try {
    $geoResolved = (int)$pdo->query("SELECT COUNT(*) FROM geo_locations WHERE status='resolved'")->fetchColumn();
    $geoPending = (int)$pdo->query("SELECT COUNT(*) FROM geo_locations WHERE status IN ('pending','processing','retry')")->fetchColumn();
} catch (Throwable) {}

$kindLabels = [
    'home' => 'Zuhause',
    'work' => 'Arbeit',
    'charging' => 'Ladeort',
    'travel' => 'Reise',
    'place' => 'Ort',
];

render_header('Karte','map', true);
?>
<div class="page wrap map-page">
  <div class="page-head">
    <div>
      <span class="kicker">Orte</span>
      <h1>Karte & Orte.</h1>
      <p>Fahrtrouten, Ladeorte, letzte Fahrzeugpositionen und eigene Geobereiche auf einer Karte – alles aus bereits gespeicherten TrakFog-Daten.</p>
    </div>
    <div class="split-actions">
      <span class="pill"><?= count($tripPayload) ?> Route<?= count($tripPayload) === 1 ? '' : 'n' ?></span>
      <span class="pill"><?= count($chargePayload) ?> Ladeort<?= count($chargePayload) === 1 ? '' : 'e' ?></span>
      <?php if ($isAdmin): ?><button class="btn btn-primary" type="button" id="openGeofenceModal">＋ Geobereich</button><?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <form class="panel map-toolbar" method="get">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <label>
      <span>Fahrzeug</span>
      <select name="vehicle_id">
        <option value="0">Alle Fahrzeuge</option>
        <?php foreach ($allVehicles as $vehicle): ?>
          <option value="<?= (int)$vehicle['id'] ?>" <?= (int)$vehicle['id'] === $vehicleFilter ? 'selected' : '' ?>>
            <?= e((string)($vehicle['display_name'] ?: 'Tesla')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="map-periods">
      <?php foreach ($periods as $key => $meta): ?>
        <a class="btn <?= $period === $key ? 'btn-primary' : 'btn-ghost' ?>" href="?vehicle_id=<?= $vehicleFilter ?>&period=<?= e($key) ?>"><?= e($meta['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-ghost map-filter-submit" type="submit">Filter anwenden</button>
  </form>

  <section class="panel map-shell">
    <div class="map-layer-panel" aria-label="Kartenebenen">
      <strong>Ebenen</strong>
      <label><input type="checkbox" data-map-layer="routes" checked> <span>Fahrten</span></label>
      <label><input type="checkbox" data-map-layer="charges" checked> <span>Ladeorte</span></label>
      <label><input type="checkbox" data-map-layer="vehicles" checked> <span>Fahrzeuge</span></label>
      <label><input type="checkbox" data-map-layer="geofences" checked> <span>Geobereiche</span></label>
      <span class="map-layer-divider">Analyse</span>
      <label><input type="checkbox" data-map-layer="driveHeat"> <span>Fahrdichte</span></label>
      <label><input type="checkbox" data-map-layer="chargeHeat"> <span>Lade-Hotspots</span></label>
    </div>
    <div class="geo-editor-hud" id="geoEditorHud" hidden>
      <div>
        <span class="kicker" id="geoEditorKicker">Geo-Editor</span>
        <strong id="geoEditorTitle">Geobereich bearbeiten</strong>
        <small id="geoEditorHelp">Punkte auf der Karte setzen.</small>
      </div>
      <div class="geo-editor-actions">
        <span class="geo-editor-value" id="geoEditorValue"></span>
        <button class="btn btn-primary" type="button" id="geoEditorApply">Übernehmen</button>
        <button class="btn btn-ghost" type="button" id="geoEditorCancel">Abbrechen</button>
      </div>
    </div>
    <div id="tesla-map" class="map trakfog-map"></div>
    <div class="map-heat-legend" id="mapHeatLegend" hidden>
      <strong id="mapHeatLegendTitle">Heatmap</strong>
      <span class="map-heat-gradient"></span>
      <small id="mapHeatLegendLow">weniger</small><small id="mapHeatLegendHigh">häufiger</small>
    </div>
    <div class="map-status-strip">
      <span><b><?= $geoResolved ?></b> Adressen aufgelöst</span>
      <span><b><?= $geoPending ?></b> in Geo-Warteschlange</span>
      <span><b><?= count($activeGeofences) ?></b> aktive Geobereiche</span>
      <span><b><?= number_format($driveHeatSamples,0,',','.') ?></b> Fahrpunkte für Heatmap</span>
      <span><b><?= number_format($chargeHeatSessions,0,',','.') ?></b> Ladesessions für Hotspots</span>
      <span>Reverse Geocoding: <?= $geocoderEnabled ? 'aktiv' : 'deaktiviert' ?> · kein Tesla-Wakeup.</span>
      <?php if ($isAdmin): ?><span class="map-context-hint">Rechtsklick übernimmt exakt die angeklickte Kartenposition</span><?php endif; ?>
    </div>
  </section>

  <div class="map-activity-grid">
    <section class="panel">
      <div class="panel-head"><h3>🛣️ Letzte Routen</h3><a class="btn btn-ghost" href="trips.php">Alle Fahrten →</a></div>
      <div class="panel-body">
        <?php if (!$tripPayload): ?>
          <div class="empty">Im gewählten Zeitraum gibt es noch keine Fahrt mit GPS-Daten.</div>
        <?php else: ?>
          <div class="map-activity-list">
            <?php foreach (array_slice($tripPayload,0,8) as $trip): ?>
              <button class="map-activity-row" type="button" data-map-trip="<?= (int)$trip['id'] ?>">
                <span class="map-activity-icon route">↗</span>
                <span class="map-activity-copy">
                  <strong><?= e($trip['start_label']) ?> → <?= e($trip['end_label']) ?></strong>
                  <small><?= e($trip['started_at']) ?> · <?= $trip['distance_km'] !== null ? number_format((float)$trip['distance_km'],1,',','.').' km' : 'Strecke offen' ?></small>
                </span>
                <span>⌖</span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>⚡ Letzte Ladeorte</h3><a class="btn btn-ghost" href="charges.php">Alle Ladungen →</a></div>
      <div class="panel-body">
        <?php if (!$chargePayload): ?>
          <div class="empty">Im gewählten Zeitraum gibt es noch keinen Ladevorgang mit Standort.</div>
        <?php else: ?>
          <div class="map-activity-list">
            <?php foreach (array_slice($chargePayload,0,8) as $charge): ?>
              <button class="map-activity-row" type="button" data-map-charge="<?= (int)$charge['id'] ?>">
                <span class="map-activity-icon charge">⚡</span>
                <span class="map-activity-copy">
                  <strong><?= e($charge['label']) ?></strong>
                  <small><?= e($charge['started_at']) ?> · <?= $charge['energy_kwh'] !== null ? number_format((float)$charge['energy_kwh'],1,',','.').' kWh' : 'Energie offen' ?></small>
                </span>
                <span>⌖</span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="tf-modal" id="geofenceModal" hidden>
  <div class="tf-modal-backdrop" data-modal-close></div>
  <section class="tf-modal-card" role="dialog" aria-modal="true" aria-labelledby="geofenceModalTitle">
    <div class="tf-modal-head">
      <div><span class="kicker">Map & Geo</span><h2 id="geofenceModalTitle">Geobereich verwalten</h2></div>
      <button class="tf-modal-close" type="button" data-modal-close aria-label="Schließen">×</button>
    </div>
    <div class="tf-modal-body">
      <form method="post" class="geofence-form" id="geofenceForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_geofence">
        <input type="hidden" name="geofence_id" id="geofenceId" value="0">
        <input type="hidden" name="shape_type" id="geofenceShapeType" value="circle">
        <input type="hidden" name="polygon_json" id="geofencePolygonJson" value="">
        <div class="geofence-shape-switch" role="group" aria-label="Form des Geobereichs">
          <button class="geofence-shape-btn active" type="button" data-shape-choice="circle">⭕ Radius</button>
          <button class="geofence-shape-btn" type="button" data-shape-choice="polygon">⬡ Freie Fläche</button>
        </div>
        <div class="geofence-form-grid">
          <label><span>Name</span><input name="name" id="geofenceName" placeholder="z. B. Zuhause" required></label>
          <label>
            <span>Typ</span>
            <select name="kind" id="geofenceKind">
              <?php foreach ($kindLabels as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="geofence-radius-field" id="geofenceRadiusField">
            <span>Radius</span>
            <div class="radius-input-wrap"><input name="radius_m" id="geofenceRadius" type="number" min="5" max="5000" value="75" required><b>m</b></div>
          </label>
          <label class="check-chip"><input type="checkbox" name="active" id="geofenceActive" value="1" checked><span>Aktiv</span></label>
          <label><span>Breitengrad</span><input name="latitude" id="geofenceLat" inputmode="decimal" required></label>
          <label><span>Längengrad</span><input name="longitude" id="geofenceLon" inputmode="decimal" required></label>
          <label class="geofence-notes"><span>Notiz</span><input name="notes" id="geofenceNotes" placeholder="optional"></label>
        </div>
        <div class="geofence-shape-tools">
          <button class="btn btn-ghost" type="button" id="editCircleOnMap">⭕ Radius auf Karte anpassen</button>
          <button class="btn btn-ghost" type="button" id="drawPolygonOnMap" hidden>⬡ Fläche auf Karte zeichnen</button>
          <span id="geofenceShapeSummary">Kreis · 75 m Radius</span>
        </div>
        <div class="geofence-map-hint">Radius: Mittelpunkt und Randgriff verschieben. Fläche: Eckpunkte setzen und ziehen; Rechtsklick auf einen Eckpunkt entfernt ihn.</div>
        <div class="split-actions">
          <button class="btn btn-primary" type="submit">Geobereich speichern</button>
          <button class="btn btn-ghost" type="button" id="resetGeofenceForm">Neuer Bereich</button>
        </div>
      </form>

      <div class="geofence-existing">
        <div class="panel-head"><h3>Bestehende Geobereiche</h3><span class="pill"><?= count($geofences) ?></span></div>
        <?php if (!$geofences): ?>
          <div class="empty">Noch keine eigenen Geobereiche vorhanden.</div>
        <?php else: ?>
          <div class="geofence-list">
            <?php foreach ($geofences as $geofence): ?>
              <article class="geofence-row">
                <div>
                  <strong><?= e((string)$geofence['name']) ?></strong>
                  <span>
                    <?= e($kindLabels[(string)$geofence['kind']] ?? 'Ort') ?> ·
                    <?php if (($geofence['shape_type'] ?? 'circle') === 'polygon'): ?>
                      freie Fläche · <?= count($geofence['polygon_points'] ?? []) ?> Eckpunkte
                    <?php else: ?>
                      <?= number_format((int)$geofence['radius_m'],0,',','.') ?> m Radius
                    <?php endif; ?>
                    · <?= (int)$geofence['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                  </span>
                </div>
                <div class="split-actions">
                  <button
                    class="btn btn-ghost geofence-edit"
                    type="button"
                    data-id="<?= (int)$geofence['id'] ?>"
                    data-name="<?= e((string)$geofence['name']) ?>"
                    data-kind="<?= e((string)$geofence['kind']) ?>"
                    data-shape="<?= e((string)($geofence['shape_type'] ?? 'circle')) ?>"
                    data-polygon="<?= e((string)($geofence['polygon_json'] ?? '')) ?>"
                    data-lat="<?= e((string)$geofence['latitude']) ?>"
                    data-lon="<?= e((string)$geofence['longitude']) ?>"
                    data-radius="<?= (int)$geofence['radius_m'] ?>"
                    data-active="<?= (int)$geofence['active'] ?>"
                    data-notes="<?= e((string)($geofence['notes'] ?? '')) ?>"
                  >Bearbeiten</button>
                  <form method="post" onsubmit="return confirm('Geobereich wirklich löschen?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_geofence">
                    <input type="hidden" name="geofence_id" value="<?= (int)$geofence['id'] ?>">
                    <button class="btn btn-ghost" type="submit">Löschen</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>
<?php endif; ?>

<script>
(() => {
  const vehicles = <?= json_encode($vehiclePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const trips = <?= json_encode($tripPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const charges = <?= json_encode($chargePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const geofences = <?= json_encode($activeGeofences, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const driveHeat = <?= json_encode($driveHeatRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const chargeHeat = <?= json_encode($chargeHeatRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const focusTripId = <?= $focusTripId ?>;
  const focusChargeId = <?= $focusChargeId ?>;
  const focusVehicleId = <?= $focusVehicleId ?>;

  const map = L.map('tesla-map', {zoomControl:true, preferCanvas:true}).setView([51.16,10.45],6);
  const syncMapSize = () => window.requestAnimationFrame(() => map.invalidateSize({pan:false}));
  window.addEventListener('resize', syncMapSize, {passive:true});
  window.addEventListener('orientationchange', syncMapSize, {passive:true});
  setTimeout(syncMapSize, 80);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom:19,
    attribution:'&copy; OpenStreetMap'
  }).addTo(map);

  const layers = {
    routes: L.layerGroup().addTo(map),
    charges: L.layerGroup().addTo(map),
    vehicles: L.layerGroup().addTo(map),
    geofences: L.layerGroup().addTo(map),
    driveHeat: null,
    chargeHeat: null
  };
  const allBounds = [];
  const tripLayers = new Map();
  const chargeLayers = new Map();
  const vehicleLayers = new Map();

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[ch]));
  const fmt = (value, digits=1) => value == null || !Number.isFinite(Number(value))
    ? '–'
    : Number(value).toLocaleString('de-DE',{minimumFractionDigits:digits,maximumFractionDigits:digits});

  const heatWeight=(value,max) => {
    const numeric=Math.max(0,Number(value || 0));
    const maximum=Math.max(1,Number(max || 1));
    if (numeric<=0) return 0;
    if (maximum<=1) return .55;
    return Math.max(.12,Math.min(1,Math.log1p(numeric)/Math.log1p(maximum)));
  };

  const makeHeatLayer=(rows,valueKey,kind) => {
    const valid=(rows || []).map(row => ({
      lat:Number(row.latitude),
      lon:Number(row.longitude),
      value:Number(row[valueKey] || 0)
    })).filter(row => Number.isFinite(row.lat) && Number.isFinite(row.lon) && row.value>0);

    if (!valid.length) return L.layerGroup();

    const max=Math.max(...valid.map(row => row.value),1);
    const points=valid.map(row => [row.lat,row.lon,heatWeight(row.value,max)]);

    if (typeof L.heatLayer === 'function') {
      const driveGradient={0.12:'#64748b',0.35:'#f0a33a',0.68:'#f36c21',1:'#e82127'};
      const chargeGradient={0.12:'#8a6b22',0.40:'#f3c451',0.72:'#f08c2e',1:'#e82127'};
      return L.heatLayer(points,{
        radius:kind==='charge' ? 32 : 24,
        blur:kind==='charge' ? 24 : 18,
        minOpacity:.24,
        maxZoom:17,
        gradient:kind==='charge' ? chargeGradient : driveGradient
      });
    }

    const fallback=L.layerGroup();
    valid.forEach(row => {
      const weight=heatWeight(row.value,max);
      L.circleMarker([row.lat,row.lon],{
        radius:4+(weight*10),
        stroke:false,
        fillOpacity:.18+(weight*.52),
        fillColor:kind==='charge' ? '#f08c2e' : '#e82127'
      }).addTo(fallback);
    });
    return fallback;
  };

  layers.driveHeat=makeHeatLayer(driveHeat,'samples','drive');
  layers.chargeHeat=makeHeatLayer(chargeHeat,'sessions','charge');

  const icon = (kind, label) => L.divIcon({
    className:'tf-map-icon-wrap',
    html:'<span class="tf-map-icon '+kind+'">'+esc(label)+'</span>',
    iconSize:[34,34],
    iconAnchor:[17,17],
    popupAnchor:[0,-14]
  });

  geofences.forEach(g => {
    const lat=Number(g.latitude), lon=Number(g.longitude), radius=Number(g.radius_m || 75);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

    const shape=String(g.shape_type || 'circle');
    let layer=null;
    let detail='';

    if (shape === 'polygon' && Array.isArray(g.polygon_points) && g.polygon_points.length >= 3) {
      const points=g.polygon_points
        .map(p => [Number(p.lat),Number(p.lon)])
        .filter(p => Number.isFinite(p[0]) && Number.isFinite(p[1]));

      if (points.length >= 3) {
        layer=L.polygon(points,{
          className:'tf-geofence-polygon',
          weight:2,
          fillOpacity:.12
        }).addTo(layers.geofences);
        detail='Freie Fläche · '+points.length+' Eckpunkte';
        points.forEach(p => allBounds.push(p));
      }
    }

    if (!layer) {
      layer=L.circle([lat,lon],{
        radius,
        className:'tf-geofence-circle',
        weight:2,
        fillOpacity:.08
      }).addTo(layers.geofences);
      detail=fmt(radius,0)+' m Radius';
      allBounds.push([lat,lon]);
    }

    layer.bindPopup(
      '<div class="tf-map-popup"><span class="popup-kicker">Geobereich</span><strong>'+esc(g.name)+'</strong><small>'+esc(detail)+'</small></div>'
    );
  });

  trips.forEach(t => {
    const points=(t.points || [])
      .map(p => [Number(p.lat),Number(p.lon)])
      .filter(p => Number.isFinite(p[0]) && Number.isFinite(p[1]));
    if (!points.length) return;

    points.forEach(p => allBounds.push(p));
    const line=L.polyline(points,{
      className:'tf-trip-line',
      weight:Number(t.id)===focusTripId ? 6 : 4,
      opacity:Number(t.id)===focusTripId ? 1 : .82,
      lineCap:'round',
      lineJoin:'round'
    }).addTo(layers.routes);

    const popup=
      '<div class="tf-map-popup">'+
        '<span class="popup-kicker">Fahrt #'+Number(t.id)+'</span>'+
        '<strong>'+esc(t.start_label)+' → '+esc(t.end_label)+'</strong>'+
        '<small>'+esc(t.started_at)+' · '+fmt(t.distance_km,1)+' km</small>'+
        '<a href="trip.php?id='+Number(t.id)+'">Route & Details →</a>'+
      '</div>';
    line.bindPopup(popup);

    const start=L.marker(points[0],{icon:icon('start','S')}).addTo(layers.routes).bindPopup(popup);
    const end=L.marker(points[points.length-1],{icon:icon('end','Z')}).addTo(layers.routes).bindPopup(popup);
    tripLayers.set(Number(t.id), {line,start,end,points});
  });

  charges.forEach(c => {
    const lat=Number(c.lat), lon=Number(c.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    const marker=L.marker([lat,lon],{icon:icon('charge','⚡')}).addTo(layers.charges);
    marker.bindPopup(
      '<div class="tf-map-popup">'+
        '<span class="popup-kicker">Ladevorgang #'+Number(c.id)+'</span>'+
        '<strong>'+esc(c.label)+'</strong>'+
        '<small>'+esc(c.started_at)+' · '+fmt(c.energy_kwh,1)+' kWh</small>'+
        '<a href="charge.php?id='+Number(c.id)+'">Ladung öffnen →</a>'+
      '</div>'
    );
    chargeLayers.set(Number(c.id), marker);
    allBounds.push([lat,lon]);
  });

  vehicles.forEach(v => {
    const lat=Number(v.lat), lon=Number(v.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    const marker=L.marker([lat,lon],{icon:icon('vehicle','T')}).addTo(layers.vehicles);
    marker.bindPopup(
      '<div class="tf-map-popup">'+
        '<span class="popup-kicker">Letzte Fahrzeugposition</span>'+
        '<strong>'+esc(v.name)+'</strong>'+
        '<small>'+esc(v.label)+'</small>'+
        '<small>'+esc(v.state)+' · '+(v.battery == null ? '–' : Math.round(Number(v.battery))+' %')+' · '+esc(v.last_seen)+'</small>'+
        '<a href="vehicle.php?id='+Number(v.id)+'">Fahrzeug öffnen →</a>'+
      '</div>'
    );
    vehicleLayers.set(Number(v.id), marker);
    allBounds.push([lat,lon]);
  });

  const heatLegend=document.getElementById('mapHeatLegend');
  const heatLegendTitle=document.getElementById('mapHeatLegendTitle');
  const heatLegendLow=document.getElementById('mapHeatLegendLow');
  const heatLegendHigh=document.getElementById('mapHeatLegendHigh');
  const syncHeatLegend=() => {
    if (!heatLegend) return;
    const driveOn=document.querySelector('[data-map-layer="driveHeat"]')?.checked === true;
    const chargeOn=document.querySelector('[data-map-layer="chargeHeat"]')?.checked === true;
    const driveVisible=driveOn && Array.isArray(driveHeat) && driveHeat.length>0;
    const chargeVisible=chargeOn && Array.isArray(chargeHeat) && chargeHeat.length>0;

    heatLegend.hidden=!(driveVisible || chargeVisible);
    if (heatLegend.hidden) return;

    if (driveVisible && chargeVisible) {
      if (heatLegendTitle) heatLegendTitle.textContent='Heatmap';
      if (heatLegendLow) heatLegendLow.textContent='weniger';
      if (heatLegendHigh) heatLegendHigh.textContent='häufiger';
    } else if (chargeVisible) {
      if (heatLegendTitle) heatLegendTitle.textContent='Lade-Hotspots';
      if (heatLegendLow) heatLegendLow.textContent='weniger Sessions';
      if (heatLegendHigh) heatLegendHigh.textContent='mehr Sessions';
    } else {
      if (heatLegendTitle) heatLegendTitle.textContent='Fahrdichte';
      if (heatLegendLow) heatLegendLow.textContent='seltener';
      if (heatLegendHigh) heatLegendHigh.textContent='häufiger';
    }
  };

  document.querySelectorAll('[data-map-layer]').forEach(input => {
    input.addEventListener('change', () => {
      const key=input.dataset.mapLayer;
      if (!layers[key]) return;
      if (input.checked) layers[key].addTo(map);
      else map.removeLayer(layers[key]);
      syncHeatLegend();
    });
  });

  document.querySelectorAll('[data-map-trip]').forEach(button => {
    button.addEventListener('click', () => {
      const item=tripLayers.get(Number(button.dataset.mapTrip));
      if (!item) return;
      map.fitBounds(item.line.getBounds(),{padding:[50,50],maxZoom:16});
      item.line.openPopup();
    });
  });
  document.querySelectorAll('[data-map-charge]').forEach(button => {
    button.addEventListener('click', () => {
      const marker=chargeLayers.get(Number(button.dataset.mapCharge));
      if (!marker) return;
      map.setView(marker.getLatLng(),16);
      marker.openPopup();
    });
  });

  if (focusTripId && tripLayers.has(focusTripId)) {
    const item=tripLayers.get(focusTripId);
    map.fitBounds(item.line.getBounds(),{padding:[50,50],maxZoom:16});
    item.line.openPopup();
  } else if (focusChargeId && chargeLayers.has(focusChargeId)) {
    const marker=chargeLayers.get(focusChargeId);
    map.setView(marker.getLatLng(),16);
    marker.openPopup();
  } else if (focusVehicleId && vehicleLayers.has(focusVehicleId)) {
    const marker=vehicleLayers.get(focusVehicleId);
    map.setView(marker.getLatLng(),15);
    marker.openPopup();
  } else if (allBounds.length === 1) {
    map.setView(allBounds[0],14);
  } else if (allBounds.length > 1) {
    map.fitBounds(allBounds,{padding:[35,35],maxZoom:15});
  }

  const modal=document.getElementById('geofenceModal');
  const openButton=document.getElementById('openGeofenceModal');
  const latInput=document.getElementById('geofenceLat');
  const lonInput=document.getElementById('geofenceLon');
  const idInput=document.getElementById('geofenceId');
  const nameInput=document.getElementById('geofenceName');
  const kindInput=document.getElementById('geofenceKind');
  const radiusInput=document.getElementById('geofenceRadius');
  const activeInput=document.getElementById('geofenceActive');
  const notesInput=document.getElementById('geofenceNotes');
  const shapeTypeInput=document.getElementById('geofenceShapeType');
  const polygonInput=document.getElementById('geofencePolygonJson');
  const radiusField=document.getElementById('geofenceRadiusField');
  const editCircleButton=document.getElementById('editCircleOnMap');
  const drawPolygonButton=document.getElementById('drawPolygonOnMap');
  const shapeSummary=document.getElementById('geofenceShapeSummary');
  const editorHud=document.getElementById('geoEditorHud');
  const editorTitle=document.getElementById('geoEditorTitle');
  const editorHelp=document.getElementById('geoEditorHelp');
  const editorValue=document.getElementById('geoEditorValue');
  const editorApply=document.getElementById('geoEditorApply');
  const editorCancel=document.getElementById('geoEditorCancel');
  const mapContainer=map.getContainer();

  const geoEditor={
    mode:null,
    shapeLayer:null,
    centerMarker:null,
    radiusMarker:null,
    vertexMarkers:[],
    center:null,
    radius:75,
    points:[]
  };

  const editorHandleIcon=(label,kind='vertex') => L.divIcon({
    className:'geo-editor-handle-wrap',
    html:'<span class="geo-editor-handle '+kind+'">'+esc(label)+'</span>',
    iconSize:[26,26],
    iconAnchor:[13,13]
  });

  const parsePolygonInput=() => {
    try {
      const parsed=JSON.parse(polygonInput?.value || '[]');
      if (!Array.isArray(parsed)) return [];
      return parsed.map(p => ({
        lat:Number(p.lat ?? p[0]),
        lon:Number(p.lon ?? p.lng ?? p[1])
      })).filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lon));
    } catch (_) {
      return [];
    }
  };

  const polygonCentroid=(points) => {
    if (!points.length) return map.getCenter();
    const sum=points.reduce((acc,p) => ({lat:acc.lat+p.lat,lon:acc.lon+p.lon}),{lat:0,lon:0});
    return L.latLng(sum.lat/points.length,sum.lon/points.length);
  };

  const setPolygonInput=(points) => {
    const clean=points.map(p => ({
      lat:Number(Number(p.lat).toFixed(7)),
      lon:Number(Number(p.lon).toFixed(7))
    }));
    polygonInput.value=JSON.stringify(clean);
    const center=polygonCentroid(clean);
    latInput.value=center.lat.toFixed(6);
    lonInput.value=center.lng.toFixed(6);
  };

  const updateShapeSummary=() => {
    const shape=shapeTypeInput.value === 'polygon' ? 'polygon' : 'circle';
    if (shape === 'polygon') {
      const count=parsePolygonInput().length;
      shapeSummary.textContent=count >= 3
        ? 'Freie Fläche · '+count+' Eckpunkte'
        : 'Freie Fläche · noch nicht gezeichnet';
    } else {
      const radius=Math.max(5,Number(radiusInput.value || 75));
      shapeSummary.textContent='Kreis · '+Math.round(radius).toLocaleString('de-DE')+' m Radius';
    }
  };

  const setShape=(shape) => {
    shape=shape === 'polygon' ? 'polygon' : 'circle';
    shapeTypeInput.value=shape;
    document.querySelectorAll('[data-shape-choice]').forEach(button => {
      button.classList.toggle('active',button.dataset.shapeChoice===shape);
    });
    radiusField.hidden=shape!=='circle';
    editCircleButton.hidden=shape!=='circle';
    drawPolygonButton.hidden=shape!=='polygon';
    updateShapeSummary();
  };

  const resetForm=(latlng=null) => {
    if (!modal) return;
    const position=latlng || map.getCenter();
    idInput.value='0';
    nameInput.value='';
    kindInput.value='place';
    radiusInput.value='75';
    activeInput.checked=true;
    notesInput.value='';
    latInput.value=Number(position.lat).toFixed(6);
    lonInput.value=Number(position.lng).toFixed(6);
    polygonInput.value='';
    setShape('circle');
  };

  const openModal=() => {
    if (!modal) return;
    modal.hidden=false;
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => nameInput?.focus());
  };

  const closeModal=() => {
    if (!modal) return;
    modal.hidden=true;
    document.body.classList.remove('modal-open');
  };

  const radiusHandlePosition=(center,radius) => {
    const latRad=center.lat*Math.PI/180;
    const metersPerDegreeLon=Math.max(1,111320*Math.cos(latRad));
    return L.latLng(center.lat,center.lng+(radius/metersPerDegreeLon));
  };

  const clearGeoEditor=() => {
    if (geoEditor.shapeLayer) map.removeLayer(geoEditor.shapeLayer);
    if (geoEditor.centerMarker) map.removeLayer(geoEditor.centerMarker);
    if (geoEditor.radiusMarker) map.removeLayer(geoEditor.radiusMarker);
    geoEditor.vertexMarkers.forEach(marker => map.removeLayer(marker));
    geoEditor.shapeLayer=null;
    geoEditor.centerMarker=null;
    geoEditor.radiusMarker=null;
    geoEditor.vertexMarkers=[];
    geoEditor.mode=null;
    geoEditor.center=null;
    geoEditor.points=[];
    editorHud.hidden=true;
    map.doubleClickZoom.enable();
    mapContainer.classList.remove('geo-editing');
  };

  const refreshCircleEditor=() => {
    if (geoEditor.mode!=='circle' || !geoEditor.center) return;
    const center=geoEditor.center;
    const radius=Math.max(5,Math.min(5000,Number(geoEditor.radius || 75)));
    geoEditor.radius=radius;

    if (!geoEditor.shapeLayer) {
      geoEditor.shapeLayer=L.circle(center,{
        radius,
        className:'tf-geofence-editor-circle',
        weight:3,
        fillOpacity:.14
      }).addTo(map);
    } else {
      geoEditor.shapeLayer.setLatLng(center).setRadius(radius);
    }

    if (!geoEditor.centerMarker) {
      geoEditor.centerMarker=L.marker(center,{
        draggable:true,
        icon:editorHandleIcon('●','center')
      }).addTo(map);
      geoEditor.centerMarker.on('drag',event => {
        geoEditor.center=event.target.getLatLng();
        geoEditor.radiusMarker?.setLatLng(radiusHandlePosition(geoEditor.center,geoEditor.radius));
        refreshCircleEditor();
      });
    } else {
      geoEditor.centerMarker.setLatLng(center);
    }

    const handlePos=radiusHandlePosition(center,radius);
    if (!geoEditor.radiusMarker) {
      geoEditor.radiusMarker=L.marker(handlePos,{
        draggable:true,
        icon:editorHandleIcon('↔','radius')
      }).addTo(map);
      geoEditor.radiusMarker.on('drag',event => {
        const meters=map.distance(geoEditor.center,event.target.getLatLng());
        geoEditor.radius=Math.max(5,Math.min(5000,meters));
        geoEditor.shapeLayer?.setRadius(geoEditor.radius);
        editorValue.textContent=Math.round(geoEditor.radius).toLocaleString('de-DE')+' m';
      });
      geoEditor.radiusMarker.on('dragend',() => refreshCircleEditor());
    } else {
      geoEditor.radiusMarker.setLatLng(handlePos);
    }

    editorValue.textContent=Math.round(radius).toLocaleString('de-DE')+' m';
    editorApply.disabled=false;
  };

  const startCircleEditor=() => {
    const lat=Number(latInput.value), lon=Number(lonInput.value);
    const center=Number.isFinite(lat)&&Number.isFinite(lon) ? L.latLng(lat,lon) : map.getCenter();
    clearGeoEditor();
    closeModal();
    geoEditor.mode='circle';
    geoEditor.center=center;
    geoEditor.radius=Math.max(5,Math.min(5000,Number(radiusInput.value || 75)));
    editorTitle.textContent='Radius direkt auf der Karte';
    editorHelp.textContent='Mittelpunkt verschieben oder den Randgriff ziehen. Die Meterzahl aktualisiert sich live.';
    editorHud.hidden=false;
    mapContainer.classList.add('geo-editing');
    map.setView(center,Math.max(map.getZoom(),17));
    refreshCircleEditor();
  };

  const refreshPolygonEditor=() => {
    if (geoEditor.mode!=='polygon') return;

    if (geoEditor.shapeLayer) map.removeLayer(geoEditor.shapeLayer);
    geoEditor.vertexMarkers.forEach(marker => map.removeLayer(marker));
    geoEditor.vertexMarkers=[];

    const latLngs=geoEditor.points.map(p => [p.lat,p.lon]);
    if (latLngs.length >= 3) {
      geoEditor.shapeLayer=L.polygon(latLngs,{
        className:'tf-geofence-editor-polygon',
        weight:3,
        fillOpacity:.16
      }).addTo(map);
    } else if (latLngs.length >= 2) {
      geoEditor.shapeLayer=L.polyline(latLngs,{
        className:'tf-geofence-editor-polygon',
        weight:3
      }).addTo(map);
    } else {
      geoEditor.shapeLayer=null;
    }

    geoEditor.points.forEach((point,index) => {
      const marker=L.marker([point.lat,point.lon],{
        draggable:true,
        icon:editorHandleIcon(String(index+1),'vertex')
      }).addTo(map);

      marker.on('drag',event => {
        const pos=event.target.getLatLng();
        geoEditor.points[index]={lat:pos.lat,lon:pos.lng};
        if (geoEditor.shapeLayer) {
          geoEditor.shapeLayer.setLatLngs(geoEditor.points.map(p => [p.lat,p.lon]));
        }
      });
      marker.on('dragend',() => refreshPolygonEditor());

      marker.on('contextmenu',event => {
        if (event.originalEvent) {
          event.originalEvent.preventDefault();
          event.originalEvent.stopPropagation();
        }
        if (geoEditor.points.length <= 3) return;
        geoEditor.points.splice(index,1);
        refreshPolygonEditor();
      });

      geoEditor.vertexMarkers.push(marker);
    });

    const count=geoEditor.points.length;
    editorValue.textContent=count+' Eckpunkt'+(count===1?'':'e');
    editorApply.disabled=count<3;
    editorHelp.textContent=count<3
      ? 'Auf die Karte klicken: mindestens drei Eckpunkte setzen.'
      : 'Weitere Punkte setzen oder Griffe ziehen. Rechtsklick auf einen Griff entfernt ihn.';
  };

  const startPolygonEditor=() => {
    clearGeoEditor();
    closeModal();
    geoEditor.mode='polygon';
    geoEditor.points=parsePolygonInput();
    editorTitle.textContent=geoEditor.points.length >= 3 ? 'Freie Fläche bearbeiten' : 'Freie Fläche zeichnen';
    editorHud.hidden=false;
    mapContainer.classList.add('geo-editing');
    map.doubleClickZoom.disable();

    if (geoEditor.points.length >= 3) {
      const bounds=L.latLngBounds(geoEditor.points.map(p => [p.lat,p.lon]));
      if (bounds.isValid()) map.fitBounds(bounds,{padding:[70,70],maxZoom:19});
    } else {
      const lat=Number(latInput.value),lon=Number(lonInput.value);
      if (Number.isFinite(lat)&&Number.isFinite(lon)) map.setView([lat,lon],Math.max(map.getZoom(),18));
    }
    refreshPolygonEditor();
  };

  const applyGeoEditor=() => {
    if (geoEditor.mode==='circle' && geoEditor.center) {
      latInput.value=geoEditor.center.lat.toFixed(6);
      lonInput.value=geoEditor.center.lng.toFixed(6);
      radiusInput.value=String(Math.round(geoEditor.radius));
      polygonInput.value='';
      setShape('circle');
    } else if (geoEditor.mode==='polygon' && geoEditor.points.length>=3) {
      setPolygonInput(geoEditor.points);
      setShape('polygon');
    } else {
      return;
    }
    clearGeoEditor();
    openModal();
    updateShapeSummary();
  };

  const cancelGeoEditor=() => {
    if (!geoEditor.mode) return;
    clearGeoEditor();
    openModal();
  };

  openButton?.addEventListener('click', () => { resetForm(); openModal(); });
  document.querySelectorAll('[data-modal-close]').forEach(el => el.addEventListener('click', closeModal));
  document.getElementById('resetGeofenceForm')?.addEventListener('click', () => resetForm());
  document.querySelectorAll('[data-shape-choice]').forEach(button => {
    button.addEventListener('click',() => setShape(button.dataset.shapeChoice || 'circle'));
  });
  radiusInput?.addEventListener('input',updateShapeSummary);
  editCircleButton?.addEventListener('click',startCircleEditor);
  drawPolygonButton?.addEventListener('click',startPolygonEditor);
  editorApply?.addEventListener('click',applyGeoEditor);
  editorCancel?.addEventListener('click',cancelGeoEditor);

  map.on('click',event => {
    if (geoEditor.mode!=='polygon') return;
    geoEditor.points.push({lat:event.latlng.lat,lon:event.latlng.lng});
    refreshPolygonEditor();
  });

  mapContainer.addEventListener('contextmenu', event => {
    event.preventDefault();
    event.stopPropagation();
    if (!modal || geoEditor.mode) return;

    const containerPoint=L.DomEvent.getMousePosition(event,mapContainer);
    const clickedLatLng=map.containerPointToLatLng(containerPoint);
    resetForm(clickedLatLng);
    openModal();
  }, false);

  document.querySelectorAll('.geofence-edit').forEach(button => {
    button.addEventListener('click', () => {
      idInput.value=button.dataset.id || '0';
      nameInput.value=button.dataset.name || '';
      kindInput.value=button.dataset.kind || 'place';
      latInput.value=button.dataset.lat || '';
      lonInput.value=button.dataset.lon || '';
      radiusInput.value=button.dataset.radius || '75';
      activeInput.checked=button.dataset.active === '1';
      notesInput.value=button.dataset.notes || '';
      polygonInput.value=button.dataset.polygon || '';
      setShape(button.dataset.shape || 'circle');
      openModal();

      const points=parsePolygonInput();
      if (shapeTypeInput.value==='polygon' && points.length>=3) {
        const bounds=L.latLngBounds(points.map(p => [p.lat,p.lon]));
        if (bounds.isValid()) map.fitBounds(bounds,{padding:[50,50],maxZoom:18});
      } else {
        const lat=Number(latInput.value),lon=Number(lonInput.value);
        if (Number.isFinite(lat)&&Number.isFinite(lon)) map.setView([lat,lon],16);
      }
    });
  });

  window.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (geoEditor.mode) {
      cancelGeoEditor();
      return;
    }
    if (modal && !modal.hidden) closeModal();
  });

  requestAnimationFrame(() => map.invalidateSize());
})();
</script>
<?php render_footer(); ?>
