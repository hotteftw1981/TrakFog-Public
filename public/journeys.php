<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$user = Auth::user($pdo);
$canEdit = in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
$message = null;
$error = null;

$typeLabels = Journey::typeLabels();
$statusLabels = Journey::statusLabels();
$displayTimezone = new DateTimeZone(date_default_timezone_get());

$toUtc = static function (?string $value) use ($displayTimezone): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        return (new DateTimeImmutable($value, $displayTimezone))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
};

$fmtLocal = static function (?string $value, string $format = 'd.m.Y H:i') use ($displayTimezone): string {
    if (!$value) return '–';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone($displayTimezone)
            ->format($format);
    } catch (Throwable) {
        return (string)$value;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create_journey') {
                $title = trim((string)($_POST['title'] ?? ''));
                $vehicleId = max(0, (int)($_POST['vehicle_id'] ?? 0));
                $type = (string)($_POST['type'] ?? 'travel');
                $destination = trim((string)($_POST['destination_label'] ?? ''));
                $plannedStart = $toUtc((string)($_POST['planned_start_at'] ?? ''));
                $plannedEnd = $toUtc((string)($_POST['planned_end_at'] ?? ''));
                $budgetRaw = str_replace(',', '.', trim((string)($_POST['budget_amount'] ?? '')));
                $budget = $budgetRaw !== '' && is_numeric($budgetRaw) ? max(0.0, (float)$budgetRaw) : null;
                $notes = trim((string)($_POST['notes'] ?? ''));
                $autoAssign = isset($_POST['auto_assign']) ? 1 : 0;
                $startNow = isset($_POST['start_now']);

                if ($title === '') {
                    throw new RuntimeException('Bitte einen Namen für die Reise angeben.');
                }
                if ($vehicleId <= 0) {
                    throw new RuntimeException('Bitte ein Fahrzeug auswählen.');
                }
                if (!array_key_exists($type, $typeLabels)) {
                    $type = 'travel';
                }
                if ($plannedStart && $plannedEnd && strtotime($plannedEnd . ' UTC') < strtotime($plannedStart . ' UTC')) {
                    throw new RuntimeException('Das geplante Ende liegt vor dem Start.');
                }

                $vehicleStmt = $pdo->prepare('SELECT id FROM vehicles WHERE id=? LIMIT 1');
                $vehicleStmt->execute([$vehicleId]);
                if (!$vehicleStmt->fetchColumn()) {
                    throw new RuntimeException('Das ausgewählte Fahrzeug wurde nicht gefunden.');
                }

                if ($startNow) {
                    $activeStmt = $pdo->prepare(
                        "SELECT title FROM journeys WHERE vehicle_id=? AND status='active' LIMIT 1"
                    );
                    $activeStmt->execute([$vehicleId]);
                    $activeTitle = $activeStmt->fetchColumn();
                    if ($activeTitle !== false) {
                        throw new RuntimeException('Für dieses Fahrzeug läuft bereits die Reise „' . (string)$activeTitle . '“.');
                    }
                }

                $startedAt = null;
                if ($startNow) {
                    $startedAt = $plannedStart && strtotime($plannedStart . ' UTC') <= time()
                        ? $plannedStart
                        : gmdate('Y-m-d H:i:s');
                }
                $status = $startNow ? 'active' : 'planned';

                $stmt = $pdo->prepare(
                    "INSERT INTO journeys(
                        vehicle_id,title,type,status,destination_label,
                        planned_start_at,planned_end_at,started_at,ended_at,
                        auto_assign,budget_amount,currency,notes,created_by
                     )
                     VALUES(?,?,?,?,?,?,?,?,NULL,?,?, 'EUR',?,?)"
                );
                $stmt->execute([
                    $vehicleId,$title,$type,$status,
                    $destination !== '' ? $destination : null,
                    $plannedStart,$plannedEnd,$startedAt,
                    $autoAssign,$budget,
                    $notes !== '' ? $notes : null,
                    (int)$user['id'],
                ]);

                $journeyId = (int)$pdo->lastInsertId();
                if ($startNow && $autoAssign) {
                    Journey::syncAutoAssignments($pdo, $journeyId);
                }

                header('Location: journey.php?id=' . $journeyId);
                exit;
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : report_exception($e, 'Reise konnte nicht angelegt werden.');
        }
    }
}

$vehicles = $pdo->query(
    "SELECT id,display_name,vin
     FROM vehicles
     ORDER BY display_name,id"
)->fetchAll();

$journeys = $pdo->query(
    "SELECT j.*,v.display_name,v.vin
     FROM journeys j
     JOIN vehicles v ON v.id=j.vehicle_id
     ORDER BY
       CASE j.status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END,
       COALESCE(j.started_at,j.planned_start_at,j.created_at) DESC,
       j.id DESC"
)->fetchAll();

$activeCount = 0;
$plannedCount = 0;
$completedCount = 0;
$totalDistance = 0.0;
$totalCost = 0.0;
$journeyCards = [];

foreach ($journeys as $journey) {
    if ((string)$journey['status'] === 'active' && (int)$journey['auto_assign'] === 1) {
        Journey::syncAutoAssignments($pdo, (int)$journey['id']);
    }
    $metrics = Journey::metrics($pdo, (int)$journey['id']);
    $journeyCards[] = ['journey' => $journey, 'metrics' => $metrics];
    $totalDistance += (float)$metrics['distance_km'];
    $totalCost += (float)$metrics['charging_cost'];

    match ((string)$journey['status']) {
        'active' => $activeCount++,
        'completed' => $completedCount++,
        default => $plannedCount++,
    };
}

render_header('Reisen','journeys');
?>
<div class="page wrap journey-page">
  <div class="page-head">
    <div>
      <span class="kicker">Historie</span>
      <h1>Reisen & Touren.</h1>
      <p>Fasse komplette Reisen zusammen: Fahrten, Ladestopps, Strecke, Energie, Kosten und Route in einer einzigen Historie.</p>
    </div>
    <div class="split-actions">
      <?php if ($activeCount > 0): ?><span class="pill ok">● <?= $activeCount ?> aktiv</span><?php endif; ?>
      <span class="pill"><?= count($journeys) ?> Reise<?= count($journeys) === 1 ? '' : 'n' ?></span>
      <?php if ($canEdit): ?><button class="btn btn-primary" type="button" id="openJourneyModal">＋ Reise anlegen</button><?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <div class="stat-grid">
    <a class="stat tf-stat-link" href="journeys.php#journey-list" title="Details zu aktive Reisen" aria-label="Details zu aktive Reisen öffnen"><strong><?= $activeCount ?></strong><span>aktive Reisen</span></a>
    <a class="stat tf-stat-link" href="journeys.php#journey-list" title="Details zu geplant" aria-label="Details zu geplant öffnen"><strong><?= $plannedCount ?></strong><span>geplant</span></a>
    <a class="stat tf-stat-link" href="journeys.php#journey-list" title="Details zu Reisestrecke gesamt" aria-label="Details zu Reisestrecke gesamt öffnen"><strong><?= number_format($totalDistance,1,',','.') ?> km</strong><span>Reisestrecke gesamt</span></a>
    <a class="stat tf-stat-link" href="journeys.php#journey-list" title="Details zu bestätigte Ladekosten" aria-label="Details zu bestätigte Ladekosten öffnen"><strong><?= number_format($totalCost,2,',','.') ?> €</strong><span>bestätigte Ladekosten</span></a>
  </div>

  <?php if (!$journeyCards): ?>
    <section class="panel journey-empty-panel">
      <div class="panel-body">
        <div class="journey-empty-icon">🧳</div>
        <h2>Noch keine Reise angelegt.</h2>
        <p>Beispiel: <b>🇮🇹 Reise Italien 2027</b>. Während sie läuft, kann TrakFog neue Fahrten und Ladevorgänge automatisch zuordnen.</p>
        <?php if ($canEdit): ?><button class="btn btn-primary" type="button" data-open-journey>Erste Reise anlegen →</button><?php endif; ?>
      </div>
    </section>
  <?php else: ?>
    <div class="journey-list" id="journey-list">
      <?php foreach ($journeyCards as $entry): ?>
        <?php
          $journey = $entry['journey'];
          $metrics = $entry['metrics'];
          $status = (string)$journey['status'];
          $type = (string)$journey['type'];
          $isActive = $status === 'active';
          $rangeStart = $journey['started_at'] ?: $journey['planned_start_at'];
          $rangeEnd = $journey['ended_at'] ?: $journey['planned_end_at'];
        ?>
        <article class="panel journey-card <?= $isActive ? 'active' : '' ?>">
          <div class="journey-card-main">
            <div class="journey-card-icon"><?= $type === 'vacation' ? '🏖️' : ($type === 'business' ? '💼' : ($type === 'roadtrip' ? '🛣️' : '🧳')) ?></div>
            <div class="journey-card-copy">
              <div class="journey-card-titleline">
                <div>
                  <span class="kicker"><?= e($typeLabels[$type] ?? 'Reise') ?></span>
                  <h2><?= e((string)$journey['title']) ?></h2>
                </div>
                <span class="pill <?= $isActive ? 'ok' : ($status === 'planned' ? 'warn' : '') ?>">
                  <?= $isActive ? '● ' : '' ?><?= e($statusLabels[$status] ?? $status) ?>
                </span>
              </div>

              <div class="journey-route-line">
                <span><?= $rangeStart ? e($fmtLocal((string)$rangeStart,'d.m.Y')) : 'Start offen' ?></span>
                <i>→</i>
                <strong><?= e((string)($journey['destination_label'] ?: 'Ziel offen')) ?></strong>
                <i>→</i>
                <span><?= $isActive ? 'läuft' : ($rangeEnd ? e($fmtLocal((string)$rangeEnd,'d.m.Y')) : 'Ende offen') ?></span>
              </div>

              <div class="journey-card-metrics">
                <div><span>Strecke</span><b><?= number_format((float)$metrics['distance_km'],1,',','.') ?> km</b></div>
                <div><span>Fahrten</span><b><?= (int)$metrics['trip_count'] ?></b></div>
                <div><span>Ladungen</span><b><?= (int)$metrics['charge_count'] ?></b></div>
                <div><span>geladen</span><b><?= number_format((float)$metrics['charged_kwh'],1,',','.') ?> kWh</b></div>
                <div><span>Ladekosten</span><b><?= (int)$metrics['confirmed_cost_count'] > 0 ? number_format((float)$metrics['charging_cost'],2,',','.').' €' : '–' ?></b></div>
                <div><span>€/100 km</span><b><?= $metrics['cost_per_100km'] !== null ? number_format((float)$metrics['cost_per_100km'],2,',','.').' €' : '–' ?></b></div>
              </div>
            </div>
          </div>
          <div class="journey-card-foot">
            <span><?= e((string)($journey['display_name'] ?: 'Tesla')) ?><?= (int)$journey['auto_assign'] === 1 ? ' · Auto-Zuordnung aktiv' : ' · manuelle Zuordnung' ?></span>
            <a class="btn <?= $isActive ? 'btn-primary' : 'btn-ghost' ?>" href="journey.php?id=<?= (int)$journey['id'] ?>">Reise öffnen →</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div class="tf-modal" id="journeyModal" hidden>
  <div class="tf-modal-backdrop" data-journey-modal-close></div>
  <section class="tf-modal-card journey-modal-card" role="dialog" aria-modal="true" aria-labelledby="journeyModalTitle">
    <div class="tf-modal-head">
      <div><span class="kicker">Neue Historie</span><h2 id="journeyModalTitle">Reise anlegen</h2></div>
      <button class="tf-modal-close" type="button" data-journey-modal-close aria-label="Schließen">×</button>
    </div>
    <div class="tf-modal-body">
      <form method="post" class="journey-create-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create_journey">

        <div class="journey-form-grid">
          <label class="journey-form-wide"><span>Name</span><input name="title" placeholder="z. B. 🇮🇹 Reise Italien 2027" required></label>
          <label>
            <span>Fahrzeug</span>
            <select name="vehicle_id" required>
              <option value="">Fahrzeug wählen</option>
              <?php foreach ($vehicles as $vehicle): ?>
                <option value="<?= (int)$vehicle['id'] ?>"><?= e((string)($vehicle['display_name'] ?: $vehicle['vin'] ?: 'Tesla')) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Art</span>
            <select name="type">
              <?php foreach ($typeLabels as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="journey-form-wide"><span>Ziel / Bezeichnung</span><input name="destination_label" placeholder="z. B. Gardasee, Italien"></label>
          <label><span>Geplanter Start</span><input name="planned_start_at" type="datetime-local"></label>
          <label><span>Geplantes Ende</span><input name="planned_end_at" type="datetime-local"></label>
          <label><span>Budget €</span><input name="budget_amount" inputmode="decimal" placeholder="optional"></label>
          <label class="check-chip journey-check"><input type="checkbox" name="auto_assign" value="1" checked><span>Fahrten & Ladungen automatisch zuordnen</span></label>
          <label class="journey-form-wide"><span>Notiz</span><textarea name="notes" rows="3" placeholder="optional"></textarea></label>
        </div>

        <div class="journey-create-options">
          <label class="check-chip"><input type="checkbox" name="start_now" value="1"><span>Reise sofort starten</span></label>
          <span>Ohne Haken bleibt sie zunächst als „Geplant“ gespeichert.</span>
        </div>

        <div class="split-actions">
          <button class="btn btn-primary" type="submit">Reise anlegen</button>
          <button class="btn btn-ghost" type="button" data-journey-modal-close>Abbrechen</button>
        </div>
      </form>
    </div>
  </section>
</div>

<script>
(() => {
  const modal=document.getElementById('journeyModal');
  const open=() => {
    if (!modal) return;
    modal.hidden=false;
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => modal.querySelector('input[name="title"]')?.focus());
  };
  const close=() => {
    if (!modal) return;
    modal.hidden=true;
    document.body.classList.remove('modal-open');
  };

  document.getElementById('openJourneyModal')?.addEventListener('click',open);
  document.querySelectorAll('[data-open-journey]').forEach(el => el.addEventListener('click',open));
  document.querySelectorAll('[data-journey-modal-close]').forEach(el => el.addEventListener('click',close));
  window.addEventListener('keydown',event => {
    if (event.key==='Escape' && modal && !modal.hidden) close();
  });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
