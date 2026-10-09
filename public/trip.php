<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();
$mayDeleteTrips = in_array((string)($_SESSION['role'] ?? ''), ['owner', 'admin'], true);

$id = max(0, (int)($_GET['id'] ?? 0));
$stmt = $pdo->prepare(
    "SELECT t.*,v.display_name,v.vin,
            " . TripSoc::CHARGE_OVERLAP_SQL . " AS soc_charge_overlap
     FROM trips t
     JOIN vehicles v ON v.id=t.vehicle_id
     WHERE t.id=?
     LIMIT 1"
);
$stmt->execute([$id]);
$trip = $stmt->fetch();

if (!$trip) {
    ErrorPage::render(404, AppLogger::requestId(), 'Fahrt nicht gefunden.');
}

$journeyStmt = $pdo->prepare(
    "SELECT j.id,j.title,j.status
     FROM journey_trips jt
     JOIN journeys j ON j.id=jt.journey_id
     WHERE jt.trip_id=? AND jt.included=1
     ORDER BY j.id DESC
     LIMIT 1"
);
$journeyStmt->execute([$id]);
$journeyLink = $journeyStmt->fetch() ?: null;

$locations = Geo::loadLocations($pdo);
$geofences = Geo::loadGeofences($pdo, true);
$startLabel = Geo::label(
    $trip['start_latitude'] ?? null,
    $trip['start_longitude'] ?? null,
    $locations,
    $geofences
);
$endLabel = Geo::label(
    $trip['end_latitude'] ?? null,
    $trip['end_longitude'] ?? null,
    $locations,
    $geofences
);

$route = Geo::routePoints($pdo, [$id], 900)[$id] ?? [];
if (!$route && is_numeric($trip['start_latitude'] ?? null) && is_numeric($trip['start_longitude'] ?? null)) {
    $route[] = [
        'lat' => (float)$trip['start_latitude'],
        'lon' => (float)$trip['start_longitude'],
        'at' => (string)$trip['started_at'],
    ];
}
if (is_numeric($trip['end_latitude'] ?? null) && is_numeric($trip['end_longitude'] ?? null)) {
    $endLat = (float)$trip['end_latitude'];
    $endLon = (float)$trip['end_longitude'];
    $last = $route ? $route[count($route)-1] : null;
    if (!$last || abs((float)$last['lat']-$endLat)>0.00001 || abs((float)$last['lon']-$endLon)>0.00001) {
        $route[] = [
            'lat' => $endLat,
            'lon' => $endLon,
            'at' => (string)($trip['ended_at'] ?? $trip['last_sample_at'] ?? ''),
        ];
    }
}

$displayTimezone = new DateTimeZone(date_default_timezone_get());
$fmtLocal = static function (?string $value, bool $seconds = false) use ($displayTimezone): string {
    if (!$value) return '–';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone($displayTimezone)
            ->format($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i');
    } catch (Throwable) {
        return (string)$value;
    }
};

$durationSeconds = null;
try {
    $startAt = new DateTimeImmutable((string)$trip['started_at'], new DateTimeZone('UTC'));
    $endAt = $trip['ended_at']
        ? new DateTimeImmutable((string)$trip['ended_at'], new DateTimeZone('UTC'))
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $durationSeconds = $trip['drive_seconds'] !== null ? (int)$trip['drive_seconds'] : max(0, $endAt->getTimestamp() - $startAt->getTimestamp());
} catch (Throwable) {}

$formatDuration = static function (?int $seconds): string {
    if ($seconds === null) return '–';
    $hours = intdiv($seconds,3600);
    $minutes = intdiv($seconds%3600,60);
    if ($hours>0) return $hours.' h '.str_pad((string)$minutes,2,'0',STR_PAD_LEFT).' min';
    return max(1,$minutes).' min';
};

$active = empty($trip['ended_at']);
$startSoc = $trip['start_soc'] !== null ? (float)$trip['start_soc'] : null;
$endSoc = $trip['end_soc'] !== null ? (float)$trip['end_soc'] : null;
$chargeOverlaps = TripSoc::chargeOverlaps($trip);
$socDelta = TripSoc::drivingDelta($startSoc, $endSoc, $chargeOverlaps);

render_header('Fahrt #' . $id, 'trips', true);
?>
<div class="page wrap trip-detail-page">
  <?php if ($chargeOverlaps): ?><div class="alert alert-warn">Ladevorgang im gespeicherten Fahrtzeitraum erkannt. Die dort gewonnene Akkuenergie wird nicht der Fahrt zugerechnet. Der echte Fahrverbrauch kann aus diesen beiden SoC-Werten nicht bestimmt werden.</div><?php endif; ?>
  <?php if(isset($_GET['merged'])): ?><div class="alert alert-ok">Fahrten erfolgreich zu einer Fahrt zusammengef&uuml;hrt. Die Strecke und Verbrauchswerte sind kombiniert.</div><?php endif; ?>
  <div class="page-head">
    <div>
      <span class="kicker">Historie · Fahrt #<?= $id ?></span>
      <h1><?= e($startLabel['label']) ?> → <?= e($endLabel['label']) ?></h1>
      <p><?= e($fmtLocal((string)$trip['started_at'])) ?> → <?= $active ? 'läuft' : e($fmtLocal((string)$trip['ended_at'])) ?> · <?= e((string)($trip['display_name'] ?: 'Tesla')) ?></p>
    </div>
    <div class="split-actions">
      <span class="pill <?= $active ? 'ok' : '' ?>"><?= $active ? '● Live-Fahrt' : 'abgeschlossen' ?></span>
      <?php if ($journeyLink): ?><a class="btn btn-ghost" href="journey.php?id=<?= (int)$journeyLink['id'] ?>">🧳 <?= e((string)$journeyLink['title']) ?></a><?php endif; ?>
      <a class="btn btn-primary" href="map.php?trip_id=<?= $id ?>">Auf großer Karte →</a>
      <a class="btn btn-ghost" href="trips.php">← Fahrten</a>
      <?php if ($mayDeleteTrips && !$active): ?><button type="button" class="btn trip-delete-inline" data-trip-delete-id="<?= $id ?>" data-trip-delete-label="Fahrt #<?= $id ?>">L&ouml;schen</button><?php endif; ?>
    </div>
  </div>

  <div class="stat-grid trip-detail-stats">
    <a class="stat tf-stat-link" href="#trip-detail-map" title="Details zu Strecke" aria-label="Details zu Strecke öffnen"><strong><?= $trip['distance_km'] !== null ? number_format((float)$trip['distance_km'],1,',','.').' km' : '–' ?></strong><span>Strecke</span></a>
    <a class="stat tf-stat-link" href="#trip-route-summary" title="Details zu Dauer" aria-label="Details zu Dauer öffnen"><strong><?= e($formatDuration($durationSeconds)) ?></strong><span>Dauer</span></a>
    <a class="stat tf-stat-link" href="#trip-energy-detail" title="Details zu Stream-Energie" aria-label="Details zu Stream-Energie öffnen"><strong><?= $trip['energy_kwh'] !== null ? number_format((float)$trip['energy_kwh'],2,',','.').' kWh' : '–' ?></strong><span>Stream-Energie</span></a>
    <a class="stat tf-stat-link" href="#trip-energy-detail" title="Details zu Verbrauch" aria-label="Details zu Verbrauch öffnen"><strong><?= $trip['avg_wh_km'] !== null ? number_format((float)$trip['avg_wh_km'],0,',','.').' Wh/km' : '–' ?></strong><span>Verbrauch</span></a>
  </div>

  <section class="panel trip-route-summary" id="trip-route-summary">
    <div class="panel-head">
      <div><h3>🗺️ Route</h3><span class="small"><?= count($route) ?> Kartenpunkte · aus Tesla Stream</span></div>
      <span class="pill"><?= $trip['max_speed_kmh'] !== null ? number_format((float)$trip['max_speed_kmh'],0,',','.').' km/h max.' : 'Tempo –' ?></span>
    </div>
    <div class="panel-body trip-route-info">
      <div class="trip-place">
        <span class="trip-place-marker start">S</span>
        <div>
          <small>Start</small>
          <strong><?= e($startLabel['label']) ?></strong>
          <?php if (!empty($startLabel['address']) && $startLabel['address'] !== $startLabel['label']): ?><span><?= e($startLabel['address']) ?></span><?php endif; ?>
          <em><?= e($fmtLocal((string)$trip['started_at'], true)) ?></em>
        </div>
      </div>
      <div class="trip-place-line"></div>
      <div class="trip-place">
        <span class="trip-place-marker end">Z</span>
        <div>
          <small>Ziel</small>
          <strong><?= e($endLabel['label']) ?></strong>
          <?php if (!empty($endLabel['address']) && $endLabel['address'] !== $endLabel['label']): ?><span><?= e($endLabel['address']) ?></span><?php endif; ?>
          <em><?= $active ? 'Fahrt läuft' : e($fmtLocal((string)$trip['ended_at'], true)) ?></em>
        </div>
      </div>
    </div>
  </section>

  <section class="panel trip-map-panel">
    <div class="panel-body" style="padding:0">
      <div id="trip-detail-map" class="trakfog-map trip-detail-map"></div>
    </div>
  </section>

  <div class="grid-2 trip-detail-grid">
    <section class="panel" id="trip-energy-detail">
      <div class="panel-head"><h3>🔋 Energie & Akku</h3></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>SoC</span><b><?php if ($chargeOverlaps): ?>Nicht eindeutig (Ladepause)<?php else: ?><?= $startSoc !== null ? number_format($startSoc,0,',','.').' %' : '–' ?> → <?= $endSoc !== null ? number_format($endSoc,0,',','.').' %' : ($active ? '…' : '–') ?><?php endif; ?></b></div>
          <div class="data-row"><span>SoC-Änderung</span><b><?= $chargeOverlaps ? '– (Laden im Zeitraum)' : ($socDelta !== null ? ($socDelta>0?'+':'').number_format($socDelta,0,',','.').' Pkt.' : '–') ?></b></div>
          <div class="data-row"><span>Range</span><b><?= $chargeOverlaps ? '– (Laden im Zeitraum)' : (($trip['start_range_km'] !== null ? number_format((float)$trip['start_range_km'],0,',','.').' km' : '–').' → '.($trip['end_range_km'] !== null ? number_format((float)$trip['end_range_km'],0,',','.').' km' : ($active ? '…' : '–'))) ?></b></div>
          <div class="data-row"><span>Stream-Pakete</span><b><?= number_format((int)($trip['sample_count'] ?? 0),0,',','.') ?></b></div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>🚗 Strecke</h3></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>Kilometerstand Start</span><b><?= $trip['start_odometer_km'] !== null ? number_format((float)$trip['start_odometer_km'],1,',','.').' km' : '–' ?></b></div>
          <div class="data-row"><span>Kilometerstand Ziel</span><b><?= $trip['end_odometer_km'] !== null ? number_format((float)$trip['end_odometer_km'],1,',','.').' km' : ($active ? '…' : '–') ?></b></div>
          <div class="data-row"><span>Max. Tempo</span><b><?= $trip['max_speed_kmh'] !== null ? number_format((float)$trip['max_speed_kmh'],0,',','.').' km/h' : '–' ?></b></div>
          <div class="data-row"><span>Datenquelle</span><b><?= e((string)($trip['source'] ?: 'tesla_stream')) ?></b></div>
        </div>
      </div>
    </section>
  </div>

  <p class="analytics-note">Die Route wird aus bereits gespeicherten Tesla-Stream-Koordinaten rekonstruiert. Das Öffnen dieser Seite fragt den Tesla nicht zusätzlich ab.</p>
</div>

<script>
(() => {
  const points = <?= json_encode($route, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const route = points
    .map(p => [Number(p.lat),Number(p.lon)])
    .filter(p => Number.isFinite(p[0]) && Number.isFinite(p[1]));

  const map=L.map('trip-detail-map',{preferCanvas:true}).setView([51.16,10.45],6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    maxZoom:19,
    attribution:'&copy; OpenStreetMap'
  }).addTo(map);

  const icon=(kind,label)=>L.divIcon({
    className:'tf-map-icon-wrap',
    html:'<span class="tf-map-icon '+kind+'">'+label+'</span>',
    iconSize:[34,34],
    iconAnchor:[17,17]
  });

  if (route.length) {
    const line=L.polyline(route,{className:'tf-trip-line',weight:5,opacity:.95,lineCap:'round',lineJoin:'round'}).addTo(map);
    L.marker(route[0],{icon:icon('start','S')}).addTo(map).bindPopup(<?= json_encode($startLabel['label'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    L.marker(route[route.length-1],{icon:icon('end','Z')}).addTo(map).bindPopup(<?= json_encode($endLabel['label'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    map.fitBounds(line.getBounds(),{padding:[35,35],maxZoom:16});
  }

  requestAnimationFrame(()=>map.invalidateSize());
})();
</script>
<?php if ($mayDeleteTrips): ?><?php require dirname(__DIR__) . '/src/trip-delete-dialog.php'; ?><?php endif; ?>
<?php render_footer(); ?>
