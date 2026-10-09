<?php
require dirname(__DIR__) . '/src/bootstrap.php';

if (SetupService::required($pdo) && SetupService::userCount($pdo) === 0) {
    header('Location: setup.php');
    exit;
}

if (Auth::check()) {
    header('Location: ' . (SetupService::required($pdo) ? 'setup.php' : 'app.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen. Bitte erneut versuchen.';
    } elseif (Auth::attempt($pdo, (string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: ' . (SetupService::required($pdo) ? 'setup.php' : 'app.php'));
        exit;
    } else {
        $error = 'Benutzername/E-Mail oder Passwort ist nicht korrekt.';
    }
}

render_header('Anmelden');
?>
<div class="login-wrap">
  <section class="login-card">
    <div class="login-brand">
      <img class="login-brand-logo" src="assets/brand/trakfog-logo.svg" alt="TrakFog">
      <span class="login-brand-tagline">Your Tesla. Your data.</span>
    </div>

    <h1>Willkommen zurück</h1>
    <p>Melde dich an, um Fahrzeugdaten, Fahrten, Ladevorgänge und den Tesla Live-Stream zu verwalten.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <form class="form" method="post">
      <?= Csrf::field() ?>
      <div class="field">
        <label>Benutzername oder E-Mail</label>
        <input name="login" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label>Passwort</label>
        <div class="password-field">
          <input id="loginPassword" name="password" type="password" autocomplete="current-password" required>
          <button class="password-toggle" id="passwordToggle" type="button" aria-label="Passwort anzeigen">Anzeigen</button>
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Anmelden</button>
    </form>

    <div class="auth-actions">
      <button type="button" id="authTheme">◐ Darstellung wechseln</button>
      <span class="auth-version">V<?= e(app_version()) ?> · private beta</span>
    </div>
  </section>
</div>
<?php render_footer(); ?>
