<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();
require_once dirname(__DIR__) . '/src/ChargeEnergyComparison.php';

$user = Auth::user($pdo);
$isAdmin = in_array($user['role'], ['owner','admin'], true);
$id = (int)($_GET['id'] ?? 0);
$message = null;
$error = null;

$loadCharge = static function (PDO $pdo, int $id): array|false {
    $stmt = $pdo->prepare(
        "SELECT c.*, v.display_name, v.vin
         FROM charges c
         JOIN vehicles v ON v.id=c.vehicle_id
         WHERE c.id=?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
};

$charge = $loadCharge($pdo, $id);
if (!$charge) {
    ErrorPage::render(404, AppLogger::requestId(), 'Ladevorgang nicht gefunden.');
}


$parseMoney = static function (mixed $value): ?float {
    $text = trim(str_replace(',', '.', (string)$value));
    if ($text === '') return null;
    return is_numeric($text) && (float)$text >= 0 ? (float)$text : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save_cost') {
                $locationName = trim((string)($_POST['location_name'] ?? ''));
                $costRaw = trim((string)($_POST['cost_amount'] ?? ''));
                $costAmount = $parseMoney($costRaw);
                $currency = strtoupper(trim((string)($_POST['currency'] ?? 'EUR')));

                if ($costAmount === null) {
                    throw new RuntimeException('Bitte gültige Gesamtkosten angeben.');
                }
                if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                    throw new RuntimeException('Die Währung muss aus drei Buchstaben bestehen, z. B. EUR.');
                }

                $energy = is_numeric($charge['energy_added_kwh'] ?? null)
                    ? max(0.0, (float)$charge['energy_added_kwh'])
                    : 0.0;
                $effectivePrice = $energy > 0.001
                    ? round($costAmount / $energy, 4)
                    : null;

                $stmt = $pdo->prepare(
                    'UPDATE charges
                     SET location_name=?,
                         tariff_id=NULL,
                         price_per_kwh=?,
                         cost_amount=?,
                         cost_currency=?,
                         cost_source=?,
                         cost_locked=1
                     WHERE id=?'
                );
                $stmt->execute([
                    $locationName !== '' ? $locationName : null,
                    $effectivePrice,
                    round($costAmount, 2),
                    $currency,
                    'manual_total',
                    $id,
                ]);

                $charge = $loadCharge($pdo, $id);
                $message = $costAmount == 0.0
                    ? '0,00 € bestätigt – dieser Ladevorgang gilt als kostenlos.'
                    : 'Tatsächlich bezahlter Gesamtpreis gespeichert.';
            } elseif ($action === 'save_voltcore_energy') {
                $meterRaw = trim(str_replace(',', '.', (string)($_POST['voltcore_energy_kwh'] ?? '')));
                $meterKwh = null;
                if ($meterRaw !== '') {
                    if (!is_numeric($meterRaw) || !is_finite((float)$meterRaw) || (float)$meterRaw < 0 || (float)$meterRaw > 1000) {
                        throw new RuntimeException('Bitte einen gültigen VoltCore-Zählerwert von 0 bis 1000 kWh eingeben.');
                    }
                    $meterKwh = round((float)$meterRaw, 3);
                }
                $sessionRef = trim((string)($_POST['voltcore_session_ref'] ?? ''));
                if ($sessionRef !== '' && (!ctype_digit($sessionRef) || strlen($sessionRef) > 40)) {
                    throw new RuntimeException('Die VoltCore-Session-ID darf nur Ziffern enthalten.');
                }
                if ($meterKwh === null && $sessionRef !== '') {
                    throw new RuntimeException('Bitte zuerst einen VoltCore-Zählerwert eingeben.');
                }
                $stmt = $pdo->prepare('UPDATE charges SET voltcore_energy_kwh=?, voltcore_session_ref=? WHERE id=?');
                $stmt->execute([$meterKwh, $meterKwh !== null && $sessionRef !== '' ? $sessionRef : null, $id]);
                $charge = $loadCharge($pdo, $id);
                $message = $meterKwh === null ? 'VoltCore-Zuordnung entfernt.' : 'VoltCore-Messwert für diese Session gespeichert.';
            } elseif ($action === 'reset_cost') {
                $stmt = $pdo->prepare(
                    'UPDATE charges
                     SET cost_amount=NULL,
                         cost_currency=NULL,
                         price_per_kwh=NULL,
                         cost_source=NULL,
                         cost_locked=0,
                         tesla_charge_session_id=NULL,
                         tesla_site_name=NULL,
                         tesla_cost_synced_at=NULL,
                         tesla_history_json=NULL
                     WHERE id=?'
                );
                $stmt->execute([$id]);
                $charge = $loadCharge($pdo, $id);
                $message = 'Kosten wieder als offen markiert.';
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : report_exception($e, 'Kosten konnten nicht gespeichert werden.');
        }
    }
}

$geoLocations = Geo::loadLocations($pdo);
$geoFences = Geo::loadGeofences($pdo, true);
$chargePlace = Geo::label(
    $charge['latitude'] ?? null,
    $charge['longitude'] ?? null,
    $geoLocations,
    $geoFences,
    (string)($charge['location_name'] ?? '')
);

$active = empty($charge['ended_at']);
$startAt = (string)$charge['started_at'];
$endAt = $charge['ended_at'] ? (string)$charge['ended_at'] : gmdate('Y-m-d H:i:s');

try {
    $startMoment = new DateTimeImmutable($startAt, new DateTimeZone('UTC'));
    $endMoment = new DateTimeImmutable($endAt, new DateTimeZone('UTC'));
    $durationSeconds = max(0, $endMoment->getTimestamp() - $startMoment->getTimestamp());
} catch (Throwable) {
    $durationSeconds = 0;
}

$bucketSeconds = $durationSeconds <= 21600 ? 30 : ($durationSeconds <= 86400 ? 60 : 300);
$bucketLabel = $bucketSeconds === 30 ? '30-Sekunden-Mittel' : ($bucketSeconds === 60 ? 'Minutenmittel' : '5-Minuten-Mittel');

$curveStmt = $pdo->prepare(
    "SELECT
        FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / ?) * ?) AS bucket_at,
        AVG(
            CAST(
                NULLIF(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.charge_state.charger_power')), 'null'),
                    ''
                ) AS DECIMAL(10,2)
            )
        ) AS charger_power_kw,
        AVG(battery_level) AS battery_level,
        MAX(
            CAST(
                NULLIF(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.charge_state.charge_energy_added')), 'null'),
                    ''
                ) AS DECIMAL(12,3)
            )
        ) AS energy_added_kwh,
        AVG(
            CAST(
                NULLIF(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.charge_state.charger_voltage')), 'null'),
                    ''
                ) AS DECIMAL(10,2)
            )
        ) AS charger_voltage,
        AVG(
            CAST(
                NULLIF(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.charge_state.charger_actual_current')), 'null'),
                    ''
                ) AS DECIMAL(10,2)
            )
        ) AS charger_current
     FROM vehicle_snapshots
     WHERE vehicle_id=?
       AND recorded_at>=?
       AND recorded_at<=?
     GROUP BY bucket_at
     ORDER BY bucket_at"
);
$curveStmt->execute([$bucketSeconds, $bucketSeconds, (int)$charge['vehicle_id'], $startAt, $endAt]);
$curveRows = $curveStmt->fetchAll();

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

$duration = static function (int $seconds): string {
    if ($seconds <= 0) return '–';
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) return $hours . ' h ' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' min';
    return max(1, $minutes) . ' min';
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

$powerPoints = $toPoints($curveRows, 'charger_power_kw');
$socPoints = $toPoints($curveRows, 'battery_level');
$energyPoints = $toPoints($curveRows, 'energy_added_kwh');

$powerChart = [
    'unit' => 'kW',
    'decimals' => 1,
    'zeroFloor' => true,
    'series' => [
        ['label' => 'Ladeleistung', 'key' => 'brand', 'points' => $powerPoints],
    ],
];
$socChart = [
    'unit' => '%',
    'decimals' => 0,
    'min' => 0,
    'max' => 100,
    'series' => [
        ['label' => 'SoC', 'key' => 'green', 'points' => $socPoints],
    ],
];
$energyChart = [
    'unit' => 'kWh',
    'decimals' => 2,
    'zeroFloor' => true,
    'series' => [
        ['label' => 'Geladen', 'key' => 'blue', 'points' => $energyPoints],
    ],
];

$latestCurve = $curveRows ? $curveRows[count($curveRows) - 1] : [];
$latestVoltage = is_numeric($latestCurve['charger_voltage'] ?? null) ? (float)$latestCurve['charger_voltage'] : null;
$latestCurrent = is_numeric($latestCurve['charger_current'] ?? null) ? (float)$latestCurve['charger_current'] : null;
$startSoc = $charge['start_battery_percent'] !== null ? (float)$charge['start_battery_percent'] : null;
$endSoc = $charge['end_battery_percent'] !== null ? (float)$charge['end_battery_percent'] : null;
$socDelta = $startSoc !== null && $endSoc !== null ? $endSoc - $startSoc : null;
$energyComparison = ChargeEnergyComparison::calculate($charge['energy_added_kwh'] ?? null, $charge['voltcore_energy_kwh'] ?? null);
$costAmount = is_numeric($charge['cost_amount'] ?? null) ? (float)$charge['cost_amount'] : null;
$pricePerKwh = is_numeric($charge['price_per_kwh'] ?? null) ? (float)$charge['price_per_kwh'] : null;
$currency = (string)($charge['cost_currency'] ?: 'EUR');
$costSourceLabels = [
    'manual_total' => 'bestätigter Gesamtpreis',
    'manual_price' => 'historischer Preis/kWh',
    'manual' => 'manuell / Rechnung',
    'tariff' => 'historischer Tarif',
    'tesla_invoice' => 'Tesla-Abrechnung',
];
$costSourceLabel = $costSourceLabels[(string)($charge['cost_source'] ?? '')] ?? 'nicht hinterlegt';

$journeyStmt = $pdo->prepare(
    "SELECT j.id,j.title,j.status
     FROM journey_charges jc
     JOIN journeys j ON j.id=jc.journey_id
     WHERE jc.charge_id=? AND jc.included=1
     ORDER BY j.id DESC
     LIMIT 1"
);
$journeyStmt->execute([(int)$charge['id']]);
$journeyLink = $journeyStmt->fetch() ?: null;

render_header('Ladevorgang #' . (int)$charge['id'], 'charges');
?>
<div class="page wrap charge-detail-page" data-live-region="page-charge" data-chart-page <?= $active ? 'data-live-poll="10000"' : '' ?>>
  <div class="page-head">
    <div>
      <span class="kicker">TrakFog Charging · Session #<?= (int)$charge['id'] ?></span>
      <h1><?= e((string)($charge['display_name'] ?: 'Tesla')) ?> lädt<?= $active ? ' gerade.' : ' · Verlauf.' ?></h1>
      <p><?= e($fmtLocal($startAt)) ?> → <?= $active ? 'läuft' : e($fmtLocal((string)$charge['ended_at'])) ?> · <?= e($duration($durationSeconds)) ?></p>
    </div>
    <div class="split-actions">
      <span class="pill <?= $active ? 'ok' : '' ?>"><?= $active ? '● Live-Ladung' : 'abgeschlossen' ?></span>
      <?php if ($journeyLink): ?><a class="btn btn-ghost" href="journey.php?id=<?= (int)$journeyLink['id'] ?>">🧳 <?= e((string)$journeyLink['title']) ?></a><?php endif; ?>
      <a class="btn btn-ghost" href="charges.php">← Ladehistorie</a>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <div class="stat-grid charge-detail-stats">
    <a class="stat tf-stat-link" href="#charge-session-summary" title="Details zu Energie" aria-label="Details zu Energie öffnen"><strong><?= $charge['energy_added_kwh'] !== null ? number_format((float)$charge['energy_added_kwh'],2,',','.').' kWh' : '–' ?></strong><span>Energie</span></a>
    <a class="stat tf-stat-link" href="#charge-cost-details" title="Details zu Ladekosten" aria-label="Details zu Ladekosten öffnen"><strong><?= $costAmount !== null ? number_format($costAmount,2,',','.').' '.e($currency) : '–' ?></strong><span><?= $costAmount !== null ? 'Kosten bestätigt' : 'Kosten noch offen' ?></span></a>
    <a class="stat tf-stat-link" href="#charge-cost-details" title="Details zu effektiver Preis" aria-label="Details zu effektiver Preis öffnen"><strong><?= $pricePerKwh !== null ? number_format($pricePerKwh,4,',','.').' '.e($currency).'/kWh' : '–' ?></strong><span>effektiver Preis</span></a>
    <a class="stat tf-stat-link" href="#charge-session-summary" title="Details zu SoC" aria-label="Details zu SoC öffnen"><strong><?= $startSoc !== null ? number_format($startSoc,0,',','.') . '%' : '–' ?> → <?= $endSoc !== null ? number_format($endSoc,0,',','.') . '%' : '–' ?></strong><span>SoC</span></a>
  </div>

  <section class="panel charge-session-summary" id="charge-session-summary">
    <div class="panel-head"><h3>⚡ Session</h3><span class="pill"><?= e($bucketLabel) ?></span></div>
    <div class="panel-body">
      <div class="charge-session-grid">
        <div><span>Status</span><b><?= e((string)($charge['last_state'] ?: ($charge['end_reason'] ?: '–'))) ?></b></div>
        <div><span>Dauer</span><b><?= e($duration($durationSeconds)) ?></b></div>
        <div><span>Peak</span><b><?= $charge['max_power_kw'] !== null ? number_format((float)$charge['max_power_kw'],1,',','.').' kW' : '–' ?></b></div>
        <?php if (!empty($charge['tesla_site_name'])): ?>
          <div><span>Tesla Standort</span><b><?= e((string)$charge['tesla_site_name']) ?></b></div>
        <?php endif; ?>
        <?php if (($charge['cost_source'] ?? '') === 'tesla_invoice'): ?>
          <div><span>Kostenquelle</span><b>⚡ Tesla Charging History</b></div>
        <?php endif; ?>
        <div><span>SoC-Änderung</span><b><?= $socDelta !== null ? ($socDelta > 0 ? '+' : '') . number_format($socDelta,0,',','.') . ' Pkt.' : '–' ?></b></div>
        <div><span>Spannung zuletzt</span><b><?= $latestVoltage !== null ? number_format($latestVoltage,0,',','.').' V' : '–' ?></b></div>
        <div><span>Strom zuletzt</span><b><?= $latestCurrent !== null ? number_format($latestCurrent,1,',','.').' A' : '–' ?></b></div>
        <div><span>Messpunkte</span><b><?= number_format(count($curveRows),0,',','.') ?></b></div>
        <div><span>Kostenquelle</span><b><?= e($costSourceLabel) ?></b></div>
      </div>
      <?php if ($charge['latitude'] !== null && $charge['longitude'] !== null): ?>
        <div class="charge-detail-location">
          <span>⌖ <?= e($chargePlace['label']) ?></span>
          <a href="map.php?charge_id=<?= (int)$charge['id'] ?>">Auf Karte öffnen →</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel charge-compare-panel" id="charge-energy-compare">
    <div class="panel-head">
      <div><h3>⚡ Energievergleich · Tesla & VoltCore</h3><span class="small">Fahrzeugwert und zugeordneter Zählerwert – getrennte Messquellen</span></div>
      <span class="pill <?= $energyComparison ? 'ok' : '' ?>"><?= $energyComparison ? 'Vergleich vorhanden' : 'VoltCore-Wert fehlt' ?></span>
    </div>
    <div class="panel-body">
      <?php if ($energyComparison): ?>
        <div class="charge-compare-box">
          <span class="charge-compare-overline">ENERGIEDIFFERENZ</span>
          <div class="charge-compare-value"><strong><?= number_format($energyComparison['difference_kwh'],2,',','.') ?> kWh</strong><span><?= $energyComparison['difference_pct_meter'] !== null ? number_format($energyComparison['difference_pct_meter'],1,',','.') . ' % der VoltCore-Energie' : 'Prozentwert bei 0 kWh nicht berechenbar' ?></span></div>
          <div class="charge-compare-track" role="img" aria-label="TrakFog <?= number_format($energyComparison['vehicle_kwh'],2,',','.') ?> Kilowattstunden, VoltCore <?= number_format($energyComparison['meter_kwh'],2,',','.') ?> Kilowattstunden">
            <?php if ($energyComparison['meter_exceeds_vehicle']): ?>
              <span class="charge-compare-tesla" style="width:<?= number_format($energyComparison['vehicle_share'],3,'.','') ?>%"></span><span class="charge-compare-remainder" style="width:<?= number_format(100 - $energyComparison['vehicle_share'],3,'.','') ?>%"></span>
            <?php else: ?>
              <span class="charge-compare-tesla" style="width:100%"></span>
              <span class="charge-compare-meter-mark" style="left:<?= number_format($energyComparison['meter_share'],3,'.','') ?>%" title="VoltCore-Zählerwert"></span>
            <?php endif; ?>
          </div>
          <div class="charge-compare-labels"><span><?= number_format($energyComparison['vehicle_kwh'],2,',','.') ?> kWh · TrakFog / Tesla</span><span><?= number_format($energyComparison['meter_kwh'],2,',','.') ?> kWh · VoltCore</span></div>
          <p class="charge-compare-explain">Quelle Fahrzeug: Tesla-Telemetrie · Quelle Ladesäule: VoltCore (<?= e((string)($charge['voltcore_session_ref'] ?? '') !== '' ? 'Session #' . $charge['voltcore_session_ref'] : 'manuell zugeordnet') ?>). Die Abweichung ist ein Messvergleich, kein automatisch berechneter Ladeverlust.</p>
        </div>
      <?php else: ?>
        <p class="charge-compare-empty">Noch kein VoltCore-Zählerwert zugeordnet. TrakFog erhält derzeit den SoC für VoltCore bereitgestellt, aber keinen automatischen Rückkanal für die gemessenen kWh. Sobald ein zugehöriger Messwert hinterlegt ist, erscheint hier der Vergleich.</p>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <details class="charge-compare-editor">
          <summary><?= $energyComparison ? 'VoltCore-Zuordnung bearbeiten' : 'VoltCore-Zählerwert zuordnen' ?></summary>
          <form method="post" class="charge-compare-form">
            <?= Csrf::field() ?><input type="hidden" name="action" value="save_voltcore_energy">
            <label><span>Gemessene Energie (kWh)</span><input type="text" inputmode="decimal" name="voltcore_energy_kwh" value="<?= is_numeric($charge['voltcore_energy_kwh'] ?? null) ? e(number_format((float)$charge['voltcore_energy_kwh'],3,',','')) : '' ?>" placeholder="z. B. 2,14" maxlength="15"></label>
            <label><span>VoltCore-Session-ID (optional)</span><input type="text" inputmode="numeric" name="voltcore_session_ref" value="<?= e((string)($charge['voltcore_session_ref'] ?? '')) ?>" placeholder="z. B. 547" maxlength="40"></label>
            <button type="submit" class="btn btn-primary">Messwert speichern</button>
          </form>
          <p class="small">Nur Messwerte derselben Ladesession eintragen. Zum Entfernen beide Felder leeren und speichern. Die Tesla-Energie bleibt unverändert.</p>
        </details>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel charge-cost-panel" id="charge-cost-details">
    <div class="panel-head">
      <div><h3>💶 Kosten & Ladeort</h3><span class="small">Tatsächlich bezahlten Betrag eintragen – TrakFog berechnet den effektiven kWh-Preis</span></div>
      <span class="pill <?= $costAmount !== null ? 'ok' : 'warn' ?>"><?= $costAmount !== null ? 'bestätigt' : 'Kosten offen' ?></span>
    </div>
    <div class="panel-body">
      <?php if ($isAdmin): ?>
        <form method="post" class="charge-cost-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save_cost">
          <label>
            <span>Ladeort / Bezeichnung</span>
            <input name="location_name" value="<?= e((string)($charge['location_name'] ?? '')) ?>" placeholder="z. B. Zuhause oder Supercharger Köln">
          </label>
          <label>
            <span>Tatsächlich bezahlt</span>
            <input name="cost_amount" inputmode="decimal" value="<?= $costAmount !== null ? e(number_format($costAmount,2,'.','')) : '' ?>" placeholder="z. B. 0,00">
          </label>
          <label>
            <span>Währung</span>
            <input name="currency" maxlength="3" value="<?= e($currency) ?>">
          </label>
          <div class="charge-cost-help">
            <b>Ohne Bestätigung bleiben die Kosten offen.</b> Gib 0,00 € nur ein, wenn du eine tatsächlich kostenlose Ladung ausdrücklich bestätigen möchtest. Unbestätigte Sessions fließen nicht in die Kostenstatistik ein.
          </div>
          <div class="charge-cost-actions">
            <button class="btn btn-primary" type="submit">Gesamtpreis bestätigen</button>
          </div>
        </form>
        <?php if ($costAmount !== null): ?>
          <form method="post" class="charge-cost-reset">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reset_cost">
            <button class="btn btn-ghost" type="submit">Kosten wieder als offen markieren</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <div class="data-list">
          <div class="data-row"><span>Ladeort</span><b><?= e($chargePlace['label']) ?></b></div>
          <div class="data-row"><span>Gesamtpreis</span><b><?= $costAmount !== null ? number_format($costAmount,2,',','.').' '.e($currency) : '– · Kosten offen' ?></b></div>
          <div class="data-row"><span>Effektiver Preis</span><b><?= $pricePerKwh !== null ? number_format($pricePerKwh,4,',','.').' '.e($currency).'/kWh' : '–' ?></b></div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <div class="analytics-chart-grid charge-chart-grid">
    <section class="panel analytics-chart-panel analytics-chart-wide">
      <div class="panel-head">
        <div><h3>⚡ Ladekurve</h3><span class="small">Ladeleistung über die Session</span></div>
        <span class="pill <?= $active ? 'ok' : '' ?>"><?= $active ? 'live' : 'Session' ?></span>
      </div>
      <div class="panel-body">
        <div class="tf-chart" data-chart="<?= $chartJson($powerChart) ?>"></div>
      </div>
    </section>

    <section class="panel analytics-chart-panel">
      <div class="panel-head">
        <div><h3>🔋 State of Charge</h3><span class="small">Akkustand über die Zeit</span></div>
        <span class="pill">SoC</span>
      </div>
      <div class="panel-body">
        <div class="tf-chart" data-chart="<?= $chartJson($socChart) ?>"></div>
      </div>
    </section>

    <section class="panel analytics-chart-panel">
      <div class="panel-head">
        <div><h3>↗ Geladene Energie</h3><span class="small">Tesla charge_energy_added</span></div>
        <span class="pill">kWh</span>
      </div>
      <div class="panel-body">
        <div class="tf-chart" data-chart="<?= $chartJson($energyChart) ?>"></div>
      </div>
    </section>
  </div>

  <p class="analytics-note">Ladekurven und Kosten arbeiten ausschließlich mit gespeicherten TrakFog-Daten. Unbestätigte 0,00 € zählen nicht als kostenlose Ladung und fließen nicht in Kosten-Durchschnittswerte ein.</p>
</div>
<script src="assets/js/charts.js?v=<?= e(app_version()) ?>"></script>
<?php render_footer(); ?>
