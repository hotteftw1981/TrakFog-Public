<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$message = $error = $warning = null;
$integration = TeslaService::integration($pdo);

$formatUtc = static function (?string $value): string {
    if (!$value) {
        return '—';
    }

    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen. Bitte erneut versuchen.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'save_tesla') {
                $access = trim((string)($_POST['access_token'] ?? ''));
                $refresh = trim((string)($_POST['refresh_token'] ?? ''));

                TeslaService::saveTokens($pdo, $config, $access, $refresh, (int)Auth::id());
                $test = TeslaService::testConnection($pdo, $config);
                $count = TeslaService::syncVehicles($pdo, $config);

                $message = 'Tesla verbunden · ' . $count . ' Fahrzeug(e) erkannt'
                    . ($test['refreshed'] ? ' · Access Token wurde direkt erneuert' : '') . '.';
            } elseif ($action === 'test_tesla') {
                $test = TeslaService::testConnection($pdo, $config);
                $message = 'Verbindung erfolgreich · ' . $test['vehicle_count'] . ' Fahrzeug(e) erreichbar'
                    . ($test['refreshed'] ? ' · Token automatisch erneuert' : '') . '.';
            } elseif ($action === 'refresh_tesla') {
                TeslaService::refreshTokens($pdo, $config);
                $message = 'Refresh Token erfolgreich getestet · neuer Access Token gespeichert.';
            } elseif ($action === 'sync_tesla') {
                $count = TeslaService::syncVehicles($pdo, $config);
                $message = $count . ' Tesla-Fahrzeug(e) synchronisiert.';
            } elseif ($action === 'disconnect_tesla') {
                if ($integration) {
                    $pdo->prepare(
                        "UPDATE integrations
                         SET status='disconnected',access_token_enc=NULL,refresh_token_enc=NULL,token_expires_at=NULL,last_error=NULL
                         WHERE id=?"
                    )->execute([$integration['id']]);
                }
                $message = 'Tesla-Verbindung getrennt. Bereits gespeicherte Fahrzeughistorie bleibt erhalten.';
            }
        } catch (Throwable $e) {
            $ref = AppLogger::exception($e, ['connector' => 'tesla', 'action' => $action]);
            $public = $e instanceof TeslaApiException || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Tesla-Aktion fehlgeschlagen.';

            $recoverableTeslaError = $e instanceof TeslaApiException
                && in_array($e->statusCode, [403, 408, 412, 429], true);

            $integrationForError = TeslaService::integration($pdo);

            if ($recoverableTeslaError) {
                $warning = $public . ' · Die gespeicherte Tesla-Verbindung bleibt aktiv. · Fehler-ID: ' . $ref;
                if ($integrationForError && !empty($integrationForError['access_token_enc'])) {
                    $pdo->prepare(
                        "UPDATE integrations SET status='connected',last_error=? WHERE id=?"
                    )->execute([$public, $integrationForError['id']]);
                }
            } else {
                $error = $public . ' · Fehler-ID: ' . $ref;
                if ($integrationForError) {
                    $pdo->prepare(
                        "UPDATE integrations SET status='error',last_error=? WHERE id=?"
                    )->execute([$public, $integrationForError['id']]);
                }
            }
        }
    }

    $integration = TeslaService::integration($pdo);
}

$vehicles = [];
if ($integration) {
    $stmt = $pdo->prepare(
        "SELECT id,display_name,vin,state,last_seen_at
         FROM vehicles
         WHERE integration_id=?
         ORDER BY display_name,id"
    );
    $stmt->execute([$integration['id']]);
    $vehicles = $stmt->fetchAll();
}

$connected = ($integration['status'] ?? '') === 'connected' && !empty($integration['access_token_enc']);
$expiresAt = $integration['token_expires_at'] ?? null;
$expiresTs = $expiresAt ? strtotime($expiresAt . ' UTC') : false;
$tokenExpired = $expiresTs !== false && $expiresTs <= time();
$tokenSoon = $expiresTs !== false && !$tokenExpired && ($expiresTs - time()) < 86400;

render_header('Connect', 'system');
?>
<div class="page wrap">
  <div class="page-head">
    <div>
      <span class="kicker">TrakFog Connect</span>
      <h1>Deine Tesla-Verbindung.</h1>
      <p>TrakFog ist ab jetzt Tesla-only. Hier verwaltest du Tokens, Verbindung, Fahrzeugerkennung und Synchronisation.</p>
    </div>
    <span class="pill <?= $connected ? 'ok' : (($integration['status'] ?? '') === 'error' ? 'warn' : '') ?>">
      <?= $connected ? '● Tesla verbunden' : e($integration['status'] ?? 'Tesla nicht verbunden') ?>
    </span>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($warning): ?><div class="alert alert-warn">⚠️ <?= e($warning) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <div class="grid-2">
    <section class="panel">
      <div class="panel-head">
        <h3>🚗 Tesla Owner API</h3>
        <span class="pill <?= $connected ? 'ok' : (($integration['status'] ?? '') === 'error' ? 'warn' : '') ?>">
          <?= $connected ? 'verbunden' : e($integration['status'] ?? 'nicht verbunden') ?>
        </span>
      </div>

      <div class="panel-body">
        <p class="small">
          TrakFog speichert niemals dein Tesla-Passwort. Access- und Refresh-Token werden mit deinem lokalen TrakFog-App-Key verschlüsselt gespeichert.
          Die reine Verbindungs-/Fahrzeugliste weckt dein Auto nicht absichtlich auf.
        </p>

        <?php if (!$integration || empty($integration['access_token_enc'])): ?>
          <div class="alert alert-warn" style="margin-bottom:16px">
            <strong>🔑 Noch keine Tesla-Tokens?</strong><br>
            <span class="small">Mit Tesla Auth kannst du dich direkt bei Tesla anmelden und Access- sowie Refresh-Token erzeugen. TrakFog bekommt dabei niemals dein Tesla-Passwort.</span>
            <div style="margin-top:10px">
              <a class="btn btn-ghost" href="https://github.com/adriankumpf/tesla_auth/releases/latest" target="_blank" rel="noopener noreferrer">Tesla Auth herunterladen ↗</a>
            </div>
          </div>

          <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_tesla">

            <div class="field">
              <label>Access Token</label>
              <input name="access_token" type="password" autocomplete="off" placeholder="eyJ…" required>
              <div class="hint">Wird nur verschlüsselt gespeichert und nie wieder im Klartext angezeigt.</div>
            </div>

            <div class="field">
              <label>Refresh Token</label>
              <input name="refresh_token" type="password" autocomplete="off" placeholder="eyJ…" required>
              <div class="hint">Damit kann TrakFog einen abgelaufenen Access Token automatisch erneuern.</div>
            </div>

            <button class="btn btn-primary" type="submit">🔐 Speichern, testen & Fahrzeuge erkennen</button>
          </form>
        <?php else: ?>
          <div class="token-state" id="token-details">
            <a class="stat tf-stat-link" href="#token-details" title="Details zu Verbindung" aria-label="Details zu Verbindung öffnen">
              <strong><?= $connected ? '✓ gültig' : e($integration['status'] ?? '—') ?></strong>
              <span>Verbindung</span>
            </a>
            <a class="stat tf-stat-link" href="drive.php#vehicle-list" title="Details zu Fahrzeuge" aria-label="Details zu Fahrzeuge öffnen">
              <strong><?= count($vehicles) ?></strong>
              <span>Fahrzeuge</span>
            </a>
            <a class="stat tf-stat-link" href="#token-details" title="Details zu Access Token" aria-label="Details zu Access Token öffnen">
              <strong class="<?= $tokenExpired ? '' : 'health-ok' ?>" style="<?= !$tokenExpired ? 'padding:3px 8px;border-radius:999px;display:inline-block' : '' ?>">
                <?= $tokenExpired ? 'abgelaufen' : ($tokenSoon ? 'bald fällig' : ($expiresAt ? 'aktiv' : 'unbekannt')) ?>
              </strong>
              <span>Access Token</span>
            </a>
          </div>

          <div class="data-list">
            <div class="data-row"><span>Token läuft ab</span><b><?= e($formatUtc($expiresAt)) ?></b></div>
            <div class="data-row"><span>Letzter API-Sync</span><b><?= e($formatUtc($integration['last_sync_at'] ?? null)) ?></b></div>
            <div class="data-row"><span>Auto-Polling</span><b>aus · schlaf-freundlich 😴</b></div>
          </div>

          <div class="connector-actions">
            <a class="btn btn-ghost" href="https://github.com/adriankumpf/tesla_auth/releases/latest" target="_blank" rel="noopener noreferrer">🔑 Tesla Auth ↗</a>
            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="test_tesla">
              <button class="btn btn-primary" type="submit">✓ Verbindung testen</button>
            </form>

            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="refresh_tesla">
              <button class="btn btn-ghost" type="submit">↻ Refresh Token testen</button>
            </form>

            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="sync_tesla">
              <button class="btn btn-ghost" type="submit">🚗 Fahrzeuge synchronisieren</button>
            </form>

            <form method="post" onsubmit="return confirm('Tesla-Verbindung wirklich trennen?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="disconnect_tesla">
              <button class="btn btn-danger" type="submit">Verbindung trennen</button>
            </form>
          </div>
        <?php endif; ?>

        <?php if (!empty($integration['last_error'])): ?>
          <div class="alert alert-warn" style="margin-top:16px">Letzter API-Fehler: <?= e($integration['last_error']) ?></div>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h3>🚘 Erkannte Fahrzeuge</h3>
        <span class="pill <?= count($vehicles) ? 'ok' : '' ?>"><?= count($vehicles) ?> erkannt</span>
      </div>
      <div class="panel-body">
        <?php if (!$vehicles): ?>
          <div class="empty">Noch kein Fahrzeug synchronisiert.<br><span class="small">Nach erfolgreicher Verbindung taucht dein Tesla hier automatisch auf.</span></div>
        <?php else: ?>
          <div class="vehicle-mini-list">
            <?php foreach ($vehicles as $vehicle): ?>
              <?php
                $vin = (string)($vehicle['vin'] ?? '');
                $vinShort = $vin !== '' ? '••••••' . substr($vin, -6) : 'VIN unbekannt';
              ?>
              <div class="vehicle-mini">
                <div>
                  <strong><?= e($vehicle['display_name'] ?: 'Tesla') ?></strong>
                  <div class="small"><?= e($vinShort) ?> · zuletzt <?= e($formatUtc($vehicle['last_seen_at'] ?? null)) ?></div>
                </div>
                <div class="split-actions">
                  <span class="pill <?= ($vehicle['state'] ?? '') === 'online' ? 'ok' : '' ?>"><?= e($vehicle['state'] ?: 'unbekannt') ?></span>
                  <a class="btn btn-ghost" href="vehicle.php?id=<?= (int)$vehicle['id'] ?>">Drive öffnen →</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="separator" style="margin:20px 0"></div>

        <div class="data-list">
          <div class="data-row"><span>Tesla Owner API</span><span class="pill <?= $connected ? 'ok' : '' ?>"><?= $connected ? 'bereit' : 'off' ?></span></div>
          <div class="data-row"><span>Fahrzeughistorie</span><span class="pill ok">lokal</span></div>
          <div class="data-row"><span>Auto-Polling</span><span class="pill">noch aus</span></div>
          <div class="data-row"><span>Fleet API</span><span class="pill">nicht verwendet</span></div>
        </div>
      </div>
    </section>
  </div>
</div>
<?php render_footer(); ?>
