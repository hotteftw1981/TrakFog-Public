<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$completed = SetupService::completed($pdo);
$userCount = SetupService::userCount($pdo);

if ($completed) {
    header('Location: ' . (Auth::check() ? 'app.php' : 'login.php'));
    exit;
}

if ($userCount > 0 && !Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::check() ? Auth::user($pdo) : null;
if ($user && !in_array((string)($user['role'] ?? ''), ['owner','admin'], true)) {
    ErrorHandler::forbidden();
}

$error = null;
$message = null;
$step = strtolower(trim((string)($_GET['step'] ?? '')));
$integration = $user ? TeslaService::integration($pdo) : null;
$connected = ($integration['status'] ?? '') === 'connected' && !empty($integration['access_token_enc']);

if ($step === '') {
    $step = $userCount === 0 ? 'owner' : ($connected ? 'data' : 'tesla');
}
if (!in_array($step, ['owner','tesla','data'], true)) {
    $step = 'owner';
}
if ($userCount > 0 && $step === 'owner') {
    $step = $connected ? 'data' : 'tesla';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen. Bitte erneut versuchen.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'claim_owner') {
                if (SetupService::userCount($pdo) !== 0) {
                    throw new RuntimeException('Diese Installation besitzt bereits einen Benutzer.');
                }
                $userId = SetupService::claimOwner(
                    $pdo,
                    (string)($_POST['username'] ?? ''),
                    (string)($_POST['email'] ?? ''),
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['password_confirm'] ?? ''),
                    (string)($_POST['setup_token'] ?? '')
                );
                if (!Auth::loginUser($pdo, $userId)) {
                    throw new RuntimeException('Owner wurde angelegt, konnte aber nicht automatisch angemeldet werden.');
                }
                header('Location: setup.php?step=tesla');
                exit;
            }

            if (!Auth::check()) {
                throw new RuntimeException('Bitte zuerst den Owner-Account anlegen.');
            }

            $user = Auth::user($pdo);
            if (!$user || !in_array((string)$user['role'], ['owner','admin'], true)) {
                ErrorHandler::forbidden();
            }

            if ($action === 'save_tesla') {
                $access = trim((string)($_POST['access_token'] ?? ''));
                $refresh = trim((string)($_POST['refresh_token'] ?? ''));
                TeslaService::saveTokens($pdo, $config, $access, $refresh, (int)$user['id']);
                $test = TeslaService::testConnection($pdo, $config);
                $count = TeslaService::syncVehicles($pdo, $config);
                $_SESSION['setup_notice'] = 'Tesla verbunden · ' . $count . ' Fahrzeug(e) erkannt'
                    . (!empty($test['refreshed']) ? ' · Access Token wurde erneuert' : '') . '.';
                header('Location: setup.php?step=data');
                exit;
            }

            if ($action === 'skip_tesla') {
                header('Location: setup.php?step=data');
                exit;
            }

            if (in_array($action, ['finish_dashboard','finish_import'], true)) {
                SetupService::complete($pdo, (int)$user['id']);
                header('Location: ' . ($action === 'finish_import' ? 'migration.php' : 'app.php'));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException || $e instanceof TeslaApiException
                ? $e->getMessage()
                : report_exception($e, 'Ersteinrichtung konnte nicht abgeschlossen werden.');
        }
    }
}

if (!empty($_SESSION['setup_notice'])) {
    $message = (string)$_SESSION['setup_notice'];
    unset($_SESSION['setup_notice']);
}

$userCount = SetupService::userCount($pdo);
$user = Auth::check() ? Auth::user($pdo) : null;
$integration = $user ? TeslaService::integration($pdo) : null;
$connected = ($integration['status'] ?? '') === 'connected' && !empty($integration['access_token_enc']);
$checks = SetupService::checks($pdo, $config);
$blockingChecksOk = count(array_filter(
    $checks,
    static fn(array $check): bool => !empty($check['required']) && empty($check['ok'])
)) === 0;

render_header('Ersteinrichtung', '', false, true);
?>
<div class="installer setup-wizard">
  <div class="setup-head">
    <div class="setup-brand">
      <img src="assets/brand/trakfog-logo.svg" alt="TrakFog">
      <div><span class="kicker">Ersteinrichtung</span><h1>TrakFog startklar machen.</h1></div>
    </div>
    <span class="pill">V<?= e(app_version()) ?></span>
  </div>

  <div class="setup-progress" aria-label="Einrichtungsschritte">
    <span class="<?= $userCount > 0 ? 'done' : ($step === 'owner' ? 'active' : '') ?>"><b>1</b> Owner</span>
    <span class="<?= $connected ? 'done' : ($step === 'tesla' ? 'active' : '') ?>"><b>2</b> Tesla</span>
    <span class="<?= $step === 'data' ? 'active' : '' ?>"><b>3</b> Daten</span>
  </div>

  <?php if ($message): ?><div class="alert alert-ok">✅ <?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <?php if ($step === 'owner'): ?>
    <div class="setup-layout">
      <section class="panel">
        <div class="panel-head"><div><h3>👤 Ersten Owner anlegen</h3><span class="small">Dieser Account besitzt die vollständige lokale Administration.</span></div></div>
        <div class="panel-body">
          <form class="form" method="post" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="claim_owner">
            <div class="field"><label>Benutzername</label><input name="username" maxlength="80" pattern="[A-Za-z0-9._-]{3,80}" value="admin" required autofocus></div>
            <div class="field"><label>E-Mail</label><input name="email" type="email" maxlength="190" required></div>
            <div class="field"><label>Installationsschlüssel</label><input name="setup_token" type="password" autocomplete="off" spellcheck="false" minlength="64" maxlength="64" required placeholder="64 Zeichen aus dem Docker-Webcontainer"></div>
            <div class="hint">Nur der Serverbetreiber kennt diesen einmalig erzeugten Schlüssel. Mit Docker: <code>docker exec trakfog-web cat /var/lib/trakfog/setup_token</code>. In Portainer: Console des Containers <code>trakfog-web</code> öffnen und <code>cat /var/lib/trakfog/setup_token</code> ausführen. Den Schlüssel nicht veröffentlichen.</div>
            <div class="install-grid">
              <div class="field"><label>Passwort</label><input name="password" type="password" minlength="10" autocomplete="new-password" required></div>
              <div class="field"><label>Passwort wiederholen</label><input name="password_confirm" type="password" minlength="10" autocomplete="new-password" required></div>
            </div>
            <div class="hint">Mindestens 10 Zeichen. Das Passwort bleibt ausschließlich als sicherer Passwort-Hash in deiner TrakFog-Datenbank.</div>
            <button class="btn btn-primary" type="submit" <?= $blockingChecksOk ? '' : 'disabled' ?>>Owner anlegen &amp; weiter</button>
          </form>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><div><h3>🩺 Systemcheck</h3><span class="pill <?= $blockingChecksOk ? 'ok' : 'warn' ?>"><?= $blockingChecksOk ? 'bereit' : 'prüfen' ?></span></div></div>
        <div class="panel-body">
          <div class="setup-checks">
            <?php foreach ($checks as $check): ?>
              <div>
                <span class="status-led <?= !empty($check['neutral']) || !empty($check['ok']) ? 'ok' : 'warn' ?>"></span>
                <span><strong><?= e((string)$check['label']) ?></strong><small><?= e((string)$check['detail']) ?></small></span>
                <b><?= !empty($check['required']) ? 'Pflicht' : 'Hinweis' ?></b>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="hint">HTTPS darf beim lokalen Erststart noch fehlen. Für den späteren externen Zugriff sollte TrakFog hinter einem HTTPS-Reverse-Proxy laufen.</p>
        </div>
      </section>
    </div>
  <?php elseif ($step === 'tesla'): ?>
    <section class="panel setup-main-card">
      <div class="panel-head">
        <div><h3>🚗 Tesla verbinden</h3><span class="small">Optional jetzt – oder jederzeit später unter System.</span></div>
        <span class="pill <?= $connected ? 'ok' : '' ?>"><?= $connected ? 'verbunden' : 'optional' ?></span>
      </div>
      <div class="panel-body">
        <?php if ($connected): ?>
          <div class="alert alert-ok">Tesla ist bereits verbunden. Du kannst direkt mit der Datenübernahme fortfahren.</div>
          <div class="split-actions"><a class="btn btn-primary" href="setup.php?step=data">Weiter zu Daten →</a></div>
        <?php else: ?>
          <p class="small">TrakFog speichert niemals dein Tesla-Passwort. Access- und Refresh-Token werden mit deinem lokalen App-Key verschlüsselt gespeichert.</p>
          <div class="alert alert-warn">
            Noch keine Tokens? Mit Tesla Auth kannst du sie direkt bei Tesla erzeugen.
            <div style="margin-top:10px"><a class="btn btn-ghost" href="https://github.com/adriankumpf/tesla_auth/releases/latest" target="_blank" rel="noopener noreferrer">Tesla Auth öffnen ↗</a></div>
          </div>
          <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_tesla">
            <div class="field"><label>Access Token</label><input name="access_token" type="password" autocomplete="off" placeholder="eyJ…" required></div>
            <div class="field"><label>Refresh Token</label><input name="refresh_token" type="password" autocomplete="off" placeholder="eyJ…" required></div>
            <div class="split-actions">
              <button class="btn btn-primary" type="submit">Speichern, testen &amp; Fahrzeuge erkennen</button>
            </div>
          </form>
          <form method="post" class="setup-skip-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="skip_tesla">
            <button class="btn btn-ghost" type="submit">Tesla später verbinden</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  <?php else: ?>
    <section class="panel setup-main-card">
      <div class="panel-head">
        <div><h3>↔ Historie mitnehmen?</h3><span class="small">Optionaler letzter Schritt – deine Daten gehören dir.</span></div>
        <span class="pill">TeslaMate + TrakFog</span>
      </div>
      <div class="panel-body">
        <div class="setup-choice-grid">
          <div class="setup-choice">
            <span class="setup-choice-icon">↔</span>
            <h3>Vorhandene Daten übernehmen</h3>
            <p>TeslaMate-Backup oder einen portablen TrakFog-Export nach der Einrichtung analysieren und kontrolliert importieren.</p>
            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="finish_import">
              <button class="btn btn-primary" type="submit">Einrichtung abschließen &amp; Import öffnen</button>
            </form>
          </div>
          <div class="setup-choice">
            <span class="setup-choice-icon">🚀</span>
            <h3>Frisch starten</h3>
            <p>Direkt zum Dashboard. Das Migrationscenter bleibt später jederzeit unter System erreichbar.</p>
            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="finish_dashboard">
              <button class="btn btn-ghost" type="submit">Einrichtung abschließen</button>
            </form>
          </div>
        </div>
        <div class="setup-summary">
          <span><b>Owner</b><small><?= e((string)($user['username'] ?? 'bereit')) ?></small></span>
          <span><b>Tesla</b><small><?= $connected ? 'verbunden' : 'noch nicht verbunden' ?></small></span>
          <span><b>Migration</b><small>jederzeit verfügbar</small></span>
        </div>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
