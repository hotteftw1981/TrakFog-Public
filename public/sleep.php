<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$vehicles = $pdo->query(
    "SELECT id,display_name,state,battery_level,rated_range_km,last_seen_at
     FROM vehicles
     ORDER BY display_name,id"
)->fetchAll();

$selectedVehicleId = max(0, (int)($_GET['vehicle_id'] ?? 0));
if ($selectedVehicleId === 0 && $vehicles) {
    $selectedVehicleId = (int)$vehicles[0]['id'];
}

$period = (string)($_GET['period'] ?? '30d');
$periods = [
    '7d' => ['label' => '7 Tage', 'days' => 7],
    '30d' => ['label' => '30 Tage', 'days' => 30],
    '365d' => ['label' => '1 Jahr', 'days' => 365],
    'all' => ['label' => 'Gesamt', 'days' => null],
];
if (!isset($periods[$period])) $period = '30d';

$selectedVehicle = null;
foreach ($vehicles as $vehicle) {
    if ((int)$vehicle['id'] === $selectedVehicleId) {
        $selectedVehicle = $vehicle;
        break;
    }
}

$displayTimezone = new DateTimeZone(date_default_timezone_get());
$utc = new DateTimeZone('UTC');

$fmtLocal = static function (?string $value, bool $withSeconds = false) use ($displayTimezone, $utc): string {
    if (!$value) return '–';
    try {
        return (new DateTimeImmutable($value, $utc))
            ->setTimezone($displayTimezone)
            ->format($withSeconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i');
    } catch (Throwable) {
        return (string)$value;
    }
};

$fmtDuration = static function (?int $seconds): string {
    if ($seconds === null || $seconds < 0) return '–';
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) return $days . ' T ' . $hours . ' h';
    if ($hours > 0) return $hours . ' h ' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' min';
    return max(1, $minutes) . ' min';
};

$cutoff = null;
if ($periods[$period]['days'] !== null) {
    $cutoff = (new DateTimeImmutable('now', $utc))
        ->modify('-' . (int)$periods[$period]['days'] . ' days')
        ->format('Y-m-d H:i:s');
}

$where = ['vehicle_id=?'];
$params = [$selectedVehicleId];
if ($cutoff !== null) {
    $where[] = 'started_at>=?';
    $params[] = $cutoff;
}
$whereSql = implode(' AND ', $where);

$sessions = [];
$events = [];
$currentSleep = null;
$metrics = [
    'valid_count' => 0,
    'excluded_count' => 0,
    'insufficient_count' => 0,
    'sleep_seconds' => 0,
    'total_drain' => 0.0,
    'avg_drain_day' => null,
    'longest_seconds' => 0,
    'wakeups' => 0,
];

if ($selectedVehicleId > 0) {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM sleep_sessions
         WHERE {$whereSql}
         ORDER BY started_at DESC
         LIMIT 120"
    );
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();

    $openStmt = $pdo->prepare(
        "SELECT *
         FROM sleep_sessions
         WHERE vehicle_id=? AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1"
    );
    $openStmt->execute([$selectedVehicleId]);
    $currentSleep = $openStmt->fetch() ?: null;

    $metricSql =
        "SELECT
            SUM(CASE WHEN quality='valid' THEN 1 ELSE 0 END) AS valid_count,
            SUM(CASE WHEN quality='excluded' THEN 1 ELSE 0 END) AS excluded_count,
            SUM(CASE WHEN quality='insufficient' THEN 1 ELSE 0 END) AS insufficient_count,
            COALESCE(SUM(CASE WHEN quality='valid' THEN duration_seconds ELSE 0 END),0) AS sleep_seconds,
            COALESCE(SUM(CASE WHEN quality='valid' THEN drain_percent ELSE 0 END),0) AS total_drain,
            COALESCE(MAX(CASE WHEN quality='valid' THEN duration_seconds ELSE NULL END),0) AS longest_seconds
         FROM sleep_sessions
         WHERE {$whereSql}";
    $metricStmt = $pdo->prepare($metricSql);
    $metricStmt->execute($params);
    $row = $metricStmt->fetch() ?: [];

    $metrics['valid_count'] = (int)($row['valid_count'] ?? 0);
    $metrics['excluded_count'] = (int)($row['excluded_count'] ?? 0);
    $metrics['insufficient_count'] = (int)($row['insufficient_count'] ?? 0);
    $metrics['sleep_seconds'] = (int)($row['sleep_seconds'] ?? 0);
    $metrics['total_drain'] = (float)($row['total_drain'] ?? 0);
    $metrics['longest_seconds'] = (int)($row['longest_seconds'] ?? 0);
    $metrics['avg_drain_day'] = $metrics['sleep_seconds'] > 0
        ? ($metrics['total_drain'] * 86400 / $metrics['sleep_seconds'])
        : null;

    $eventWhere = ['vehicle_id=?', "to_state='online'"];
    $eventParams = [$selectedVehicleId];
    if ($cutoff !== null) {
        $eventWhere[] = 'observed_at>=?';
        $eventParams[] = $cutoff;
    }
    $eventStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM vehicle_state_events WHERE ' . implode(' AND ', $eventWhere)
    );
    $eventStmt->execute($eventParams);
    $metrics['wakeups'] = (int)$eventStmt->fetchColumn();

    $eventsStmt = $pdo->prepare(
        "SELECT *
         FROM vehicle_state_events
         WHERE vehicle_id=?
         ORDER BY observed_at DESC
         LIMIT 18"
    );
    $eventsStmt->execute([$selectedVehicleId]);
    $events = $eventsStmt->fetchAll();
}

$currentSleepSeconds = null;
if ($currentSleep) {
    try {
        $start = new DateTimeImmutable((string)$currentSleep['started_at'], $utc);
        $currentSleepSeconds = max(0, time() - $start->getTimestamp());
    } catch (Throwable) {
        $currentSleepSeconds = null;
    }
}

$qualityLabels = [
    'valid' => ['label' => 'gültig', 'class' => 'ok'],
    'excluded' => ['label' => 'ausgeschlossen', 'class' => 'warn'],
    'insufficient' => ['label' => 'zu wenig Daten', 'class' => 'sleep'],
    'pending' => ['label' => 'läuft', 'class' => 'ok'],
];
$reasonLabels = [
    'too_short' => 'zu kurz',
    'charging' => 'Ladung im Zeitraum',
    'movement' => 'Fahrzeug bewegt',
    'missing_soc' => 'SoC fehlt',
];

$drainStatus = 'Noch keine belastbare Rate';
$drainClass = 'sleep';
if ($metrics['avg_drain_day'] !== null) {
    if ($metrics['avg_drain_day'] < 0.5) {
        $drainStatus = 'sehr niedrig';
        $drainClass = 'ok';
    } elseif ($metrics['avg_drain_day'] < 1.5) {
        $drainStatus = 'unauffällig';
        $drainClass = '';
    } elseif ($metrics['avg_drain_day'] < 3.0) {
        $drainStatus = 'erhöht';
        $drainClass = 'warn';
    } else {
        $drainStatus = 'auffällig';
        $drainClass = 'danger';
    }
}

render_header('Sleep & Phantom Drain', 'sleep');
?>
<div class="page wrap sleep-page" data-live-region="page-sleep" data-live-poll="10000">
  <div class="page-head">
    <div>
      <span class="kicker">Auswertung</span>
      <h1>Sleep & Phantom Drain.</h1>
      <p>Wie lange dein Tesla wirklich schläft, wie oft er aufwacht und wie viel SoC während belastbarer Schlafphasen verschwindet – ohne zusätzliche Wake-ups durch diese Ansicht.</p>
    </div>
    <div class="split-actions">
      <?php if ($currentSleep): ?><span class="pill ok">☾ schläft seit <?= e($fmtDuration($currentSleepSeconds)) ?></span><?php endif; ?>
      <span class="pill <?= e($drainClass) ?>"><?= e($drainStatus) ?></span>
    </div>
  </div>

  <form class="analytics-filter panel sleep-filter" method="get">
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
    <div class="analytics-periods">
      <?php foreach ($periods as $key => $meta): ?>
        <a class="btn <?= $period === $key ? 'btn-primary' : 'btn-ghost' ?>" href="?vehicle_id=<?= $selectedVehicleId ?>&period=<?= e($key) ?>"><?= e($meta['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </form>

  <?php if (!$selectedVehicle): ?>
    <section class="panel"><div class="panel-body"><div class="empty">Noch kein Tesla vorhanden.</div></div></section>
  <?php else: ?>
    <div class="sleep-hero-grid">
      <section class="panel sleep-now-card <?= $currentSleep ? 'sleeping' : '' ?>">
        <div class="panel-head">
          <div><h3>☾ Aktueller Zustand</h3><span class="small"><?= e((string)($selectedVehicle['display_name'] ?: 'Tesla')) ?></span></div>
          <span class="pill <?= in_array(strtolower((string)$selectedVehicle['state']), ['asleep','offline'], true) ? 'sleep' : 'ok' ?>"><?= e((string)($selectedVehicle['state'] ?: 'unbekannt')) ?></span>
        </div>
        <div class="panel-body">
          <?php if ($currentSleep): ?>
            <div class="sleep-now-value"><?= e($fmtDuration($currentSleepSeconds)) ?></div>
            <div class="sleep-now-label">aktuelle Ruhephase</div>
            <div class="sleep-now-meta">
              <span>Start <?= e($fmtLocal((string)$currentSleep['started_at'])) ?></span>
              <span>Start-SoC <?= $currentSleep['start_soc'] !== null ? number_format((float)$currentSleep['start_soc'],0,',','.').' %' : '–' ?></span>
              <span>Range <?= $currentSleep['start_range_km'] !== null ? number_format((float)$currentSleep['start_range_km'],0,',','.').' km' : '–' ?></span>
            </div>
          <?php else: ?>
            <div class="sleep-now-value">wach</div>
            <div class="sleep-now-label">keine laufende Schlafphase</div>
            <div class="sleep-now-meta">
              <span>SoC <?= $selectedVehicle['battery_level'] !== null ? number_format((float)$selectedVehicle['battery_level'],0,',','.').' %' : '–' ?></span>
              <span>Range <?= $selectedVehicle['rated_range_km'] !== null ? number_format((float)$selectedVehicle['rated_range_km'],0,',','.').' km' : '–' ?></span>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <div class="stat-grid sleep-stat-grid">
        <a class="stat tf-stat-link" href="#sleep-detail" title="Details zu Phantom Drain · gültig" aria-label="Details zu Phantom Drain · gültig öffnen"><strong><?= number_format($metrics['total_drain'],1,',','.') ?> %</strong><span>Phantom Drain · gültig</span></a>
        <a class="stat tf-stat-link" href="#sleep-detail" title="Details zu gewichtete Drain-Rate" aria-label="Details zu gewichtete Drain-Rate öffnen"><strong><?= $metrics['avg_drain_day'] !== null ? number_format($metrics['avg_drain_day'],2,',','.').' %/Tag' : '–' ?></strong><span>gewichtete Drain-Rate</span></a>
        <a class="stat tf-stat-link" href="#sleep-detail" title="Details zu gültige Schlafzeit" aria-label="Details zu gültige Schlafzeit öffnen"><strong><?= e($fmtDuration($metrics['sleep_seconds'])) ?></strong><span>gültige Schlafzeit</span></a>
        <a class="stat tf-stat-link" href="#sleep-detail" title="Details zu Wake-ups" aria-label="Details zu Wake-ups öffnen"><strong><?= number_format($metrics['wakeups'],0,',','.') ?></strong><span>Wake-ups</span></a>
      </div>
    </div>

    <div class="grid-2 sleep-detail-grid" id="sleep-detail">
      <section class="panel">
        <div class="panel-head"><h3>👻 Phantom-Drain-Qualität</h3><span class="pill"><?= e($periods[$period]['label']) ?></span></div>
        <div class="panel-body">
          <div class="data-list">
            <div class="data-row"><span>gültige Schlafphasen</span><b><?= number_format($metrics['valid_count'],0,',','.') ?></b></div>
            <div class="data-row"><span>längste gültige Schlafphase</span><b><?= e($fmtDuration($metrics['longest_seconds'])) ?></b></div>
            <div class="data-row"><span>ausgeschlossen</span><b><?= number_format($metrics['excluded_count'],0,',','.') ?></b></div>
            <div class="data-row"><span>zu wenig Daten</span><b><?= number_format($metrics['insufficient_count'],0,',','.') ?></b></div>
          </div>
          <p class="sleep-info">Für Phantom Drain zählen nur Ruhephasen ab 15 Minuten mit verwertbarem Start-/End-SoC. Zeiträume mit Ladung oder erkennbarer Fahrzeugbewegung werden automatisch aus der Drain-Berechnung genommen.</p>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>📡 Letzte Zustandswechsel</h3><span class="pill"><?= count($events) ?></span></div>
        <div class="panel-body">
          <?php if (!$events): ?>
            <div class="empty">Noch keine Zustandswechsel seit Aktivierung der Sleep-Analyse.</div>
          <?php else: ?>
            <div class="state-event-list">
              <?php foreach ($events as $event): ?>
                <div class="state-event-row">
                  <span><?= e($fmtLocal((string)$event['observed_at'], true)) ?></span>
                  <b><?= e((string)($event['from_state'] ?: '–')) ?> → <?= e((string)$event['to_state']) ?></b>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="panel sleep-history-panel">
      <div class="panel-head">
        <div><h3>🌙 Schlafhistorie</h3><span class="small">Phantom Drain wird nur aus belastbaren Sessions berechnet</span></div>
        <span class="pill"><?= count($sessions) ?> angezeigt</span>
      </div>
      <div class="panel-body">
        <?php if (!$sessions): ?>
          <div class="empty">Noch keine Schlafsession vorhanden.<br><span class="small">Die Analyse beginnt mit V0.1.1.23 und rekonstruiert bewusst keine unsicheren historischen Schlafphasen.</span></div>
        <?php else: ?>
          <div class="sleep-session-list">
            <?php foreach ($sessions as $session): ?>
              <?php
                $quality = $qualityLabels[(string)$session['quality']] ?? ['label' => (string)$session['quality'], 'class' => ''];
                $reason = $reasonLabels[(string)($session['excluded_reason'] ?? '')] ?? null;
                $durationSeconds = $session['duration_seconds'] !== null
                    ? (int)$session['duration_seconds']
                    : ($session['ended_at'] === null ? $currentSleepSeconds : null);
                $drain = $session['drain_percent'] !== null ? (float)$session['drain_percent'] : null;
                $rate = $session['drain_percent_per_day'] !== null ? (float)$session['drain_percent_per_day'] : null;
              ?>
              <article class="sleep-session-card">
                <div class="sleep-session-head">
                  <div>
                    <strong><?= e($fmtLocal((string)$session['started_at'])) ?></strong>
                    <span>→ <?= $session['ended_at'] ? e($fmtLocal((string)$session['ended_at'])) : 'läuft' ?> · <?= e($fmtDuration($durationSeconds)) ?></span>
                  </div>
                  <div class="split-actions">
                    <span class="pill <?= e($quality['class']) ?>"><?= e($quality['label']) ?></span>
                    <?php if ($reason): ?><span class="pill"><?= e($reason) ?></span><?php endif; ?>
                  </div>
                </div>
                <div class="sleep-session-metrics">
                  <div><span>SoC</span><b><?= $session['start_soc'] !== null ? number_format((float)$session['start_soc'],0,',','.').'%' : '–' ?> → <?= $session['end_soc'] !== null ? number_format((float)$session['end_soc'],0,',','.').'%' : ($session['ended_at'] ? '–' : '…') ?></b></div>
                  <div><span>Phantom Drain</span><b><?= $drain !== null ? number_format($drain,1,',','.').' %' : '–' ?></b></div>
                  <div><span>Rate</span><b><?= $rate !== null ? number_format($rate,2,',','.').' %/Tag' : '–' ?></b></div>
                  <div><span>Range-Verlust</span><b><?= $session['drain_range_km'] !== null ? number_format((float)$session['drain_range_km'],1,',','.').' km' : '–' ?></b></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <p class="analytics-note">Sleep & Phantom Drain benutzt nur die ohnehin laufende, schlaf-freundliche TrakFog Engine. Diese Seite fragt den Tesla nicht zusätzlich ab und löst keinen Wake-up aus.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
