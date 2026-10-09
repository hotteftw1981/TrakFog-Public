<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$id = max(0, (int)($_GET['id'] ?? 0));
$user = Auth::user($pdo);
$canEdit = in_array((string)($user['role'] ?? ''), ['owner','admin'], true);

$typeLabels = Journey::typeLabels();
$statusLabels = Journey::statusLabels();
$displayTimezone = new DateTimeZone(date_default_timezone_get());
$utcTimezone = new DateTimeZone('UTC');

$toUtc = static function (?string $value) use ($displayTimezone, $utcTimezone): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        return (new DateTimeImmutable($value, $displayTimezone))
            ->setTimezone($utcTimezone)
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
};

$fmtLocal = static function (?string $value, string $format = 'd.m.Y H:i') use ($displayTimezone, $utcTimezone): string {
    if (!$value) return '–';
    try {
        return (new DateTimeImmutable($value, $utcTimezone))
            ->setTimezone($displayTimezone)
            ->format($format);
    } catch (Throwable) {
        return (string)$value;
    }
};

$fmtInput = static function (?string $value) use ($displayTimezone, $utcTimezone): string {
    if (!$value) return '';
    try {
        return (new DateTimeImmutable($value, $utcTimezone))
            ->setTimezone($displayTimezone)
            ->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
};

$fmtDuration = static function (?int $seconds): string {
    if ($seconds === null) return '–';
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) return $days . ' T ' . $hours . ' h';
    if ($hours > 0) return $hours . ' h ' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' min';
    return max(1, $minutes) . ' min';
};

$loadJourney = static function (PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT j.*,v.display_name,v.vin
         FROM journeys j
         JOIN vehicles v ON v.id=j.vehicle_id
         WHERE j.id=?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
};

$journey = $loadJourney($pdo, $id);
if (!$journey) {
    ErrorPage::render(404, AppLogger::requestId(), 'Reise nicht gefunden.');
}

$message = match ((string)($_GET['status'] ?? '')) {
    'saved' => 'Reise gespeichert.',
    'started' => 'Reise gestartet. Neue Fahrten und Ladungen werden automatisch zugeordnet.',
    'completed' => 'Reise beendet und abschließend synchronisiert.',
    'reopened' => 'Reise wieder geöffnet.',
    'assigned' => 'Zuordnung gespeichert.',
    default => null,
};
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_details') {
                $title = trim((string)($_POST['title'] ?? ''));
                $type = (string)($_POST['type'] ?? 'travel');
                $destination = trim((string)($_POST['destination_label'] ?? ''));
                $plannedStart = $toUtc((string)($_POST['planned_start_at'] ?? ''));
                $plannedEnd = $toUtc((string)($_POST['planned_end_at'] ?? ''));
                $budgetRaw = str_replace(',', '.', trim((string)($_POST['budget_amount'] ?? '')));
                $budget = $budgetRaw !== '' && is_numeric($budgetRaw) ? max(0.0, (float)$budgetRaw) : null;
                $notes = trim((string)($_POST['notes'] ?? ''));
                $autoAssign = isset($_POST['auto_assign']) ? 1 : 0;

                if ($title === '') throw new RuntimeException('Bitte einen Namen angeben.');
                if (!array_key_exists($type, $typeLabels)) $type = 'travel';
                if ($plannedStart && $plannedEnd && strtotime($plannedEnd . ' UTC') < strtotime($plannedStart . ' UTC')) {
                    throw new RuntimeException('Das geplante Ende liegt vor dem Start.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE journeys
                     SET title=?,type=?,destination_label=?,
                         planned_start_at=?,planned_end_at=?,
                         auto_assign=?,budget_amount=?,notes=?
                     WHERE id=?"
                );
                $stmt->execute([
                    $title,$type,$destination !== '' ? $destination : null,
                    $plannedStart,$plannedEnd,$autoAssign,$budget,
                    $notes !== '' ? $notes : null,$id,
                ]);

                if ($autoAssign && (string)$journey['status'] === 'active') {
                    Journey::syncAutoAssignments($pdo, $id);
                }

                header('Location: journey.php?id=' . $id . '&status=saved');
                exit;
            }

            if ($action === 'start') {
                $activeStmt = $pdo->prepare(
                    "SELECT title FROM journeys WHERE vehicle_id=? AND status='active' AND id<>? LIMIT 1"
                );
                $activeStmt->execute([(int)$journey['vehicle_id'],$id]);
                $activeTitle = $activeStmt->fetchColumn();
                if ($activeTitle !== false) {
                    throw new RuntimeException('Für dieses Fahrzeug läuft bereits die Reise „' . (string)$activeTitle . '“.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE journeys
                     SET status='active',
                         started_at=COALESCE(
                           started_at,
                           CASE
                             WHEN planned_start_at IS NOT NULL AND planned_start_at<=UTC_TIMESTAMP() THEN planned_start_at
                             ELSE UTC_TIMESTAMP()
                           END
                         ),
                         ended_at=NULL
                     WHERE id=?"
                );
                $stmt->execute([$id]);
                Journey::syncAutoAssignments($pdo, $id);
                header('Location: journey.php?id=' . $id . '&status=started');
                exit;
            }

            if ($action === 'complete') {
                $stmt = $pdo->prepare(
                    "UPDATE journeys
                     SET status='completed',
                         started_at=COALESCE(
                           started_at,
                           CASE
                             WHEN planned_start_at IS NOT NULL AND planned_start_at<=UTC_TIMESTAMP() THEN planned_start_at
                             ELSE UTC_TIMESTAMP()
                           END
                         ),
                         ended_at=COALESCE(
                           ended_at,
                           CASE
                             WHEN planned_end_at IS NOT NULL AND planned_end_at<=UTC_TIMESTAMP() THEN planned_end_at
                             ELSE UTC_TIMESTAMP()
                           END
                         )
                     WHERE id=?"
                );
                $stmt->execute([$id]);
                Journey::syncAutoAssignments($pdo, $id);
                header('Location: journey.php?id=' . $id . '&status=completed');
                exit;
            }

            if ($action === 'reopen') {
                $activeStmt = $pdo->prepare(
                    "SELECT title FROM journeys WHERE vehicle_id=? AND status='active' AND id<>? LIMIT 1"
                );
                $activeStmt->execute([(int)$journey['vehicle_id'],$id]);
                $activeTitle = $activeStmt->fetchColumn();
                if ($activeTitle !== false) {
                    throw new RuntimeException('Für dieses Fahrzeug läuft bereits die Reise „' . (string)$activeTitle . '“.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE journeys
                     SET status='active',ended_at=NULL,
                         started_at=COALESCE(started_at,UTC_TIMESTAMP())
                     WHERE id=?"
                );
                $stmt->execute([$id]);
                Journey::syncAutoAssignments($pdo, $id);
                header('Location: journey.php?id=' . $id . '&status=reopened');
                exit;
            }

            if ($action === 'delete') {
                $pdo->prepare('DELETE FROM journeys WHERE id=?')->execute([$id]);
                header('Location: journeys.php');
                exit;
            }

            if ($action === 'save_assignments') {
                $journeyNow = $loadJourney($pdo, $id);
                if (!$journeyNow) throw new RuntimeException('Reise wurde nicht gefunden.');
                [$windowStart,$windowEnd] = Journey::candidateWindow($journeyNow);

                if (!$windowStart) {
                    $windowStart = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
                }
                if (!$windowEnd) {
                    $windowEnd = gmdate('Y-m-d H:i:s');
                }

                $tripCandidateStmt = $pdo->prepare(
                    "SELECT DISTINCT t.id
                     FROM trips t
                     LEFT JOIN journey_trips jt
                       ON jt.trip_id=t.id AND jt.journey_id=?
                     WHERE t.vehicle_id=?
                       AND (
                           (t.started_at>=? AND t.started_at<=?)
                           OR jt.trip_id IS NOT NULL
                       )"
                );
                $tripCandidateStmt->execute([$id,(int)$journeyNow['vehicle_id'],$windowStart,$windowEnd]);
                $tripCandidates = array_map('intval', array_column($tripCandidateStmt->fetchAll(), 'id'));

                $chargeCandidateStmt = $pdo->prepare(
                    "SELECT DISTINCT c.id
                     FROM charges c
                     LEFT JOIN journey_charges jc
                       ON jc.charge_id=c.id AND jc.journey_id=?
                     WHERE c.vehicle_id=?
                       AND (
                           (c.started_at>=? AND c.started_at<=?)
                           OR jc.charge_id IS NOT NULL
                       )"
                );
                $chargeCandidateStmt->execute([$id,(int)$journeyNow['vehicle_id'],$windowStart,$windowEnd]);
                $chargeCandidates = array_map('intval', array_column($chargeCandidateStmt->fetchAll(), 'id'));

                $selectedTrips = array_values(array_unique(array_map('intval', (array)($_POST['trip_ids'] ?? []))));
                $selectedCharges = array_values(array_unique(array_map('intval', (array)($_POST['charge_ids'] ?? []))));

                foreach ($tripCandidates as $tripId) {
                    Journey::setTrip($pdo, $id, $tripId, in_array($tripId, $selectedTrips, true));
                }
                foreach ($chargeCandidates as $chargeId) {
                    Journey::setCharge($pdo, $id, $chargeId, in_array($chargeId, $selectedCharges, true));
                }

                header('Location: journey.php?id=' . $id . '&status=assigned#assignment');
                exit;
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : report_exception($e, 'Aktion konnte nicht abgeschlossen werden.');
        }
    }
}

$journey = $loadJourney($pdo, $id);
if (!$journey) {
    ErrorPage::render(404, AppLogger::requestId(), 'Reise nicht gefunden.');
}

if ((string)$journey['status'] === 'active' && (int)$journey['auto_assign'] === 1) {
    Journey::syncAutoAssignments($pdo, $id);
}

$metrics = Journey::metrics($pdo, $id);
$locations = Geo::loadLocations($pdo);
$geofences = Geo::loadGeofences($pdo, true);

$tripStmt = $pdo->prepare(
    "SELECT t.*,jt.assignment_source
     FROM journey_trips jt
     JOIN trips t ON t.id=jt.trip_id
     WHERE jt.journey_id=? AND jt.included=1
     ORDER BY t.started_at"
);
$tripStmt->execute([$id]);
$assignedTrips = $tripStmt->fetchAll();

$chargeStmt = $pdo->prepare(
    "SELECT c.*,jc.assignment_source
     FROM journey_charges jc
     JOIN charges c ON c.id=jc.charge_id
     WHERE jc.journey_id=? AND jc.included=1
     ORDER BY c.started_at"
);
$chargeStmt->execute([$id]);
$assignedCharges = $chargeStmt->fetchAll();

$routePoints = Geo::routePoints(
    $pdo,
    array_map(static fn(array $row): int => (int)$row['id'], $assignedTrips),
    550
);

$tripPayload = [];
foreach ($assignedTrips as $trip) {
    $start = Geo::label($trip['start_latitude'] ?? null, $trip['start_longitude'] ?? null, $locations, $geofences);
    $end = Geo::label($trip['end_latitude'] ?? null, $trip['end_longitude'] ?? null, $locations, $geofences);
    $points = $routePoints[(int)$trip['id']] ?? [];

    if (!$points && is_numeric($trip['start_latitude'] ?? null) && is_numeric($trip['start_longitude'] ?? null)) {
        $points[] = ['lat'=>(float)$trip['start_latitude'],'lon'=>(float)$trip['start_longitude'],'at'=>(string)$trip['started_at']];
    }
    if (is_numeric($trip['end_latitude'] ?? null) && is_numeric($trip['end_longitude'] ?? null)) {
        $endLat = (float)$trip['end_latitude'];
        $endLon = (float)$trip['end_longitude'];
        $last = $points ? $points[count($points)-1] : null;
        if (!$last || abs((float)$last['lat']-$endLat)>0.00001 || abs((float)$last['lon']-$endLon)>0.00001) {
            $points[] = ['lat'=>$endLat,'lon'=>$endLon,'at'=>(string)($trip['ended_at'] ?? $trip['last_sample_at'] ?? '')];
        }
    }

    $tripPayload[] = [
        'id'=>(int)$trip['id'],
        'start'=>$start['label'],
        'end'=>$end['label'],
        'started_at'=>$fmtLocal((string)$trip['started_at']),
        'distance_km'=>$trip['distance_km'] !== null ? (float)$trip['distance_km'] : null,
        'points'=>$points,
    ];
}

$chargePayload = [];
foreach ($assignedCharges as $charge) {
    if (!is_numeric($charge['latitude'] ?? null) || !is_numeric($charge['longitude'] ?? null)) continue;
    $place = Geo::label(
        $charge['latitude'],
        $charge['longitude'],
        $locations,
        $geofences,
        (string)($charge['location_name'] ?? '')
    );
    $chargePayload[] = [
        'id'=>(int)$charge['id'],
        'lat'=>(float)$charge['latitude'],
        'lon'=>(float)$charge['longitude'],
        'label'=>$place['label'],
        'started_at'=>$fmtLocal((string)$charge['started_at']),
        'energy_kwh'=>$charge['energy_added_kwh'] !== null ? (float)$charge['energy_added_kwh'] : null,
        'cost'=>$charge['cost_amount'] !== null ? (float)$charge['cost_amount'] : null,
    ];
}

$timeline = [];
foreach ($assignedTrips as $trip) {
    $start = Geo::label($trip['start_latitude'] ?? null, $trip['start_longitude'] ?? null, $locations, $geofences);
    $end = Geo::label($trip['end_latitude'] ?? null, $trip['end_longitude'] ?? null, $locations, $geofences);
    $timeline[] = [
        'kind'=>'trip',
        'id'=>(int)$trip['id'],
        'at'=>(string)$trip['started_at'],
        'title'=>$start['label'] . ' → ' . $end['label'],
        'meta'=>($trip['distance_km'] !== null ? number_format((float)$trip['distance_km'],1,',','.') . ' km' : 'Strecke offen'),
        'source'=>(string)$trip['assignment_source'],
    ];
}
foreach ($assignedCharges as $charge) {
    $place = Geo::label(
        $charge['latitude'] ?? null,
        $charge['longitude'] ?? null,
        $locations,
        $geofences,
        (string)($charge['location_name'] ?? '')
    );
    $timeline[] = [
        'kind'=>'charge',
        'id'=>(int)$charge['id'],
        'at'=>(string)$charge['started_at'],
        'title'=>$place['label'],
        'meta'=>($charge['energy_added_kwh'] !== null ? number_format((float)$charge['energy_added_kwh'],1,',','.') . ' kWh' : 'Energie offen')
            . ($charge['cost_amount'] !== null ? ' · ' . number_format((float)$charge['cost_amount'],2,',','.') . ' €' : ''),
        'source'=>(string)$charge['assignment_source'],
    ];
}
usort($timeline, static fn(array $a,array $b): int => strcmp($a['at'],$b['at']));

[$windowStart,$windowEnd] = Journey::candidateWindow($journey);
$candidateStart = $windowStart ?: gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$candidateEnd = $windowEnd ?: gmdate('Y-m-d H:i:s');

$candidateTripStmt = $pdo->prepare(
    "SELECT t.*,
            jt.included AS journey_included,
            jt.assignment_source,
            (
              SELECT j2.title
              FROM journey_trips other
              JOIN journeys j2 ON j2.id=other.journey_id
              WHERE other.trip_id=t.id
                AND other.included=1
                AND other.journey_id<>?
              ORDER BY j2.id DESC
              LIMIT 1
            ) AS other_journey
     FROM trips t
     LEFT JOIN journey_trips jt ON jt.trip_id=t.id AND jt.journey_id=?
     WHERE t.vehicle_id=?
       AND ((t.started_at>=? AND t.started_at<=?) OR jt.trip_id IS NOT NULL)
     ORDER BY t.started_at DESC
     LIMIT 150"
);
$candidateTripStmt->execute([$id,$id,(int)$journey['vehicle_id'],$candidateStart,$candidateEnd]);
$candidateTrips = $candidateTripStmt->fetchAll();

$candidateChargeStmt = $pdo->prepare(
    "SELECT c.*,
            jc.included AS journey_included,
            jc.assignment_source,
            (
              SELECT j2.title
              FROM journey_charges other
              JOIN journeys j2 ON j2.id=other.journey_id
              WHERE other.charge_id=c.id
                AND other.included=1
                AND other.journey_id<>?
              ORDER BY j2.id DESC
              LIMIT 1
            ) AS other_journey
     FROM charges c
     LEFT JOIN journey_charges jc ON jc.charge_id=c.id AND jc.journey_id=?
     WHERE c.vehicle_id=?
       AND ((c.started_at>=? AND c.started_at<=?) OR jc.charge_id IS NOT NULL)
     ORDER BY c.started_at DESC
     LIMIT 150"
);
$candidateChargeStmt->execute([$id,$id,(int)$journey['vehicle_id'],$candidateStart,$candidateEnd]);
$candidateCharges = $candidateChargeStmt->fetchAll();

$journeyStart = $journey['started_at'] ?: $journey['planned_start_at'];
$journeyEnd = $journey['ended_at'] ?: (($journey['status'] === 'active') ? gmdate('Y-m-d H:i:s') : $journey['planned_end_at']);
$elapsedSeconds = null;
if ($journeyStart && $journeyEnd) {
    try {
        $elapsedSeconds = max(0, strtotime((string)$journeyEnd . ' UTC') - strtotime((string)$journeyStart . ' UTC'));
    } catch (Throwable) {}
}

$budget = $journey['budget_amount'] !== null ? (float)$journey['budget_amount'] : null;
$budgetPercent = $budget !== null && $budget > 0
    ? min(100, ((float)$metrics['charging_cost'] / $budget) * 100)
    : null;

$status = (string)$journey['status'];
$type = (string)$journey['type'];
$isActive = $status === 'active';

render_header((string)$journey['title'], 'journeys', true);
?>
<div class="page wrap journey-detail-page">
  <div class="page-head">
    <div>
      <span class="kicker"><?= e($typeLabels[$type] ?? 'Reise') ?> · #<?= $id ?></span>
      <h1><?= e((string)$journey['title']) ?></h1>
      <p>
        <?= e((string)($journey['display_name'] ?: 'Tesla')) ?>
        · <?= $journeyStart ? e($fmtLocal((string)$journeyStart,'d.m.Y')) : 'Start offen' ?>
        → <?= $isActive ? 'läuft' : ($journeyEnd ? e($fmtLocal((string)$journeyEnd,'d.m.Y')) : 'Ende offen') ?>
        <?php if (!empty($journey['destination_label'])): ?> · <?= e((string)$journey['destination_label']) ?><?php endif; ?>
      </p>
    </div>
    <div class="split-actions">
      <span class="pill <?= $isActive ? 'ok' : ($status === 'planned' ? 'warn' : '') ?>">
        <?= $isActive ? '● ' : '' ?><?= e($statusLabels[$status] ?? $status) ?>
      </span>
      <a class="btn btn-ghost" href="journeys.php">← Reisen</a>
      <?php if ($canEdit && $status === 'planned'): ?>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="start"><button class="btn btn-primary" type="submit">▶ Reise starten</button></form>
      <?php elseif ($canEdit && $status === 'active'): ?>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="complete"><button class="btn btn-primary" type="submit">✓ Reise beenden</button></form>
      <?php elseif ($canEdit && $status === 'completed'): ?>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="reopen"><button class="btn btn-ghost" type="submit">↺ Wieder öffnen</button></form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <div class="journey-kpi-grid">
    <a class="stat tf-stat-link" href="#journey-map" title="Details zu Gesamtstrecke" aria-label="Details zu Gesamtstrecke öffnen"><strong><?= number_format((float)$metrics['distance_km'],1,',','.') ?> km</strong><span>Gesamtstrecke</span></a>
    <a class="stat tf-stat-link" href="#journey-timeline" title="Details zu Fahrten" aria-label="Details zu Fahrten öffnen"><strong><?= (int)$metrics['trip_count'] ?></strong><span>Fahrten</span></a>
    <a class="stat tf-stat-link" href="#journey-timeline" title="Details zu Ladestopps" aria-label="Details zu Ladestopps öffnen"><strong><?= (int)$metrics['charge_count'] ?></strong><span>Ladestopps</span></a>
    <a class="stat tf-stat-link" href="#journey-timeline" title="Details zu geladen" aria-label="Details zu geladen öffnen"><strong><?= number_format((float)$metrics['charged_kwh'],1,',','.') ?> kWh</strong><span>geladen</span></a>
    <a class="stat tf-stat-link" href="#journey-balance" title="Details zu Ladekosten" aria-label="Details zu Ladekosten öffnen"><strong><?= (int)$metrics['confirmed_cost_count'] > 0 ? number_format((float)$metrics['charging_cost'],2,',','.').' €' : '–' ?></strong><span>Ladekosten</span></a>
    <a class="stat tf-stat-link" href="#journey-balance" title="Details zu pro 100 km" aria-label="Details zu pro 100 km öffnen"><strong><?= $metrics['cost_per_100km'] !== null ? number_format((float)$metrics['cost_per_100km'],2,',','.').' €' : '–' ?></strong><span>pro 100 km</span></a>
  </div>

  <div class="journey-overview-grid">
    <section class="panel journey-map-panel">
      <div class="panel-head">
        <div><h3>🗺️ Gesamtroute</h3><span class="small"><?= (int)$metrics['trip_count'] ?> Fahrten · <?= (int)$metrics['charge_count'] ?> Ladestopps</span></div>
        <span class="pill"><?= (int)$journey['auto_assign'] === 1 ? 'Auto-Zuordnung aktiv' : 'manuell' ?></span>
      </div>
      <div class="panel-body" style="padding:0">
        <div id="journey-map" class="trakfog-map journey-map"></div>
      </div>
    </section>

    <aside class="panel journey-summary-panel" id="journey-balance">
      <div class="panel-head"><h3>📊 Reisebilanz</h3></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>Reisedauer</span><b><?= e($fmtDuration($elapsedSeconds)) ?></b></div>
          <div class="data-row"><span>Fahrzeit</span><b><?= e($fmtDuration((int)$metrics['drive_seconds'])) ?></b></div>
          <div class="data-row"><span>Ladezeit</span><b><?= e($fmtDuration((int)$metrics['charge_seconds'])) ?></b></div>
          <div class="data-row"><span>Längste Fahrt</span><b><?= number_format((float)$metrics['longest_trip_km'],1,',','.') ?> km</b></div>
          <div class="data-row"><span>Längster Ladestopp</span><b><?= e($fmtDuration((int)$metrics['longest_charge_seconds'])) ?></b></div>
          <div class="data-row"><span>Ø Verbrauch</span><b><?= $metrics['avg_wh_km'] !== null ? number_format((float)$metrics['avg_wh_km'],0,',','.').' Wh/km' : '–' ?></b></div>
        </div>

        <?php if ($budget !== null): ?>
          <div class="journey-budget">
            <div><span>Ladebudget</span><b><?= number_format((float)$metrics['charging_cost'],2,',','.') ?> / <?= number_format($budget,2,',','.') ?> €</b></div>
            <div class="journey-budget-bar"><i style="width:<?= number_format((float)$budgetPercent,2,'.','') ?>%"></i></div>
            <small><?= $budgetPercent !== null ? number_format($budgetPercent,0,',','.') . ' % verbraucht' : '–' ?></small>
          </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <section class="panel journey-timeline-panel" id="journey-timeline">
    <div class="panel-head">
      <div><h3>🧭 Reiseverlauf</h3><span class="small">Fahrten und Ladestopps chronologisch</span></div>
      <span class="pill"><?= count($timeline) ?> Ereignisse</span>
    </div>
    <div class="panel-body">
      <?php if (!$timeline): ?>
        <div class="empty">Noch keine Fahrt oder Ladung dieser Reise zugeordnet.</div>
      <?php else: ?>
        <div class="journey-timeline">
          <?php foreach ($timeline as $event): ?>
            <article class="journey-timeline-item <?= e($event['kind']) ?>">
              <span class="journey-timeline-icon"><?= $event['kind'] === 'trip' ? '↗' : '⚡' ?></span>
              <div>
                <small><?= e($fmtLocal((string)$event['at'])) ?> · <?= $event['source'] === 'auto' ? 'automatisch' : 'manuell' ?></small>
                <strong><?= e((string)$event['title']) ?></strong>
                <span><?= e((string)$event['meta']) ?></span>
              </div>
              <a class="btn btn-ghost" href="<?= $event['kind'] === 'trip' ? 'trip.php?id=' : 'charge.php?id=' ?><?= (int)$event['id'] ?>">Details →</a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($canEdit): ?>
  <section class="panel" id="assignment">
    <div class="panel-head">
      <div>
        <h3>🔗 Fahrten & Ladungen zuordnen</h3>
        <span class="small">
          Zeitraum <?= e($fmtLocal($candidateStart)) ?> → <?= e($fmtLocal($candidateEnd)) ?>
          · manuelle Abwahl bleibt auch bei Auto-Zuordnung erhalten
        </span>
      </div>
      <span class="pill"><?= count($candidateTrips) ?> Fahrten · <?= count($candidateCharges) ?> Ladungen</span>
    </div>
    <div class="panel-body">
      <form method="post" class="journey-assignment-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_assignments">

        <div class="journey-assignment-grid">
          <div>
            <h4>🛣️ Fahrten</h4>
            <?php if (!$candidateTrips): ?>
              <div class="empty compact">Keine Fahrten im Reisezeitraum.</div>
            <?php else: ?>
              <div class="journey-check-list">
                <?php foreach ($candidateTrips as $trip): ?>
                  <?php
                    $start = Geo::label($trip['start_latitude'] ?? null,$trip['start_longitude'] ?? null,$locations,$geofences);
                    $end = Geo::label($trip['end_latitude'] ?? null,$trip['end_longitude'] ?? null,$locations,$geofences);
                    $checked = (int)($trip['journey_included'] ?? 0) === 1;
                  ?>
                  <label class="journey-check-row <?= $checked ? 'selected' : '' ?>">
                    <input type="checkbox" name="trip_ids[]" value="<?= (int)$trip['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                    <span>
                      <strong><?= e($start['label']) ?> → <?= e($end['label']) ?></strong>
                      <small><?= e($fmtLocal((string)$trip['started_at'])) ?> · <?= $trip['distance_km'] !== null ? number_format((float)$trip['distance_km'],1,',','.').' km' : '–' ?></small>
                      <?php if (!empty($trip['other_journey'])): ?><em>aktuell in „<?= e((string)$trip['other_journey']) ?>“</em><?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div>
            <h4>⚡ Ladungen</h4>
            <?php if (!$candidateCharges): ?>
              <div class="empty compact">Keine Ladungen im Reisezeitraum.</div>
            <?php else: ?>
              <div class="journey-check-list">
                <?php foreach ($candidateCharges as $charge): ?>
                  <?php
                    $place = Geo::label(
                        $charge['latitude'] ?? null,$charge['longitude'] ?? null,
                        $locations,$geofences,(string)($charge['location_name'] ?? '')
                    );
                    $checked = (int)($charge['journey_included'] ?? 0) === 1;
                  ?>
                  <label class="journey-check-row <?= $checked ? 'selected' : '' ?>">
                    <input type="checkbox" name="charge_ids[]" value="<?= (int)$charge['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                    <span>
                      <strong><?= e($place['label']) ?></strong>
                      <small><?= e($fmtLocal((string)$charge['started_at'])) ?> · <?= $charge['energy_added_kwh'] !== null ? number_format((float)$charge['energy_added_kwh'],1,',','.').' kWh' : '–' ?></small>
                      <?php if (!empty($charge['other_journey'])): ?><em>aktuell in „<?= e((string)$charge['other_journey']) ?>“</em><?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="split-actions journey-assignment-actions">
          <button class="btn btn-primary" type="submit">Zuordnung speichern</button>
          <span>Ein ausgewählter Eintrag wird bei Bedarf aus einer anderen Reise hierher verschoben.</span>
        </div>
      </form>
    </div>
  </section>

  <section class="panel journey-settings-panel">
    <div class="panel-head"><h3>⚙️ Reise bearbeiten</h3></div>
    <div class="panel-body">
      <form method="post" class="journey-edit-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_details">

        <div class="journey-form-grid">
          <label class="journey-form-wide"><span>Name</span><input name="title" value="<?= e((string)$journey['title']) ?>" required></label>
          <label>
            <span>Art</span>
            <select name="type">
              <?php foreach ($typeLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="journey-form-wide"><span>Ziel / Bezeichnung</span><input name="destination_label" value="<?= e((string)($journey['destination_label'] ?? '')) ?>"></label>
          <label><span>Geplanter Start</span><input name="planned_start_at" type="datetime-local" value="<?= e($fmtInput($journey['planned_start_at'] ?? null)) ?>"></label>
          <label><span>Geplantes Ende</span><input name="planned_end_at" type="datetime-local" value="<?= e($fmtInput($journey['planned_end_at'] ?? null)) ?>"></label>
          <label><span>Ladebudget €</span><input name="budget_amount" inputmode="decimal" value="<?= $budget !== null ? e(number_format($budget,2,',','')) : '' ?>"></label>
          <label class="check-chip journey-check"><input type="checkbox" name="auto_assign" value="1" <?= (int)$journey['auto_assign'] === 1 ? 'checked' : '' ?>><span>Neue Fahrten & Ladungen automatisch zuordnen</span></label>
          <label class="journey-form-wide"><span>Notiz</span><textarea name="notes" rows="4"><?= e((string)($journey['notes'] ?? '')) ?></textarea></label>
        </div>

        <div class="split-actions">
          <button class="btn btn-primary" type="submit">Änderungen speichern</button>
        </div>
      </form>

      <div class="journey-danger-zone">
        <div><strong>Reise löschen</strong><span>Fahrten und Ladevorgänge selbst bleiben erhalten; nur die Zuordnung wird entfernt.</span></div>
        <form method="post" onsubmit="return confirm('Reise wirklich löschen? Fahrten und Ladevorgänge bleiben erhalten.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-danger" type="submit">Reise löschen</button>
        </form>
      </div>
    </div>
  </section>
  <?php endif; ?>
</div>

<script>
(() => {
  const trips=<?= json_encode($tripPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const charges=<?= json_encode($chargePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  const map=L.map('journey-map',{preferCanvas:true}).setView([51.16,10.45],6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    maxZoom:19,
    attribution:'&copy; OpenStreetMap'
  }).addTo(map);

  const bounds=[];
  const icon=(kind,label)=>L.divIcon({
    className:'tf-map-icon-wrap',
    html:'<span class="tf-map-icon '+kind+'">'+label+'</span>',
    iconSize:[34,34],
    iconAnchor:[17,17],
    popupAnchor:[0,-14]
  });
  const esc=value=>String(value ?? '').replace(/[&<>"']/g,ch=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[ch]));

  trips.forEach((trip,index)=>{
    const points=(trip.points || []).map(p=>[Number(p.lat),Number(p.lon)])
      .filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]));
    if (!points.length) return;
    points.forEach(p=>bounds.push(p));
    const line=L.polyline(points,{
      className:'tf-trip-line',
      weight:4,
      opacity:.86,
      lineCap:'round',
      lineJoin:'round'
    }).addTo(map);
    line.bindPopup(
      '<div class="tf-map-popup"><span class="popup-kicker">Fahrt '+(index+1)+'</span>'+
      '<strong>'+esc(trip.start)+' → '+esc(trip.end)+'</strong>'+
      '<small>'+esc(trip.started_at)+' · '+(trip.distance_km==null?'–':Number(trip.distance_km).toLocaleString('de-DE',{maximumFractionDigits:1})+' km')+'</small>'+
      '<a href="trip.php?id='+Number(trip.id)+'">Fahrt öffnen →</a></div>'
    );
  });

  charges.forEach((charge,index)=>{
    const lat=Number(charge.lat),lon=Number(charge.lon);
    if(!Number.isFinite(lat)||!Number.isFinite(lon)) return;
    bounds.push([lat,lon]);
    L.marker([lat,lon],{icon:icon('charge','⚡')}).addTo(map).bindPopup(
      '<div class="tf-map-popup"><span class="popup-kicker">Ladestopp '+(index+1)+'</span>'+
      '<strong>'+esc(charge.label)+'</strong>'+
      '<small>'+esc(charge.started_at)+'</small>'+
      '<a href="charge.php?id='+Number(charge.id)+'">Ladung öffnen →</a></div>'
    );
  });

  if(bounds.length===1) map.setView(bounds[0],14);
  else if(bounds.length>1) map.fitBounds(bounds,{padding:[38,38],maxZoom:16});

  document.querySelectorAll('.journey-check-row input').forEach(input=>{
    input.addEventListener('change',()=>input.closest('.journey-check-row')?.classList.toggle('selected',input.checked));
  });

  requestAnimationFrame(()=>map.invalidateSize());
})();
</script>
<?php render_footer(); ?>
