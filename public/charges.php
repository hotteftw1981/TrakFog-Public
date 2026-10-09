<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();
require_once dirname(__DIR__) . '/src/ChargeEnergyComparison.php';

$rows = $pdo->query(
    "SELECT c.*, v.display_name,
            (
              SELECT j.id
              FROM journey_charges jc
              JOIN journeys j ON j.id=jc.journey_id
              WHERE jc.charge_id=c.id AND jc.included=1
              ORDER BY j.id DESC
              LIMIT 1
            ) AS journey_id,
            (
              SELECT j.title
              FROM journey_charges jc
              JOIN journeys j ON j.id=jc.journey_id
              WHERE jc.charge_id=c.id AND jc.included=1
              ORDER BY j.id DESC
              LIMIT 1
            ) AS journey_title
     FROM charges c
     JOIN vehicles v ON v.id=c.vehicle_id
     ORDER BY c.started_at DESC
     LIMIT 100"
)->fetchAll();

$totalCharges = (int)$pdo->query('SELECT COUNT(*) FROM charges')->fetchColumn();
$activeCharges = (int)$pdo->query('SELECT COUNT(*) FROM charges WHERE ended_at IS NULL')->fetchColumn();
$totalEnergy = (float)$pdo->query('SELECT COALESCE(SUM(energy_added_kwh),0) FROM charges')->fetchColumn();
$totalCost = (float)$pdo->query('SELECT COALESCE(SUM(cost_amount),0) FROM charges')->fetchColumn();
$confirmedCostCharges = (int)$pdo->query('SELECT COUNT(*) FROM charges WHERE cost_amount IS NOT NULL')->fetchColumn();
$costedEnergy = (float)$pdo->query('SELECT COALESCE(SUM(energy_added_kwh),0) FROM charges WHERE cost_amount IS NOT NULL')->fetchColumn();
$avgCostPerKwh = $costedEnergy > 0.001 ? $totalCost / $costedEnergy : 0.0;
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

$duration = static function (?string $start, ?string $end): string {
    if (!$start) return '–';
    try {
        $startAt = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $endAt = $end
            ? new DateTimeImmutable($end, new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = max(0, $endAt->getTimestamp() - $startAt->getTimestamp());
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) return $hours . ' h ' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' min';
        return max(1, $minutes) . ' min';
    } catch (Throwable) {
        return '–';
    }
};

render_header('Laden','charges');
?>
<div class="page wrap" data-live-region="page-charges" data-live-poll="5000">
  <div class="page-head">
    <div>
      <span class="kicker">Historie</span>
      <h1>Ladevorgänge.</h1>
      <p>Die TrakFog Engine erkennt aktive Ladevorgänge automatisch über Teslas Charge State und führt Energie, SoC, Leistung, Dauer und Standort fort.</p>
    </div>
    <div class="split-actions">
      <?php if ($activeCharges > 0): ?><span class="pill ok">● <?= $activeCharges ?> lädt</span><?php endif; ?>
      <span class="pill"><?= $totalCharges ?> gespeichert</span>
    </div>
  </div>

  <div class="stat-grid">
    <a class="stat tf-stat-link" href="charges.php#charge-list" title="Details zu Ladevorgänge gesamt" aria-label="Details zu Ladevorgänge gesamt öffnen"><strong><?= $totalCharges ?></strong><span>Ladevorgänge gesamt</span></a>
    <a class="stat tf-stat-link" href="charges.php#charge-list" title="Details zu geladen gesamt" aria-label="Details zu geladen gesamt öffnen"><strong><?= number_format($totalEnergy,2,',','.') ?> kWh</strong><span>geladen gesamt</span></a>
    <a class="stat tf-stat-link" href="charges.php#charge-list" title="Details zu Kosten gesamt · bestätigt" aria-label="Details zu Kosten gesamt · bestätigt öffnen"><strong><?= $confirmedCostCharges > 0 ? number_format($totalCost,2,',','.').' €' : '–' ?></strong><span>Kosten gesamt · bestätigt</span></a>
    <a class="stat tf-stat-link" href="statistics.php#lifetime" title="Details zu Ø effektiver Preis" aria-label="Details zu Ø effektiver Preis öffnen"><strong><?= $confirmedCostCharges > 0 && $costedEnergy > 0.001 ? number_format($avgCostPerKwh,3,',','.').' €/kWh' : '–' ?></strong><span>Ø effektiver Preis</span></a>
  </div>

  <section class="panel">
    <div class="panel-head">
      <h3>⚡ Ladehistorie</h3>
      <span class="pill ok">automatische Erkennung aktiv</span>
    </div>
    <div class="panel-body">
      <?php if (!$rows): ?>
        <div class="empty">
          Noch kein Ladevorgang erkannt.<br>
          <span class="small">Sobald Tesla einen aktiven Charge State oder Ladeleistung meldet, eröffnet die TrakFog Engine automatisch eine Session.</span>
        </div>
      <?php else: ?>
        <div class="charge-list" id="charge-list">
          <?php foreach ($rows as $row): ?>
            <?php
              $active = empty($row['ended_at']);
              $startSoc = $row['start_battery_percent'] !== null ? (float)$row['start_battery_percent'] : null;
              $endSoc = $row['end_battery_percent'] !== null ? (float)$row['end_battery_percent'] : null;
              $socDelta = ($startSoc !== null && $endSoc !== null) ? ($endSoc - $startSoc) : null;
              $energyComparison = ChargeEnergyComparison::calculate($row['energy_added_kwh'] ?? null, $row['voltcore_energy_kwh'] ?? null);
              $chargePlace = Geo::label(
                  $row['latitude'] ?? null,
                  $row['longitude'] ?? null,
                  $geoLocations,
                  $geoFences,
                  (string)($row['location_name'] ?? '')
              );
            ?>
            <article class="charge-card <?= $active ? 'active' : '' ?>">
              <div class="charge-card-head">
                <div>
                  <span class="charge-date"><?= e($fmtLocal((string)$row['started_at'])) ?></span>
                  <strong><?= e($row['display_name'] ?: 'Tesla') ?></strong>
                </div>
                <div class="split-actions"><span class="pill <?= $active ? 'ok' : '' ?>"><?= $active ? '● Lädt' : 'abgeschlossen' ?></span><a class="btn btn-ghost charge-curve-link" href="charge.php?id=<?= (int)$row['id'] ?>">Kurve & Details →</a></div>
              </div>

              <div class="charge-metrics">
                <div><span>Energie</span><b><?= $row['energy_added_kwh'] !== null ? number_format((float)$row['energy_added_kwh'],2,',','.').' kWh' : '–' ?></b></div>
                <div><span>Dauer</span><b><?= e($duration((string)$row['started_at'], $row['ended_at'] ? (string)$row['ended_at'] : null)) ?></b></div>
                <div><span>Max. Leistung</span><b><?= $row['max_power_kw'] !== null ? number_format((float)$row['max_power_kw'],1,',','.').' kW' : '–' ?></b></div>
                <div><span>SoC</span><b><?= $startSoc !== null ? number_format($startSoc,0,',','.') . '%' : '–' ?> → <?= $endSoc !== null ? number_format($endSoc,0,',','.') . '%' : '–' ?></b></div>
              </div>

              <?php if ($energyComparison): ?>
                <div class="charge-compare-inline">
                  <span>⚡ Energievergleich</span><strong><?= number_format($energyComparison['difference_kwh'],2,',','.') ?> kWh Differenz</strong><span><?= $energyComparison['difference_pct_meter'] !== null ? number_format($energyComparison['difference_pct_meter'],1,',','.') . ' % der VoltCore-Energie' : 'VoltCore: 0 kWh' ?></span>
                  <a href="charge.php?id=<?= (int)$row['id'] ?>#charge-energy-compare">Vergleich ansehen &rarr;</a>
                </div>
              <?php endif; ?>

              <?php if (!empty($row['journey_id'])): ?>
                <div class="trip-journey-link"><span>🧳 Reise</span><a href="journey.php?id=<?= (int)$row['journey_id'] ?>"><?= e((string)$row['journey_title']) ?> →</a></div>
              <?php endif; ?>

              <div class="charge-foot">
                <span><?= e($fmtLocal((string)$row['started_at'], true)) ?> → <?= $active ? 'läuft' : e($fmtLocal((string)$row['ended_at'], true)) ?></span>
                <span><?= e((string)($row['last_state'] ?: ($row['end_reason'] ?: '–'))) ?></span>
                <span><?= number_format((int)($row['sample_count'] ?? 0),0,',','.') ?> Polls</span>
                <?php if ($socDelta !== null): ?><span><?= $socDelta > 0 ? '+' : '' ?><?= number_format($socDelta,0,',','.') ?> SoC-Pkt.</span><?php endif; ?>
                <?php if ($row['cost_amount'] !== null): ?>
                  <span class="charge-cost-inline">💶 <?= number_format((float)$row['cost_amount'],2,',','.') ?> <?= e((string)($row['cost_currency'] ?: 'EUR')) ?><?= $row['price_per_kwh'] !== null ? ' · '.number_format((float)$row['price_per_kwh'],3,',','.').'/kWh effektiv' : '' ?><?= ($row['cost_source'] ?? '') === 'tesla_invoice' ? ' · ⚡ Tesla' : '' ?></span>
                <?php else: ?>
                  <span class="charge-cost-open">💶 Kosten noch offen</span>
                <?php endif; ?>
              </div>

              <?php if ($row['latitude'] !== null && $row['longitude'] !== null): ?>
                <div class="charge-location">
                  <span><?= e($chargePlace['label']) ?></span>
                  <a href="map.php?charge_id=<?= (int)$row['id'] ?>">auf Karte →</a>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <p class="trip-note">Unbestätigte Ladekosten bleiben offen und fließen nicht in Durchschnittswerte ein. Erst ausdrücklich bestätigte 0,00 € gelten als kostenlose Ladung.</p>
</div>
<?php render_footer(); ?>
