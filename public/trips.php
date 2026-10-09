<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();
$mayDeleteTrips = in_array((string)($_SESSION['role'] ?? ''), ['owner', 'admin'], true);
$mayMergeTrips = $mayDeleteTrips;

$rows = $pdo->query(
    "SELECT t.*, v.display_name,
            " . TripSoc::CHARGE_OVERLAP_SQL . " AS soc_charge_overlap,
            (
              SELECT j.id
              FROM journey_trips jt
              JOIN journeys j ON j.id=jt.journey_id
              WHERE jt.trip_id=t.id AND jt.included=1
              ORDER BY j.id DESC
              LIMIT 1
            ) AS journey_id,
            (
              SELECT j.title
              FROM journey_trips jt
              JOIN journeys j ON j.id=jt.journey_id
              WHERE jt.trip_id=t.id AND jt.included=1
              ORDER BY j.id DESC
              LIMIT 1
            ) AS journey_title
     FROM trips t
     JOIN vehicles v ON v.id=t.vehicle_id
     ORDER BY t.started_at DESC
     LIMIT 100"
)->fetchAll();

$quickSuggestion = $mayMergeTrips && !empty($rows) && !empty($rows[0]['ended_at']) ? TripMerge::suggestionFor($pdo,(int)$rows[0]['vehicle_id'],(int)$rows[0]['id']) : null;
$totalTrips = (int)$pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();
$activeTrips = (int)$pdo->query('SELECT COUNT(*) FROM trips WHERE ended_at IS NULL')->fetchColumn();
$totalDistance = (float)$pdo->query('SELECT COALESCE(SUM(distance_km),0) FROM trips')->fetchColumn();
$totalEnergy = (float)$pdo->query('SELECT COALESCE(SUM(energy_kwh),0) FROM trips')->fetchColumn();
$avgWhKm = $totalDistance > 0.05 ? ($totalEnergy * 1000 / $totalDistance) : 0.0;
$geoLocations = Geo::loadLocations($pdo);
$geoFences = Geo::loadGeofences($pdo, true);

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

$duration = static function (array $trip): string {
    $start=(string)($trip['started_at']??'');
    $end=$trip['ended_at']??null;
    if (!$start) return '–';
    try {
        $startAt = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $endAt = $end
            ? new DateTimeImmutable($end, new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = $trip['drive_seconds'] !== null ? (int)$trip['drive_seconds'] : max(0, $endAt->getTimestamp() - $startAt->getTimestamp());
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) return $hours . ' h ' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' min';
        return max(1, $minutes) . ' min';
    } catch (Throwable) {
        return '–';
    }
};

$shortPlace = static function (string $label): string {
    $parts = array_values(array_filter(array_map('trim', explode(',', $label)), static fn(string $value): bool => $value !== ''));
    if (count($parts) < 4) return trim($label);
    // Full geocoder address: house number, street, district, city, region, postcode, country.
    $filtered = array_values(array_filter($parts, static function (string $part): bool {
        return !preg_match('/^(?:\d{5}|Deutschland|Germany|Nordrhein-Westfalen|North Rhine-Westphalia|Ennepe-Ruhr-Kreis)$/iu', $part)
            && !preg_match('/(?:-Kreis|\bLandkreis\b|\bRegierungsbezirk\b)/iu', $part);
    }));
    if (count($filtered) < 3) return trim($label);
    $city = $filtered[count($filtered) - 1];
    if (preg_match('/^\d+[a-zA-Z]?$/', $filtered[0])) {
        return $filtered[1] . ' ' . $filtered[0] . ', ' . $city;
    }
    return $filtered[0] . ', ' . $city;
};

render_header('Fahrten','trips');
?>
<div class="page wrap">
  <div class="page-head">
    <div>
      <span class="kicker">Historie</span>
      <h1>Fahrten.</h1>
      <p>Fahrten werden jetzt direkt aus dem Tesla Driving Stream erkannt und während der Fahrt laufend aktualisiert.</p>
    </div>
    <div class="split-actions">
      <?php if ($activeTrips > 0): ?><span class="pill ok">● <?= $activeTrips ?> Live-Fahrt<?= $activeTrips === 1 ? '' : 'en' ?></span><?php endif; ?>
      <span class="pill"><?= $totalTrips ?> gespeichert</span>
      <a class="btn btn-ghost" href="trips.php">↻ Aktualisieren</a>
    </div>
  </div>

  <div class="stat-grid">
    <a class="stat tf-stat-link" href="trips.php#trip-list" title="Details zu Fahrten gesamt" aria-label="Details zu Fahrten gesamt öffnen"><strong><?= $totalTrips ?></strong><span>Fahrten gesamt</span></a>
    <a class="stat tf-stat-link" href="statistics.php#lifetime" title="Details zu Strecke gesamt" aria-label="Details zu Strecke gesamt öffnen"><strong><?= number_format($totalDistance,1,',','.') ?> km</strong><span>Strecke gesamt</span></a>
    <a class="stat tf-stat-link" href="statistics.php#lifetime" title="Details zu Stream-Energie" aria-label="Details zu Stream-Energie öffnen"><strong><?= number_format($totalEnergy,2,',','.') ?> kWh</strong><span>Stream-Energie</span></a>
    <a class="stat tf-stat-link" href="statistics.php#lifetime" title="Details zu Wh/km Ø" aria-label="Details zu Wh/km Ø öffnen"><strong><?= $avgWhKm > 0 ? number_format($avgWhKm,0,',','.') : '–' ?></strong><span>Wh/km Ø</span></a>
  </div>

  <?php if(isset($_GET['merge_error'])): ?><div class="alert alert-error"><?= e(substr((string)$_GET['merge_error'],0,350)) ?></div><?php endif; ?>
  <?php if(isset($_GET['deleted'])): ?><div class="alert alert-ok">Fahrt wurde gel&ouml;scht und aus den Statistiken entfernt.</div><?php endif; ?>
  <?php if(isset($_GET['delete_error'])): ?><div class="alert alert-error"><?= e(substr((string)$_GET['delete_error'], 0, 350)) ?></div><?php endif; ?>
  <?php if(isset($_GET['merged'])): ?><div class="alert alert-ok">Fahrten erfolgreich zusammengef&uuml;hrt.</div><?php endif; ?>
  <?php if($quickSuggestion): ?>
    <section class="panel trip-merge-suggestion"><div class="panel-body">
      <div class="split-actions"><div><strong>💡 Kurzer Zwischenstopp erkannt</strong>
        <p>Die letzten beiden Fahrten liegen <?= (int)$quickSuggestion['gap_minutes'] ?> Minuten und <?= (int)$quickSuggestion['distance_meters'] ?> Meter auseinander. Du kannst daraus eine einzige Fahrt machen.</p></div>
<div>
          <button class="btn btn-primary" type="button" data-quick-trip-merge="<?= (int)$quickSuggestion['previous_trip_id'] ?>,<?= (int)$quickSuggestion['current_trip_id'] ?>">Fahrten ausw&auml;hlen</button>
        </div>
      </div></div></section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <h3>🛣️ Letzte Fahrten</h3>
      <div class="split-actions"><span class="pill ok">automatische Erkennung aktiv</span><?php if ($mayMergeTrips): ?><span class="trip-merge-head-hint">Fahrten per Checkbox verbinden</span><?php endif; ?></div>
    </div>
    <div class="panel-body">
      <?php if (!$rows): ?>
        <div class="empty">
          Noch keine Fahrt erkannt.<br>
          <span class="small">Eine Fahrt wird beim Streamen vorl&auml;ufig angelegt und bei Fahrtende erst ab der Mindeststrecke als echte Fahrt gewertet.</span>
        </div>
      <?php else: ?>
        <form id="tripMergeForm" method="post" action="trip-merge.php">
          <?= Csrf::field() ?><input type="hidden" name="action" value="merge">
          <?php if ($mayMergeTrips): ?><div id="tripMergeSelectedBar" class="trip-merge-actionbar" hidden aria-live="polite">
            <div class="trip-merge-action-copy"><strong id="tripMergeSelectedCount">0 Fahrten ausgew&auml;hlt</strong><span id="tripMergeSelectedHint">Zwei Fahrten ausw&auml;hlen.</span></div>
            <div class="trip-merge-action-buttons">
              <button class="btn btn-ghost" type="button" id="tripMergeReset">Auswahl aufheben</button>
              <button class="btn btn-primary" type="button" id="tripMergeOpen" disabled>Fahrten zusammenf&uuml;hren &rarr;</button>
            </div>
          </div>
          <?php endif; ?>
          <div class="trip-list" id="trip-list">
          <?php foreach ($rows as $row): ?>
            <?php
              $active = empty($row['ended_at']);
              $startSoc = $row['start_soc'] !== null ? (float)$row['start_soc'] : null;
              $endSoc = $row['end_soc'] !== null ? (float)$row['end_soc'] : null;
              $chargeOverlaps = TripSoc::chargeOverlaps($row);
              $socDelta = TripSoc::drivingDelta($startSoc, $endSoc, $chargeOverlaps);
              $startPlace = Geo::label($row['start_latitude'] ?? null, $row['start_longitude'] ?? null, $geoLocations, $geoFences);
              $endPlace = Geo::label($row['end_latitude'] ?? null, $row['end_longitude'] ?? null, $geoLocations, $geoFences);
            ?>
            <article class="trip-card trip-card-compact <?= $active ? 'active' : '' ?>" data-trip-id="<?= (int)$row['id'] ?>">
              <div class="trip-compact-row">
                <?php if ($mayMergeTrips): ?><label class="trip-compact-select" title="Fahrt zum Zusammenf&uuml;hren ausw&auml;hlen">
                  <input type="checkbox" name="trip_ids[]" value="<?= (int)$row['id'] ?>" aria-label="<?= e(($row['display_name'] ?: 'Tesla') . ' vom ' . $fmtLocal((string)$row['started_at'])) ?> zum Zusammenf&uuml;hren ausw&auml;hlen" data-trip-merge-choice data-car="<?= (int)$row['vehicle_id'] ?>" data-time="<?= e((string)$row['started_at']) ?>" data-from="<?= e((string)$startPlace['label']) ?>" data-to="<?= e((string)$endPlace['label']) ?>" data-ended="<?= $active ? '0' : '1' ?>" data-car-label="<?= e((string)($row['display_name'] ?: 'Tesla')) ?>">
                </label><?php endif; ?>
                <button class="trip-compact-toggle" type="button" data-trip-toggle aria-expanded="false" aria-controls="trip-expanded-<?= (int)$row['id'] ?>" title="Fahrtdetails anzeigen">
                  <span class="trip-compact-identity">
                    <strong><?= e($row['display_name'] ?: 'Tesla') ?></strong>
                    <time datetime="<?= e((string)$row['started_at']) ?>"><?= e($fmtLocal((string)$row['started_at'])) ?></time>
                  </span>
                  <span class="trip-compact-route" title="<?= e((string)$startPlace['label'] . ' → ' . (string)$endPlace['label']) ?>">
                    <span><?= e($shortPlace((string)$startPlace['label'])) ?></span>
                    <span class="trip-compact-route-arrow" aria-hidden="true">&rarr;</span>
                    <span><?= e($shortPlace((string)$endPlace['label'])) ?></span>
                  </span>
                  <span class="trip-compact-facts">
                    <span title="Strecke"><?= $row['distance_km'] !== null ? number_format((float)$row['distance_km'],1,',','.').' km' : '– km' ?></span>
                    <span title="Fahrtdauer"><?= e($duration($row)) ?></span>
                    <span title="Durchschnittlicher Verbrauch"><?= $row['avg_wh_km'] !== null ? number_format((float)$row['avg_wh_km'],0,',','.').' Wh/km' : '– Wh/km' ?></span>
                    <span title="H&ouml;chstgeschwindigkeit"><?= $row['max_speed_kmh'] !== null ? number_format((float)$row['max_speed_kmh'],0,',','.').' km/h max' : '– km/h' ?></span>
                    <?php if ($chargeOverlaps): ?><span class="trip-compact-soc trip-compact-soc-pause" title="Ladevorgang im Fahrtzeitraum: SoC-Aenderung ist keine reine Fahrbilanz">Ladepause &middot; SoC getrennt</span><?php elseif ($startSoc !== null || $endSoc !== null): ?><span class="trip-compact-soc" title="Akkustand vor und nach der Fahrt">SoC <?= $startSoc !== null ? number_format($startSoc,0,',','.') . '%' : '–' ?> → <?= $endSoc !== null ? number_format($endSoc,0,',','.') . '%' : '–' ?></span><?php endif; ?>
                  </span>
                  <span class="trip-compact-chevron" aria-hidden="true">⌄</span>
                </button>
                <?php if ($active): ?><span class="pill ok trip-compact-live">● Live</span><?php endif; ?>
              </div>
              <div class="trip-expanded" id="trip-expanded-<?= (int)$row['id'] ?>" hidden>
                <div class="trip-places-inline" aria-label="Vollst&auml;ndiger Start und Ziel der Fahrt">
                  <div class="trip-place-cell">
                    <span class="trip-place-icon">S</span>
                    <div class="trip-place-content"><span class="trip-place-heading">Start</span><a class="trip-place-name" href="map.php?trip_id=<?= (int)$row['id'] ?>" title="Auf Karte ansehen"><?= e($startPlace['label']) ?></a></div>
                  </div>
                  <span class="trip-place-arrow" aria-hidden="true">&rarr;</span>
                  <div class="trip-place-cell">
                    <span class="trip-place-icon is-end">Z</span>
                    <div class="trip-place-content"><span class="trip-place-heading">Ziel</span><a class="trip-place-name" href="map.php?trip_id=<?= (int)$row['id'] ?>" title="Auf Karte ansehen"><?= e($endPlace['label']) ?></a></div>
                  </div>
                </div>
                <?php if ($chargeOverlaps): ?><p class="trip-expanded-pause-note">Ladevorgang innerhalb des gespeicherten Fahrtzeitraums. Akkugewinn aus dem Laden wird nicht als Fahrverbrauch ausgewiesen. Die Fahrzeit kann kuerzer als der gesamte Zeitraum sein.</p><?php endif; ?>
                <div class="trip-expanded-meta">
                  <span><small>Zeitraum</small><strong><?= e($fmtLocal((string)$row['started_at'],true)) ?> – <?= $active ? 'l&auml;uft' : e($fmtLocal((string)$row['ended_at'],true)) ?></strong></span>
                  <span><small>Stream-Energie</small><strong><?= $row['energy_kwh'] !== null ? number_format((float)$row['energy_kwh'],2,',','.').' kWh' : '–' ?></strong></span>
                  <span><small>SoC</small><strong><?php if ($chargeOverlaps): ?>Nicht eindeutig (Ladepause)<?php else: ?><?= $startSoc !== null ? number_format($startSoc,0,',','.') . '%' : '–' ?> → <?= $endSoc !== null ? number_format($endSoc,0,',','.') . '%' : '–' ?><?php if ($socDelta !== null): ?> · <?= $socDelta > 0 ? '+' : '' ?><?= number_format($socDelta,0,',','.') ?> Pkt.<?php endif; ?><?php endif; ?></strong></span>
                  <span><small>Messdaten</small><strong><?= number_format((int)($row['sample_count'] ?? 0),0,',','.') ?> Stream-Pakete</strong></span>
                </div>
                <div class="trip-expanded-actions">
                  <span class="pill <?= $active ? 'ok' : '' ?>"><?= $active ? '● Live-Fahrt' : 'abgeschlossen' ?></span>
                  <?php if (!empty($row['journey_id'])): ?><a class="btn btn-ghost" href="journey.php?id=<?= (int)$row['journey_id'] ?>">🧳 <?= e((string)$row['journey_title']) ?></a><?php endif; ?>
                  <a class="btn btn-ghost" href="map.php?trip_id=<?= (int)$row['id'] ?>">Route auf Karte</a>
                  <a class="btn btn-primary" href="trip.php?id=<?= (int)$row['id'] ?>">Einzelfahrt ansehen &rarr;</a>
                  <?php if ($mayDeleteTrips && !$active): ?><button type="button" class="btn trip-delete-inline" data-trip-delete-id="<?= (int)$row['id'] ?>" data-trip-delete-label="<?= e(($row['display_name'] ?: 'Tesla') . ' / ' . $fmtLocal((string)$row['started_at'])) ?>">L&ouml;schen</button><?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>

   <?php if ($mayMergeTrips): ?><dialog id="tripMergeDialog" class="tf-merge-dialog tf-merge-review" aria-labelledby="mergeDialogTitle" aria-describedby="mergeDialogSummary">
     <div class="tf-merge-review-heading">
       <div><span class="kicker">Ein letzter Check</span><h3 id="mergeDialogTitle">Fahrten zusammenf&uuml;hren</h3></div>
       <button type="button" class="tf-merge-close" id="tripMergeClose" aria-label="Schlie&szlig;en">&times;</button>
     </div>
     <p id="mergeDialogSummary">Aus den ausgew&auml;hlten Fahrten wird genau eine Fahrt.</p>
     <div class="tf-merge-preview" aria-label="Geplanter Streckenverlauf">
       <div class="tf-merge-preview-place"><span>Start</span><strong id="tripMergePreviewStart">&ndash;</strong></div>
       <span class="tf-merge-preview-arrow" aria-hidden="true">&rarr;</span>
       <div class="tf-merge-preview-place"><span>Ziel</span><strong id="tripMergePreviewEnd">&ndash;</strong></div>
     </div>
     <div class="tf-merge-preview-info" id="tripMergePreviewInfo">2 Teilfahrten · 1 Zwischenstopp</div>
     <p class="tf-merge-preservation">Die bisherigen Eintr&auml;ge werden zu einer Fahrt. Kilometer und Verbrauch werden addiert, der Zwischenstopp wird nicht als Fahrzeit mitgez&auml;hlt. Die GPS-Rohdaten bleiben erhalten.</p>
     <div class="tf-merge-dialog-actions">
       <button class="btn btn-ghost" id="tripMergeCancel" type="button">Zur&uuml;ck</button>
       <button class="btn btn-primary" id="tripMergeSave" type="submit" form="tripMergeForm">Jetzt zusammenf&uuml;hren &rarr;</button>
     </div>
   </dialog><?php endif; ?>

  <?php if ($mayDeleteTrips): ?><?php require dirname(__DIR__) . '/src/trip-delete-dialog.php'; ?><?php endif; ?>
  <p class="trip-note">Energie und Wh/km werden in dieser ersten Ausbaustufe aus den Tesla-Stream-Leistungswerten integriert. Die Werte sind als Telemetrie-Auswertung gedacht und werden später mit weiteren Tesla-Daten verfeinert.</p>
</div>
<?php render_footer(); ?>
