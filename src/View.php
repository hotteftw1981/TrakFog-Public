<?php

declare(strict_types=1);

function trakfog_section_label(string $active): string
{
    return match ($active) {
        'drive' => 'Fahrzeug',
        'trips', 'charges', 'journeys' => 'Historie',
        'statistics', 'sleep' => 'Auswertung',
        'map' => 'Orte',
        'system', 'connect', 'engine', 'settings' => 'System',
        default => 'Übersicht',
    };
}

function render_trakfog_info_content(string $areaLabel = 'TrakFog'): void
{
    ?>
    <div class="trakfog-info-grid">
        <div class="trakfog-info-stat">
            <span>Status</span>
            <strong><i class="trakfog-info-dot"></i>Community Edition</strong>
        </div>
        <div class="trakfog-info-stat">
            <span>Version</span>
            <strong>V<?= e(app_version()) ?></strong>
        </div>
        <div class="trakfog-info-stat">
            <span>Plattform</span>
            <strong>TrakFog</strong>
        </div>
        <div class="trakfog-info-stat">
            <span>Bereich</span>
            <strong><?= e($areaLabel) ?></strong>
        </div>
    </div>

    <p class="trakfog-info-copy">
        Self-hosted Tesla-Datenplattform für Fahrzeugdaten, Fahrten, Ladevorgänge, Reisen,
        LiveView und Langzeitauswertungen.
    </p>

    <div class="trakfog-info-credits">
        <div>
            <span>Konzeption &amp; Entwicklung</span>
            <strong>Patrick Garbe</strong>
            <small>Projektinitiator &amp; Entwickler</small>
        </div>
        <div>
            <span>Technische Unterstützung</span>
            <strong>Buddy ⚡</strong>
            <small>Digitale Entwicklungsassistenz</small>
        </div>
    </div>

    <div class="trakfog-info-meta">
        <span>Community Edition</span>
        <span>AGPL-3.0-only</span>
        <span>Unabhängiges Projekt · nicht mit Tesla, Inc. verbunden</span>
    </div>
    <?php
}

function render_header(string $title, string $active = '', bool $mapAssets = false, bool $standalone = false): void
{
    global $pdo;

    $GLOBALS['trakfog_active_page'] = $active;
    $GLOBALS['trakfog_map_assets'] = $mapAssets || $active === 'map';
    $user = !$standalone && class_exists('Auth') && Auth::check() ? Auth::user($pdo) : null;
    $GLOBALS['trakfog_view_authenticated'] = $user !== null;

    $fullTitle = $title === 'TrakFog' ? 'TrakFog' : $title . ' · TrakFog';

    $engineHealthy = false;
    if ($user) {
        $heartbeat = setting($pdo, 'worker_last_heartbeat', null);
        if ($heartbeat) {
            $ts = strtotime((string)$heartbeat . ' UTC');
            $engineHealthy = $ts !== false && (time() - $ts) <= 120;
        }
    }
    ?>
<!doctype html>
<html lang="de" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#06111f">
    <meta name="robots" content="noindex,nofollow">
    <meta name="application-name" content="TrakFog">
    <meta name="apple-mobile-web-app-title" content="TrakFog">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= e($fullTitle) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/brand/trakfog-icon.svg">
    <link rel="shortcut icon" href="assets/brand/trakfog-icon.svg">
    <link rel="apple-touch-icon" href="assets/brand/trakfog-icon.svg">
    <link rel="manifest" href="site.webmanifest?v=<?= e(app_version()) ?>">
    <script>
      (() => {
        const saved = localStorage.getItem('trakfog-theme');
        document.documentElement.dataset.theme = saved || 'dark';
      })();
    </script>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e(app_version()) ?>">
    <?php if ($active === 'home'): ?><link rel="stylesheet" href="assets/css/living-garage.css?v=<?= e(app_version()) ?>"><?php endif; ?>
    <?php if ($active === 'trips'): ?><link rel="stylesheet" href="assets/css/trip-merge.css?v=<?= e(app_version()) ?>"><?php endif; ?>
    <?php if ($mapAssets || $active === 'map'): ?>
        <link rel="preconnect" href="https://unpkg.com">
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    <?php endif; ?>
</head>
<body class="<?= $user ? 'app-page' : 'auth-page' ?><?= $user && $active === 'map' ? ' map-workspace' : '' ?>">
<?php if ($user): ?>
<div class="mobile-topbar">
    <button class="mobile-menu-button" id="mobileNavOpen" type="button" aria-label="Navigation öffnen" aria-controls="sidebar" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <a class="mobile-brand" href="app.php"><img class="tf-brand-icon" src="assets/brand/trakfog-icon.svg" alt="" aria-hidden="true"><strong>TrakFog</strong></a>
    <?php if (in_array($user['role'], ['owner','admin'], true)): ?>
        <a class="mobile-engine-state <?= $engineHealthy ? 'ok' : 'warn' ?>" href="system.php" aria-label="System öffnen">
            <span class="status-led <?= $engineHealthy ? 'ok' : 'warn' ?>"></span>
            <span>System</span>
        </a>
    <?php else: ?>
        <span class="mobile-engine-state ok"><span class="status-led ok"></span><span>V<?= e(app_version()) ?></span></span>
    <?php endif; ?>
</div>
<div class="mobile-nav-backdrop" id="mobileNavBackdrop" hidden></div>

<aside class="sidebar" id="sidebar">
    <button class="mobile-nav-close" id="mobileNavClose" type="button" aria-label="Navigation schließen"></button>

    <a class="side-brand" href="app.php">
        <span class="side-brand-wordmark">
            <img class="tf-brand-logo" src="assets/brand/trakfog-logo.svg" alt="TrakFog">
            <small>Tesla Data Platform</small>
        </span>
    </a>

    <nav class="side-nav" aria-label="Hauptnavigation">
        <span class="nav-group-label">Übersicht</span>
        <a class="<?= $active === 'home' ? 'active' : '' ?>" href="app.php"><span class="nav-icon">⌂</span><span>Dashboard</span></a>

        <span class="nav-group-label">Fahrzeug</span>
        <a class="<?= $active === 'drive' ? 'active' : '' ?>" href="drive.php"><span class="nav-icon">🚗</span><span>Fahrzeug</span></a>
        <a href="live.php" target="_blank" rel="noopener"><span class="nav-icon">▣</span><span>LiveView</span><span class="nav-external">↗</span></a>

        <span class="nav-group-label">Historie</span>
        <a class="<?= $active === 'trips' ? 'active' : '' ?>" href="trips.php"><span class="nav-icon">↗</span><span>Fahrten</span></a>
        <a class="<?= $active === 'charges' ? 'active' : '' ?>" href="charges.php"><span class="nav-icon">⚡</span><span>Laden</span></a>
        <a class="<?= $active === 'journeys' ? 'active' : '' ?>" href="journeys.php"><span class="nav-icon">◇</span><span>Reisen</span></a>

        <span class="nav-group-label">Auswertung</span>
        <a class="<?= $active === 'statistics' ? 'active' : '' ?>" href="statistics.php"><span class="nav-icon">▤</span><span>Statistik</span></a>
        <a class="<?= $active === 'sleep' ? 'active' : '' ?>" href="sleep.php"><span class="nav-icon">☾</span><span>Sleep & Drain</span></a>

        <span class="nav-group-label">Orte</span>
        <a class="<?= $active === 'map' ? 'active' : '' ?>" href="map.php"><span class="nav-icon">⌖</span><span>Karte & Orte</span></a>

        <span class="nav-group-label">System</span>
        <a class="<?= $active === 'system' ? 'active' : '' ?>" href="system.php">
            <span class="nav-icon">⚙</span><span>System</span>
            <?php if (in_array($user['role'], ['owner','admin'], true)): ?><span class="nav-live-dot <?= $engineHealthy ? 'ok' : 'warn' ?>"></span><?php endif; ?>
        </a>
    </nav>

    <div class="side-bottom">
        <div class="side-tools">
            <button type="button" class="side-tool" id="themeToggle">◐ <span>Darstellung</span></button>
            <button type="button" class="side-tool" id="infoToggle" aria-haspopup="dialog" aria-controls="infoModal">ⓘ <span>Info</span></button>
        </div>
        <div class="sidefoot">
            <span>V<?= e(app_version()) ?></span>
            <span>private beta</span>
        </div>
    </div>
</aside>

<main class="app-main">
    <?php $sectionLabel = trakfog_section_label($active); ?>
    <header class="app-header">
        <div class="app-header-context">
            <span>Bereich</span>
            <strong><?= e($sectionLabel) ?></strong>
        </div>
        <div class="header-actions">
            <?php if (in_array($user['role'], ['owner','admin'], true)): ?>
                <a class="system-health-link <?= $engineHealthy ? 'ok' : 'warn' ?>" href="system.php" title="Systemstatus">
                    <span class="status-led <?= $engineHealthy ? 'ok' : 'warn' ?>"></span>
                    <span>System</span>
                </a>
            <?php endif; ?>
            <a class="account-chip" href="system.php#account">
                <span class="account-avatar"><?= e(strtoupper(substr((string)$user['username'], 0, 1))) ?></span>
                <span><b><?= e($user['username']) ?></b><small><?= e($user['role']) ?></small></span>
            </a>
            <a class="header-logout" href="logout.php" title="Abmelden" aria-label="Abmelden">↪</a>
        </div>
    </header>
<?php else: ?>
<main class="auth-main">
<?php endif; ?>
<?php
}

function render_footer(): void
{
    $active = (string)($GLOBALS['trakfog_active_page'] ?? '');
    $authenticated = (bool)($GLOBALS['trakfog_view_authenticated'] ?? false);
    $mapAssets = (bool)($GLOBALS['trakfog_map_assets'] ?? false);
    ?>
</main>
<?php if ($authenticated): ?>
<div class="tf-modal trakfog-info-modal" id="infoModal" hidden>
    <div class="tf-modal-backdrop" aria-hidden="true"></div>
    <section class="tf-modal-card trakfog-info-modal-card" role="dialog" aria-modal="true" aria-labelledby="infoModalTitle">
        <div class="trakfog-info-modal-head">
            <div>
                <span class="kicker">TrakFog · System</span>
                <h2 id="infoModalTitle">Systeminformationen</h2>
            </div>
            <button class="tf-modal-close" type="button" data-info-modal-close aria-label="Schließen">×</button>
        </div>
        <div class="trakfog-info-modal-body">
            <?php render_trakfog_info_content(trakfog_section_label($active)); ?>
        </div>
    </section>
</div>
<?php else: ?>
<footer class="auth-footer"><span>TrakFog · V<?= e(app_version()) ?></span><span>Your Tesla. Your data.</span></footer>
<?php endif; ?>
<script src="assets/js/app.js?v=<?= e(app_version()) ?>"></script>
<?php if ($active === 'home'): ?><script src="assets/js/living-garage.js?v=<?= e(app_version()) ?>"></script><?php endif; ?>
<?php if ($active === 'trips'): ?><script src="assets/js/trip-merge-ui.js?v=<?= e(app_version()) ?>"></script><script src="assets/js/trip-delete.js?v=<?= e(app_version()) ?>"></script><?php endif; ?>
</body>
</html>
<?php
}
