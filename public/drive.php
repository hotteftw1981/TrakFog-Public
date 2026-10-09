<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$message = $error = $warning = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            $syncResult = TeslaService::syncVehicleData($pdo, $config, (int)($_POST['vehicle_id'] ?? 0));
            $fetchMode = (string)($syncResult['_fetch_mode'] ?? 'full');
            if ($fetchMode === 'full') {
                $message = 'Fahrzeugdaten aktualisiert.';
            } elseif ($fetchMode === 'without_location') {
                $warning = 'Fahrzeugdaten aktualisiert. Tesla hat den geschützten Standortteil dieses Abrufs blockiert; der zuletzt gespeicherte Standort bleibt erhalten.';
            } else {
                $warning = 'Fahrzeugdaten aktualisiert. Tesla hat nur einen reduzierten Detailabruf erlaubt; vorhandene Werte bleiben erhalten, wenn einzelne Bereiche fehlen.';
            }
        } catch (TeslaApiException $e) {
            if (in_array($e->statusCode, [408, 429], true)) {
                $warning = $e->getMessage() . ' Die zuletzt gespeicherten Daten bleiben sichtbar.';
            } else {
                $error = $e->getMessage();
            }
        } catch (Throwable $e) {
            $error = report_exception($e, 'Fahrzeugdaten konnten nicht aktualisiert werden.');
        }
    }
}

$vehicles = $pdo->query(
    "SELECT v.*,
            (SELECT MAX(s.recorded_at) FROM vehicle_snapshots s WHERE s.vehicle_id=v.id) AS detail_data_at,
            (SELECT s.charging_state FROM vehicle_snapshots s WHERE s.vehicle_id=v.id ORDER BY s.recorded_at DESC LIMIT 1) AS charging_state
     FROM vehicles v
     ORDER BY v.display_name,v.id"
)->fetchAll();

$tripCount = (int)$pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();
$chargeCount = (int)$pdo->query('SELECT COUNT(*) FROM charges')->fetchColumn();
$odoSum = array_sum(array_map(static fn(array $v): float => (float)($v['odometer_km'] ?? 0), $vehicles));

$fmtLocal = static function (?string $value): string {
    if (!$value) return '–';
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

render_header('Fahrzeug', 'drive');
?>
<div class="page wrap" data-live-region="page-drive" data-live-poll="5000">
  <div class="page-head">
    <div>
      <span class="kicker">Fahrzeug</span>
      <h1>Dein Tesla.</h1>
      <p>Letzte bekannte Fahrzeugdaten ohne Dauerpolling. Frische Detaildaten holst du bewusst manuell — damit ein schlafender Tesla schlafen darf. 😴</p>
    </div>
    <a class="btn btn-ghost" href="system.php#tesla">🔌 Connect</a>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($warning): ?><div class="alert alert-warn">😴 <?= e($warning) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <div class="stat-grid">
    <a class="stat tf-stat-link" href="drive.php#vehicle-list" title="Details zu Fahrzeuge" aria-label="Details zu Fahrzeuge öffnen"><strong><?= count($vehicles) ?></strong><span>Fahrzeuge</span></a>
    <a class="stat tf-stat-link" href="trips.php#trip-list" title="Details zu Fahrten" aria-label="Details zu Fahrten öffnen"><strong><?= $tripCount ?></strong><span>Fahrten</span></a>
    <a class="stat tf-stat-link" href="charges.php#charge-list" title="Details zu Ladevorgänge" aria-label="Details zu Ladevorgänge öffnen"><strong><?= $chargeCount ?></strong><span>Ladevorgänge</span></a>
    <a class="stat tf-stat-link" href="drive.php#vehicle-list" title="Details zu Kilometerstände gesamt" aria-label="Details zu Kilometerstände gesamt öffnen"><strong><?= number_format($odoSum, 0, ',', '.') ?> km</strong><span>Kilometerstände gesamt</span></a>
  </div>

  <?php if (!$vehicles): ?>
    <section class="panel">
      <div class="empty">Noch kein Fahrzeug synchronisiert.<br><br><a class="btn btn-primary" href="system.php#tesla">Tesla verbinden →</a></div>
    </section>
  <?php else: ?>
    <div class="drive-list" id="vehicle-list">
      <?php foreach ($vehicles as $v): ?>
        <?php
          $state = strtolower((string)($v['state'] ?? 'unknown'));
          $stateClass = $state === 'online' ? 'ok' : ($state === 'offline' || $state === 'asleep' ? 'sleep' : '');
          $hasDetail = !empty($v['detail_data_at']);
          $charging = strtolower((string)($v['charging_state'] ?? ''));
        ?>
        <section class="panel drive-vehicle-card">
          <div class="drive-vehicle-head">
            <div class="drive-car-art" aria-hidden="true">
              <span class="drive-car-glow"></span>
              <span class="drive-car-icon">🚗</span>
            </div>
            <div class="drive-car-title">
              <div class="split-actions">
                <h2><?= e($v['display_name'] ?: 'Tesla') ?></h2>
                <span class="pill <?= e($stateClass) ?>"><?= $state === 'offline' || $state === 'asleep' ? '☾ ' : '● ' ?><?= e($state ?: 'unknown') ?></span>
                <?php if ($charging && $charging !== 'disconnected'): ?><span class="pill ok">⚡ <?= e($charging) ?></span><?php endif; ?>
              </div>
              <div class="small">
                <?= e($v['vin'] ? 'VIN ••••••' . substr((string)$v['vin'], -6) : 'VIN noch nicht geladen') ?>
                · Detaildaten <?= e($fmtLocal($v['detail_data_at'] ?? null)) ?>
              </div>
            </div>
            <div class="drive-car-actions">
              <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                <button class="btn btn-ghost" type="submit">↻ Detaildaten abrufen</button>
              </form>
              <a class="btn btn-primary" href="vehicle.php?id=<?= (int)$v['id'] ?>">Auf einen Blick →</a>
            </div>
          </div>

          <div class="drive-metrics">
            <div class="drive-metric">
              <span>🔋 Akku</span>
              <strong><?= $v['battery_level'] !== null ? number_format((float)$v['battery_level'], 0) . '%' : '–' ?></strong>
              <div class="meter"><i style="width:<?= max(0, min(100, (float)($v['battery_level'] ?? 0))) ?>%"></i></div>
            </div>
            <div class="drive-metric"><span>🛣️ Rated Range</span><strong><?= $v['rated_range_km'] !== null ? number_format((float)$v['rated_range_km'], 0, ',', '.') . ' km' : '–' ?></strong><small>letzter Detailstand</small></div>
            <div class="drive-metric"><span>🔢 Kilometerstand</span><strong><?= $v['odometer_km'] !== null ? number_format((float)$v['odometer_km'], 0, ',', '.') . ' km' : '–' ?></strong><small>Lifetime</small></div>
            <div class="drive-metric"><span>💨 Geschwindigkeit</span><strong><?= $v['speed_kmh'] !== null ? number_format((float)$v['speed_kmh'], 0) . ' km/h' : '–' ?></strong><small><?= $state === 'online' ? 'letzter Wert' : 'Fahrzeug schläft' ?></small></div>
          </div>

          <?php if (!$hasDetail): ?>
            <div class="drive-first-data">
              <strong>👋 Fahrzeug erkannt — Detaildaten fehlen noch.</strong>
              <span>Wenn Hotte Y das nächste Mal wach ist, einmal „Detaildaten abrufen“ drücken. Danach kann Drive Akku, Reichweite, Klima, Türen und weitere Werte anzeigen.</span>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
