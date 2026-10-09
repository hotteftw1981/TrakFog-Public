<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$user = Auth::user($pdo);
$canManage = in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
$message = null;
$error = null;

$setSetting = static function (PDO $pdo, string $key, mixed $value): void {
    $stmt = $pdo->prepare(
        "INSERT INTO settings(setting_key,setting_value)
         VALUES(?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
    );
    $stmt->execute([$key,$value]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_liveview') {
                $mode = (string)($_POST['liveview_mode'] ?? 'disabled');
                $rememberDays = max(1, min(365, (int)($_POST['remember_days'] ?? 90)));
                $defaultVehicleId = max(0, (int)($_POST['default_vehicle_id'] ?? 0));
                $newPin = trim((string)($_POST['new_pin'] ?? ''));

                if (!in_array($mode, ['disabled','pin','open'], true)) {
                    $mode = 'disabled';
                }

                if ($defaultVehicleId > 0) {
                    $stmt = $pdo->prepare('SELECT id FROM vehicles WHERE id=? LIMIT 1');
                    $stmt->execute([$defaultVehicleId]);
                    if (!$stmt->fetchColumn()) {
                        throw new RuntimeException('Das gewählte Standardfahrzeug wurde nicht gefunden.');
                    }
                }

                $pinChanged = false;
                if ($newPin !== '') {
                    if (!preg_match('/^\d{6}$/', $newPin)) {
                        throw new RuntimeException('Die LiveView-PIN muss genau sechs Ziffern haben.');
                    }
                    $setSetting($pdo, 'liveview_pin_hash', password_hash($newPin, PASSWORD_DEFAULT));
                    $pinChanged = true;
                }

                if ($mode === 'pin' && !$pinChanged && !LiveViewAuth::pinConfigured($pdo)) {
                    throw new RuntimeException('Für den PIN-Modus zuerst eine sechsstellige PIN festlegen.');
                }

                $setSetting($pdo, 'liveview_mode', $mode);
                $setSetting($pdo, 'liveview_remember_days', (string)$rememberDays);
                $setSetting($pdo, 'liveview_default_vehicle_id', $defaultVehicleId > 0 ? (string)$defaultVehicleId : null);

                if ($pinChanged) {
                    LiveViewAuth::revokeAllDevices($pdo);
                }

                header('Location: settings.php?live=saved' . ($pinChanged ? '&pin=changed' : ''));
                exit;
            }

            if ($action === 'revoke_liveview_device') {
                LiveViewAuth::revokeDevice($pdo, max(0, (int)($_POST['device_id'] ?? 0)));
                header('Location: settings.php?live=device_revoked');
                exit;
            }

            if ($action === 'revoke_all_liveview_devices') {
                LiveViewAuth::revokeAllDevices($pdo);
                header('Location: settings.php?live=all_revoked');
                exit;
            }

            if ($action === 'save_tesla_cost_sync') {
                $enabled = isset($_POST['tesla_cost_sync_enabled']) ? '1' : '0';
                $interval = max(15, min(360, (int)($_POST['tesla_cost_sync_interval'] ?? 90)));
                $setSetting($pdo, 'tesla_charging_cost_sync_enabled', $enabled);
                $setSetting($pdo, 'tesla_charging_cost_sync_interval_minutes', (string)$interval);
                header('Location: settings.php?costsync=saved');
                exit;
            }

            if ($action === 'trigger_tesla_cost_sync') {
                $setSetting($pdo, 'tesla_charging_cost_sync_force', '1');
                header('Location: settings.php?costsync=triggered');
                exit;
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : report_exception($e, 'Einstellung konnte nicht gespeichert werden.');
        }
    }
}

$message = $message ?: match ((string)($_GET['live'] ?? '')) {
    'saved' => isset($_GET['pin'])
        ? 'LiveView gespeichert. Die neue PIN ist aktiv; bisherige vertrauenswürdige Geräte wurden abgemeldet.'
        : 'LiveView-Einstellungen gespeichert.',
    'device_revoked' => 'LiveView-Zugang für das Gerät wurde entzogen.',
    'all_revoked' => 'Alle vertrauenswürdigen LiveView-Geräte wurden abgemeldet.',
    default => null,
};

$message = $message ?: match ((string)($_GET['costsync'] ?? '')) {
    'saved' => 'Tesla Charging-History-Synchronisierung gespeichert.',
    'triggered' => 'Tesla Charging-History-Synchronisierung für den nächsten Engine-Tick vorgemerkt.',
    default => null,
};

$liveMode = LiveViewAuth::mode($pdo);
$livePinConfigured = LiveViewAuth::pinConfigured($pdo);
$rememberDays = max(1, min(365, (int)setting($pdo, 'liveview_remember_days', '90')));
$defaultVehicleId = max(0, (int)setting($pdo, 'liveview_default_vehicle_id', '0'));
$vehicles = $pdo->query(
    "SELECT id,display_name,vin
     FROM vehicles
     ORDER BY display_name,id"
)->fetchAll();
$devices = $canManage ? LiveViewAuth::devices($pdo) : [];

$teslaCostSyncEnabled = setting($pdo, 'tesla_charging_cost_sync_enabled', '1') !== '0';
$teslaCostSyncInterval = max(15, min(360, (int)setting($pdo, 'tesla_charging_cost_sync_interval_minutes', '90')));
$teslaCostSyncLastAt = setting($pdo, 'tesla_charging_cost_sync_last_at', null);
$teslaCostSyncLastError = setting($pdo, 'tesla_charging_cost_sync_last_error', null);
$teslaCostSyncSummaryRaw = setting($pdo, 'tesla_charging_cost_sync_last_summary', null);
$teslaCostSyncSummary = [];
if (is_string($teslaCostSyncSummaryRaw) && trim($teslaCostSyncSummaryRaw) !== '') {
    try {
        $decoded = json_decode($teslaCostSyncSummaryRaw, true, 512, JSON_THROW_ON_ERROR);
        $teslaCostSyncSummary = is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        $teslaCostSyncSummary = [];
    }
}

$fmtLocal = static function (?string $value): string {
    if (!$value) return '–';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d.m.Y H:i');
    } catch (Throwable) {
        return (string)$value;
    }
};

render_header('Einstellungen', 'system');
?>
<div class="page wrap">
  <div class="page-head">
    <div>
      <span class="kicker">System</span>
      <h1>Einstellungen</h1>
      <p>Tesla-Verbindung, LiveView-Zugang, Account, Diagnose und Updates.</p>
    </div>
    <div class="split-actions">
      <a class="btn btn-primary" href="live.php" target="_blank" rel="noopener">📺 LiveView öffnen</a>
      <?php if ($canManage): ?><a class="btn btn-ghost" href="diagnostics.php">🩺 Systemdiagnose</a><?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <?php if ($canManage): ?>
  <section class="panel settings-liveview-panel">
    <div class="panel-head">
      <div>
        <h3>📺 LiveView</h3>
        <span class="small">Eigener Vollbild-Zugang für Tesla, Tablet, Smartphone, Desktop und TV.</span>
      </div>
      <span class="pill <?= $liveMode === 'pin' ? 'ok' : ($liveMode === 'open' ? 'warn' : '') ?>">
        <?= $liveMode === 'pin' ? '🔐 PIN geschützt' : ($liveMode === 'open' ? '🌐 offen' : 'deaktiviert') ?>
      </span>
    </div>
    <div class="panel-body settings-liveview-body">
      <form method="post" class="settings-liveview-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_liveview">

        <div class="settings-liveview-modes">
          <label class="<?= $liveMode === 'pin' ? 'selected' : '' ?>">
            <input type="radio" name="liveview_mode" value="pin" <?= $liveMode === 'pin' ? 'checked' : '' ?>>
            <span>🔐</span>
            <div><strong>PIN geschützt</strong><small>Empfohlen · eigener 6-stelliger Zugang</small></div>
          </label>
          <label class="<?= $liveMode === 'open' ? 'selected' : '' ?>">
            <input type="radio" name="liveview_mode" value="open" <?= $liveMode === 'open' ? 'checked' : '' ?>>
            <span>🌐</span>
            <div><strong>Offen</strong><small>Kein PIN · nur für bewusst geschützte Netze</small></div>
          </label>
          <label class="<?= $liveMode === 'disabled' ? 'selected' : '' ?>">
            <input type="radio" name="liveview_mode" value="disabled" <?= $liveMode === 'disabled' ? 'checked' : '' ?>>
            <span>○</span>
            <div><strong>Deaktiviert</strong><small>Nur eingeloggte Admin-Vorschau möglich</small></div>
          </label>
        </div>

        <div class="settings-liveview-grid">
          <label>
            <span>Neue PIN</span>
            <input name="new_pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" placeholder="<?= $livePinConfigured ? '•••••• · leer = unverändert' : '6 Ziffern' ?>">
            <small><?= $livePinConfigured ? 'PIN ist eingerichtet. Eine neue PIN meldet alle gemerkten Geräte ab.' : 'Noch keine PIN eingerichtet.' ?></small>
          </label>

          <label>
            <span>Gerät merken</span>
            <select name="remember_days">
              <?php foreach ([7,30,60,90,180,365] as $days): ?>
                <option value="<?= $days ?>" <?= $rememberDays === $days ? 'selected' : '' ?>><?= $days ?> Tage</option>
              <?php endforeach; ?>
            </select>
            <small>Die PIN selbst wird niemals im Browser gespeichert.</small>
          </label>

          <label>
            <span>Standardfahrzeug</span>
            <select name="default_vehicle_id">
              <option value="0">automatisch</option>
              <?php foreach ($vehicles as $vehicle): ?>
                <option value="<?= (int)$vehicle['id'] ?>" <?= $defaultVehicleId === (int)$vehicle['id'] ? 'selected' : '' ?>>
                  <?= e((string)($vehicle['display_name'] ?: $vehicle['vin'] ?: 'Tesla')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small>Bei mehreren Teslas kann im LiveView trotzdem umgeschaltet werden.</small>
          </label>
        </div>

        <div class="settings-liveview-note">
          <span>🛡️</span>
          <div>
            <strong>LiveView hat keinen Backend-Login.</strong>
            <p>Der PIN-Zugang kann nur die dafür vorgesehenen Live-Daten lesen. Das Offenlassen des LiveViews löst keine zusätzlichen Tesla-Abfragen und keinen Wake-up aus.</p>
          </div>
        </div>

        <div class="split-actions">
          <button class="btn btn-primary" type="submit">LiveView speichern</button>
          <a class="btn btn-ghost" href="live.php" target="_blank" rel="noopener">Vorschau öffnen →</a>
        </div>
      </form>

      <div class="settings-liveview-future">
        <div><span>📍</span><strong>Standortfreigabe</strong><small>vorbereitet · aktuell privat</small></div>
        <div><span>👥</span><strong>Andere Teslas</strong><small>vorbereitet · Community-Sync später</small></div>
        <div><span>⭐</span><strong>Community-Orte</strong><small>vorbereitet · nur nach Freigabe sichtbar</small></div>
      </div>

      <div class="settings-liveview-devices">
        <div class="settings-subhead">
          <div><strong>Vertrauenswürdige Geräte</strong><small>Geräte mit gültigem LiveView-Token</small></div>
          <?php if ($devices): ?>
            <form method="post" onsubmit="return confirm('Alle vertrauenswürdigen LiveView-Geräte wirklich abmelden?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="revoke_all_liveview_devices">
              <button class="btn btn-ghost" type="submit">Alle abmelden</button>
            </form>
          <?php endif; ?>
        </div>

        <?php if (!$devices): ?>
          <div class="empty compact">Noch kein LiveView-Gerät gespeichert.</div>
        <?php else: ?>
          <div class="liveview-device-list">
            <?php foreach ($devices as $device): ?>
              <?php
                $active = empty($device['revoked_at']) && strtotime((string)$device['expires_at'].' UTC') > time();
              ?>
              <div class="liveview-device-row <?= $active ? '' : 'inactive' ?>">
                <span class="liveview-device-icon"><?= str_contains(strtolower((string)$device['label']),'tesla') ? '🚗' : '📱' ?></span>
                <div>
                  <strong><?= e((string)$device['label']) ?></strong>
                  <small>
                    <?= $active ? 'aktiv' : (!empty($device['revoked_at']) ? 'abgemeldet' : 'abgelaufen') ?>
                    · zuletzt <?= e($fmtLocal($device['last_used_at'] ?? null)) ?>
                    · gültig bis <?= e($fmtLocal((string)$device['expires_at'])) ?>
                  </small>
                </div>
                <?php if ($active): ?>
                  <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="revoke_liveview_device">
                    <input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
                    <button class="btn btn-ghost" type="submit">Zugang entziehen</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <section class="panel settings-costsync-panel">
    <div class="panel-head">
      <div>
        <h3>⚡ Tesla Supercharger-Kosten</h3>
        <span class="small">Übernimmt den tatsächlich abgerechneten Betrag aus Teslas Charging History – ohne Fahrzeug-Wakeup.</span>
      </div>
      <span class="pill <?= $teslaCostSyncEnabled ? 'ok' : '' ?>"><?= $teslaCostSyncEnabled ? 'automatisch aktiv' : 'deaktiviert' ?></span>
    </div>
    <div class="panel-body settings-costsync-body">
      <form method="post" class="settings-costsync-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_tesla_cost_sync">
        <label class="settings-costsync-toggle">
          <input type="checkbox" name="tesla_cost_sync_enabled" value="1" <?= $teslaCostSyncEnabled ? 'checked' : '' ?>>
          <span>
            <strong>Tesla Charging History automatisch synchronisieren</strong>
            <small>Erster erfolgreicher Lauf lädt die verfügbare Historie nach; danach werden neue Sessions regelmäßig geprüft. Manuell bestätigte Kosten werden nie überschrieben.</small>
          </span>
        </label>
        <label class="settings-costsync-interval">
          <span>Prüfintervall</span>
          <select name="tesla_cost_sync_interval">
            <?php foreach ([30,60,90,180,360] as $minutes): ?>
              <option value="<?= $minutes ?>" <?= $teslaCostSyncInterval === $minutes ? 'selected' : '' ?>><?= $minutes ?> Minuten</option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="split-actions">
          <button class="btn btn-primary" type="submit">Einstellung speichern</button>
        </div>
      </form>

      <div class="settings-costsync-status">
        <div><span>Letzter Lauf</span><b><?= e($fmtLocal($teslaCostSyncLastAt)) ?></b></div>
        <div><span>Sessions geprüft</span><b><?= isset($teslaCostSyncSummary['sessions']) ? number_format((int)$teslaCostSyncSummary['sessions'],0,',','.') : '–' ?></b></div>
        <div><span>Kosten übernommen</span><b><?= isset($teslaCostSyncSummary['updated']) ? number_format((int)$teslaCostSyncSummary['updated'],0,',','.') : '–' ?></b></div>
        <div><span>Nicht zugeordnet</span><b><?= isset($teslaCostSyncSummary['no_match']) ? number_format((int)$teslaCostSyncSummary['no_match'],0,',','.') : '–' ?></b></div>
      </div>

      <?php if ($teslaCostSyncLastError): ?>
        <div class="alert alert-error">⚠️ Letzter Tesla-History-Fehler: <?= e((string)$teslaCostSyncLastError) ?></div>
      <?php endif; ?>

      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="trigger_tesla_cost_sync">
        <button class="btn btn-ghost" type="submit">↻ Jetzt synchronisieren</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <div class="grid-2 settings-main-grid">
    <section class="panel">
      <div class="panel-head"><h3>🚗 Tesla</h3></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>Verbindung</span><a class="btn btn-ghost" href="connect.php">Connect öffnen →</a></div>
          <div class="data-row"><span>Drive</span><a class="btn btn-ghost" href="drive.php">Fahrzeuge öffnen →</a></div>
          <div class="data-row"><span>Datenhaltung</span><b>lokale MariaDB</b></div>
          <div class="data-row"><span>TrakFog Engine</span><a class="btn btn-ghost" href="worker.php">◉ Engine öffnen →</a></div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>👤 Account</h3></div>
      <div class="panel-body">
        <div class="data-list">
          <div class="data-row"><span>Benutzer</span><b><?= e($user['username']) ?></b></div>
          <div class="data-row"><span>E-Mail</span><b><?= e($user['email']) ?></b></div>
          <div class="data-row"><span>Rolle</span><b><?= e($user['role']) ?></b></div>
          <div class="data-row"><span>Version</span><b>V<?= e(app_version()) ?></b></div>
        </div>
      </div>
    </section>
  </div>
</div>
<script>
document.querySelectorAll('.settings-liveview-modes label').forEach(label=>{
  label.addEventListener('click',()=>{
    document.querySelectorAll('.settings-liveview-modes label').forEach(item=>item.classList.remove('selected'));
    label.classList.add('selected');
  });
});
</script>
<section class="panel" style="margin-top:16px">
  <div class="panel-head">
    <div>
      <h3>🧭 TrakFog Roadmap</h3>
      <span class="small">Kosten-Grundsatz: TrakFog soll ohne laufende Tesla-API-Gebühren nutzbar bleiben.</span>
    </div>
    <span class="pill">V<?= e(app_version()) ?></span>
  </div>
  <div class="panel-body trakfog-roadmap-grid">
    <article class="trakfog-roadmap-card now"><span>✅</span><h4>Jetzt eingebaut</h4><ul>
      <li>Update-Radar</li><li>Tesla Deep Data</li><li>Alerts / Service / Nearby / Release Notes Cache</li>
      <li>Supercharger-Kosten</li><li>NerdView-Polish</li><li>GitHub-Pages-Quelle</li>
    </ul></article>
    <article class="trakfog-roadmap-card next"><span>🔜</span><h4>Als Nächstes</h4><ul>
      <li>LiveView & NerdView weiterentwickeln</li><li>eigene POIs & Kartenebenen</li><li>Community-Teslas & Freigaben vorbereiten</li>
      <li>Benachrichtigungen aus lokalen Daten</li><li>Fahrten/Laden/Reisen weiter auswerten</li>
    </ul></article>
    <article class="trakfog-roadmap-card"><span>🚫</span><h4>Bewusst nicht geplant</h4><ul>
      <li>bezahlte Fleet API als Voraussetzung</li><li>Fleet Telemetry mit Pay-per-Use</li>
      <li>Pflicht-Billingkonto bei Tesla</li><li>Remote Commands über kostenpflichtige API</li>
    </ul></article>
  </div>
</section>

<?php render_footer(); ?>
