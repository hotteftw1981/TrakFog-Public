<?php
require dirname(__DIR__) . '/src/bootstrap.php';

$mode = LiveViewAuth::mode($pdo);
$pinConfigured = LiveViewAuth::pinConfigured($pdo);
$error = null;
$blockedFor = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen. Bitte Seite neu laden.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'pin_login') {
            $result = LiveViewAuth::attemptPin(
                $pdo,
                (string)($_POST['pin'] ?? ''),
                isset($_POST['remember_device']),
                (string)($_POST['device_hint'] ?? '')
            );
            if (!empty($result['ok'])) {
                header('Location: live.php');
                exit;
            }
            $error = (string)($result['message'] ?? 'PIN konnte nicht geprüft werden.');
            $blockedFor = (int)($result['blocked_for'] ?? 0);
        } elseif ($action === 'live_logout') {
            LiveViewAuth::logout($pdo, true);
            header('Location: live.php');
            exit;
        }
    }
}

$authorized = LiveViewAuth::authorized($pdo);
$accessKind = $authorized ? LiveViewAuth::accessKind($pdo) : 'none';
$version = app_version();
?><!doctype html>
<html lang="de" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=no">
  <meta name="theme-color" content="#090b0e">
  <meta name="robots" content="noindex,nofollow">
  <meta name="application-name" content="TrakFog Live">
  <meta name="apple-mobile-web-app-title" content="TrakFog Live">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <title>TrakFog Live</title>
  <link rel="icon" type="image/svg+xml" href="assets/brand/trakfog-icon.svg">
  <link rel="shortcut icon" href="assets/brand/trakfog-icon.svg">
  <link rel="apple-touch-icon" href="assets/brand/trakfog-icon.svg">
  <link rel="manifest" href="site.webmanifest?v=<?= e($version) ?>">
  <link rel="stylesheet" href="assets/css/liveview.css?v=<?= e($version) ?>">
  <?php if ($authorized): ?><link rel="stylesheet" href="assets/css/liveview-garage.css?v=<?= e($version) ?>"><?php endif; ?>
  <?php if ($authorized): ?>
    <link rel="preconnect" href="https://unpkg.com">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <?php endif; ?>
</head>
<body class="<?= $authorized ? 'liveview-body' : 'liveview-auth-body' ?>">
<?php if (!$authorized): ?>
  <main class="live-auth-shell">
    <section class="live-auth-card">
      <div class="live-auth-brand">
        <img class="live-brand-logo" src="assets/brand/trakfog-logo.svg" alt="TrakFog">
        <div><strong>LiveView</strong><small>Your Tesla. Your data.</small></div>
      </div>

      <?php if ($mode === 'disabled'): ?>
        <div class="live-auth-state-icon">◌</div>
        <h1>LiveView ist deaktiviert.</h1>
        <p>Aktiviere den LiveView einmal im normalen TrakFog-Backend unter <b>Einstellungen → LiveView</b>.</p>
      <?php elseif ($mode === 'pin' && !$pinConfigured): ?>
        <div class="live-auth-state-icon">🔐</div>
        <h1>PIN noch nicht eingerichtet.</h1>
        <p>Lege zuerst im Backend eine sechsstellige LiveView-PIN fest.</p>
      <?php else: ?>
        <span class="live-auth-kicker">Geschützter LiveView</span>
        <h1>PIN eingeben.</h1>
        <p>Sechs Ziffern – danach kann sich dieser Browser auf Wunsch selbst merken.</p>

        <?php if ($error): ?>
          <div class="live-auth-error"><?= e($error) ?><?= $blockedFor > 0 ? ' · ca. ' . max(1,(int)ceil($blockedFor/60)) . ' Min.' : '' ?></div>
        <?php endif; ?>

        <form method="post" class="live-pin-form" id="livePinForm" autocomplete="off">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="pin_login">
          <input type="hidden" name="pin" id="livePinInput" value="">
          <input type="hidden" name="device_hint" id="liveDeviceHint" value="LiveView Browser">

          <div class="live-pin-dots" id="livePinDots" aria-label="PIN">
            <?php for ($i=0; $i<6; $i++): ?><span></span><?php endfor; ?>
          </div>

          <div class="live-pin-pad" aria-label="Ziffernblock">
            <?php foreach ([1,2,3,4,5,6,7,8,9] as $digit): ?>
              <button type="button" data-pin-digit="<?= $digit ?>"><?= $digit ?></button>
            <?php endforeach; ?>
            <button type="button" class="pin-pad-action" data-pin-clear>C</button>
            <button type="button" data-pin-digit="0">0</button>
            <button type="button" class="pin-pad-action" data-pin-backspace>⌫</button>
          </div>

          <label class="live-remember">
            <input type="checkbox" name="remember_device" value="1" checked>
            <span>Dieses Gerät merken</span>
            <small><?= max(1,min(365,(int)setting($pdo,'liveview_remember_days','90'))) ?> Tage</small>
          </label>

          <button class="live-pin-submit" id="livePinSubmit" type="submit" disabled>LiveView öffnen</button>
        </form>
      <?php endif; ?>

      <div class="live-auth-foot">TrakFog · V<?= e($version) ?></div>
    </section>
  </main>
<?php else: ?>
  <div class="live-root lv-vehicle-visualization" id="liveRoot"
       data-api="live-data.php"
       data-access="<?= e($accessKind) ?>"
       data-version="<?= e($version) ?>">
    <header class="lv-topbar">
      <div class="lv-brand">
        <img class="live-brand-icon" src="assets/brand/trakfog-icon.svg" alt="" aria-hidden="true">
        <div><strong>TrakFog Live</strong><small id="lvTopState">verbinde …</small></div>
      </div>

      <div class="lv-top-center">
        <select id="lvVehicleSelect" aria-label="Fahrzeug auswählen"></select>
      </div>

      <div class="lv-top-actions">
        <?php if ($accessKind === 'account' && $mode === 'disabled'): ?><span class="lv-chip warn">Vorschau</span><?php endif; ?>
        <button class="lv-icon-btn lv-trip-merge-action" type="button" id="lvTripMergeToggle" hidden>🔗 Letzte Fahrt fortsetzen?</button>
        <?php if (Auth::check()): ?><input type="hidden" id="lvTripMergeCsrf" value="<?= e(Csrf::token()) ?>"><?php endif; ?>
        <span class="lv-chip" id="lvClock">--:--</span>
        <button class="lv-icon-btn" type="button" id="lvInfoToggle" aria-label="LiveView-Informationen">•••</button>
      </div>
    </header>

    <dialog class="lv-trip-merge-modal" id="lvTripMergeDialog" aria-labelledby="lvTripMergeTitle">
      <h3 id="lvTripMergeTitle">Letzte Fahrt fortsetzen?</h3>
      <p id="lvTripMergeDetail">Setze die rote Linie in der LiveView fort. Nach Fahrtende kannst du beide Fahrten in der Fahrtenliste zu einer einzigen zusammenf&uuml;hren.</p>
      <p class="lv-merge-feedback" id="lvTripMergeError" role="alert" hidden></p>
      <div class="lv-merge-modal-actions"><button type="button" id="lvTripMergeCancel">Abbrechen</button><button type="button" id="lvTripMergeConfirm">🔗 Rote Route verbinden</button></div>
    </dialog>
    <main class="lv-stage" id="lvStage">
      <div class="lv-track" id="lvTrack">
        <section class="lv-page lv-page-main" data-page="0" aria-label="Fahrzeug">
          <svg class="lv-scene-icons" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" width="0" height="0" focusable="false">
            <symbol id="lv-icon-moon" viewBox="0 0 24 24"><path d="M20.8 13A9 9 0 0 1 11 3.2a9 9 0 1 0 9.8 9.8Z"/></symbol>
            <symbol id="lv-icon-bolt" viewBox="0 0 24 24"><path d="m13 2-9 11h7l-1 9 10-12h-7V2Z"/></symbol>
            <symbol id="lv-icon-road" viewBox="0 0 24 24"><path d="M7 2 3 22m14-20 4 20M12 2v4m0 4v4m0 4v4"/></symbol>
            <symbol id="lv-icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>
            <symbol id="lv-icon-leaf" viewBox="0 0 24 24"><path d="M21 3C11 3 5 7 5 15c0 3 2 5 5 5 8 0 11-7 11-17ZM3 21c3-5 7-8 13-11"/></symbol>
            <symbol id="lv-icon-gauge" viewBox="0 0 24 24"><path d="M5 18a9 9 0 1 1 14 0M12 13l4-4"/><circle cx="12" cy="13" r="1.3"/></symbol>
            <symbol id="lv-icon-car" viewBox="0 0 24 24"><path d="m5 11 2-5h10l2 5 2 2v6h-2v-2H5v2H3v-6l2-2Zm0 0h14M7.5 14.5h.01M16.5 14.5h.01"/></symbol>
          </svg>
          <div class="lv-main-grid">
            <article class="lv-hero-card lv-drive-card">
              <div class="lv-card-head">
                <span id="lvHeroLabel">Fahrzeug</span>
                <span class="lv-live-dot" id="lvLiveDot"></span>
              </div>

              <div class="lv-car-scene" id="lvCarScene" data-mode="parked" data-model="unknown" data-motion="subtle" aria-label="Fahrzeugvisualisierung">
                <div class="lv-car-scene-floor" aria-hidden="true"></div>
                <div class="lv-car-scene-horizon" aria-hidden="true"></div>
                <div class="lv-car-scene-glow" aria-hidden="true"></div>
                <div class="lv-car-scene-road" aria-hidden="true"></div>
                <div class="lv-car-scene-energy" aria-hidden="true"></div>
                <div class="lv-car-sprite" aria-hidden="true">
                  <span class="lv-car-scene-contact"></span>
                  <img class="lv-car-scene-image" id="lvCarSceneImage" src="assets/vehicles/model-y-white.svg" alt="" draggable="false" decoding="async" hidden>
                </div>
                <span class="lv-car-scene-fallback" id="lvCarSceneFallback" hidden>TESLA</span>
                <div class="lv-car-scene-identity">
                  <strong id="lvCarSceneName">Fahrzeug</strong>
                  <span id="lvCarSceneModel">Tesla</span>
                </div>
                <div class="lv-car-scene-state" id="lvCarSceneState">● Verbinde …</div>
              </div>
              <div class="lv-speed-gauge" id="lvSpeedGauge" data-mode="parked">
                <div class="lv-speed-gauge-inner">
                  <div class="lv-hero-value">
                    <svg class="lv-gauge-sleep-symbol" aria-hidden="true"><use href="#lv-icon-moon"></use></svg>
                    <strong id="lvHeroValue">–</strong>
                    <span id="lvHeroUnit">km/h</span>
                  </div>
                  <div class="lv-drive-gear"><span>Gang</span><b id="lvDriveGear">–</b></div>
                  <div class="lv-speed-scale"><span>0</span><span>100</span><span>200</span></div>
                </div>
              </div>

              <div class="lv-drive-glance-row">
                <div class="lv-drive-glance">
                  <div class="lv-dashboard-ring lv-drive-ring lv-battery-ring" id="lvBatteryRing">
                    <div><strong id="lvBattery">–</strong><span>%</span><small>AKKU</small></div>
                  </div>
                  <div class="lv-drive-glance-meta"><span>Reichweite</span><b id="lvRange">– km</b></div>
                </div>

                <div class="lv-drive-glance">
                  <div class="lv-dashboard-ring lv-drive-ring lv-power-ring" id="lvPowerRing">
                    <svg class="lv-power-symbol" aria-hidden="true"><use href="#lv-icon-bolt"></use></svg>
                    <div class="lv-power-ring-readout"><strong id="lvPowerValue">–</strong><span>kW</span><small>LEISTUNG</small></div>
                  </div>
                  <div class="lv-drive-glance-meta"><span>Status</span><b id="lvPowerDirection">–</b></div>
                </div>
              </div>

              <div class="lv-hero-sub" id="lvHeroSub">Warte auf Fahrzeugdaten …</div>
            </article>

            <article class="lv-location-card">
              <div class="lv-card-head"><span>Standort</span><span id="lvDataAge">–</span></div>
              <strong id="lvLocation">–</strong>
              <small id="lvVehicleState">–</small>
            </article>

            <article class="lv-session-card">
              <div class="lv-card-head"><span id="lvSessionLabel">Aktuelle Fahrt</span><span id="lvSessionStatus">–</span></div>
              <div class="lv-session-idle" id="lvSessionEmpty" hidden aria-live="polite">
                <svg class="lv-idle-car-icon" aria-hidden="true"><use href="#lv-icon-car"></use></svg>
                <strong>Bereit für die nächste Fahrt</strong>
                <small>Neue Fahrt- oder Ladedaten erscheinen hier automatisch.</small>
              </div>
              <div class="lv-session-metrics">
                <div class="lv-session-stat">
                  <svg class="lv-stat-icon" aria-hidden="true"><use href="#lv-icon-road"></use></svg>
                  <div class="lv-session-stat-copy"><span id="lvTripDistanceLabel">Strecke</span><b id="lvTripDistance">–</b><small class="lv-session-unit">km</small></div>
                </div>
                <div class="lv-session-stat">
                  <svg class="lv-stat-icon" aria-hidden="true"><use href="#lv-icon-clock"></use></svg>
                  <div class="lv-session-stat-copy"><span id="lvTripDurationLabel">Dauer</span><b id="lvTripDuration">–</b><small class="lv-session-unit">min</small></div>
                </div>
                <div class="lv-session-stat">
                  <svg class="lv-stat-icon" aria-hidden="true"><use href="#lv-icon-leaf"></use></svg>
                  <div class="lv-session-stat-copy"><span id="lvTripConsumptionLabel">Verbrauch</span><b id="lvTripConsumption">–</b><small class="lv-session-unit">Wh/km</small></div>
                </div>
                <div class="lv-session-stat">
                  <svg class="lv-stat-icon" aria-hidden="true"><use href="#lv-icon-gauge"></use></svg>
                  <div class="lv-session-stat-copy"><span id="lvTripMaxLabel">Max.</span><b id="lvTripMax">–</b><small class="lv-session-unit">km/h</small></div>
                </div>
              </div>
            </article>

            <article class="lv-journey-strip">
              <div>
                <span class="lv-journey-icon">🧳</span>
                <div><small>Aktive Reise</small><strong id="lvJourneyName">Keine aktive Reise</strong></div>
              </div>
              <div class="lv-journey-strip-meta">
                <span id="lvJourneyDestination">–</span>
                <b id="lvJourneyDistance">–</b>
              </div>
            </article>
          </div>
        </section>

        <section class="lv-page lv-page-map" data-page="1" aria-label="Karte">
          <div class="lv-map-toolbar">
            <div class="lv-map-title">
              <span class="kicker">Live Map</span>
              <strong id="lvMapVehicle">Tesla</strong>
            </div>
            <div class="lv-map-controls">
              <span class="lv-map-chip active" id="lvOwnTeslaLabel">🚗 Mein Tesla</span>
              <button class="lv-map-chip active" type="button" id="lvRouteToggle">↗ Route</button>
              <button class="lv-map-chip" type="button" id="lvMapThemeToggle" aria-label="Kartenstil wechseln">🌙 Dunkel</button>
              <button class="lv-map-location-toggle future is-on" type="button" data-future-feature="location" aria-label="Standortfreigabe – vorbereitet"><span>⌖</span><b>AN</b><small>Standort</small></button>
              <button class="lv-map-chip future" type="button" data-future-feature="teslas">👥 Andere Teslas</button>
              <button class="lv-map-chip future" type="button" data-future-feature="places">⭐ Community-Orte</button>
            </div>
          </div>
          <div id="lvMap" class="lv-map"></div>
          <aside class="lv-map-hud">
            <div class="lv-map-hud-ring lv-dashboard-ring" id="lvMapBatteryRing">
              <div><strong id="lvMapBattery">–</strong><span>%</span><small>AKKU</small></div>
            </div>
            <div class="lv-map-hud-data">
              <div><span>Tempo</span><strong id="lvMapSpeed">– km/h</strong></div>
              <div><span>Reichweite</span><strong id="lvMapRange">– km</strong></div>
              <div><span>Aktuelle Fahrt</span><strong id="lvMapTrip">–</strong></div>
              <div class="lv-map-hud-location"><span>Position</span><strong id="lvMapLocation">–</strong></div>
            </div>
          </aside>
          <button class="lv-map-follow active" type="button" id="lvMapFollow">◎ Folgen</button>
        </section>

        <section class="lv-page lv-page-journey" data-page="2" aria-label="Fahrt und Reise">
          <div class="lv-journey-page-grid">
            <article class="lv-journey-hero">
              <div class="lv-card-head"><span>🧳 Reise</span><span id="lvJourneyDuration">–</span></div>
              <h2 id="lvJourneyPageTitle">Keine aktive Reise</h2>
              <p id="lvJourneyPageDestination">Starte oder plane eine Reise im TrakFog-Backend.</p>

              <div class="lv-journey-empty-scene" id="lvJourneyEmptyScene">
                <div class="lv-empty-route" aria-hidden="true">
                  <span class="start"></span>
                  <i></i>
                  <b>🚗</b>
                  <span class="finish">⌁</span>
                </div>
                <div class="lv-empty-route-copy">
                  <strong>Bereit für die nächste Tour.</strong>
                  <span id="lvJourneyReadyMeta">Tesla wartet auf die nächste Reise.</span>
                </div>
              </div>

              <div class="lv-journey-visual">
                <div class="lv-dashboard-ring lv-journey-ring" id="lvJourneyRing">
                  <div><strong id="lvJourneyRingValue">–</strong><span>%</span><small>AKKU</small></div>
                </div>
                <div class="lv-journey-activity">
                  <div class="lv-journey-activity-head"><span>Reiseaktivität</span><b id="lvJourneyActivityText">–</b></div>
                  <div class="lv-journey-timebar">
                    <i class="drive" id="lvJourneyDriveBar"></i>
                    <i class="charge" id="lvJourneyChargeBar"></i>
                  </div>
                  <div class="lv-journey-legend">
                    <span><i class="drive"></i>Fahren <b id="lvJourneyDriveTime">–</b></span>
                    <span><i class="charge"></i>Laden <b id="lvJourneyChargeTime">–</b></span>
                  </div>
                </div>
              </div>

              <div class="lv-journey-big-metrics">
                <div><strong id="lvJourneyTotalTime">–</strong><span>Gesamtzeit</span></div>
                <div><strong id="lvJourneyPageDistance">–</strong><span>Gesamtstrecke</span></div>
                <div><strong id="lvJourneyChargedEnergy">–</strong><span>Gesamt geladen</span></div>
                <div><strong id="lvJourneyCost">–</strong><span>Ladekosten</span></div>
              </div>
              <div class="lv-budget" id="lvJourneyBudgetWrap" hidden>
                <div><span>Ladebudget</span><b id="lvJourneyBudgetText">–</b></div>
                <div><i id="lvJourneyBudgetBar"></i></div>
              </div>
            </article>

            <article class="lv-now-card">
              <div class="lv-card-head"><span>Jetzt</span><span id="lvNowMode">–</span></div>
              <div class="lv-now-grid">
                <div><span>Fahrt</span><b id="lvNowTripDistance">–</b><small id="lvNowTripDuration">–</small></div>
                <div><span>Leistung</span><b id="lvNowPower">–</b><small id="lvNowSpeed">–</small></div>
                <div><span>Akku</span><b id="lvNowBattery">–</b><small id="lvNowRange">–</small></div>
                <div><span>Laden</span><b id="lvNowCharge">–</b><small id="lvNowChargeTime">–</small></div>
              </div>
            </article>

            <article class="lv-charge-card">
              <div class="lv-card-head"><span>⚡ Ladesession</span><span id="lvChargeState">–</span></div>
              <strong id="lvChargeLocation">Keine aktive Ladung</strong>
              <div class="lv-charge-metrics">
                <div><span>Leistung</span><b id="lvChargePower">–</b></div>
                <div><span>Geladen</span><b id="lvChargeEnergy">–</b></div>
                <div><span>Dauer</span><b id="lvChargeDuration">–</b></div>
                <div><span>Kosten</span><b id="lvChargeCost">–</b></div>
              </div>
            </article>
          </div>
        </section>

        <section class="lv-page lv-page-nerd" data-page="3" aria-label="NerdView">
          <div class="lv-tesla-nerd-grid">
            <article class="lv-tesla-hero">
              <div class="lv-tesla-hero-head">
                <div>
                  <span class="kicker">NerdView · Stand</span>
                  <strong>TESLA // TECH</strong>
                </div>
                <span id="lvNerdDataAge">–</span>
              </div>

              <div class="lv-tesla-speed" id="lvNerdSpeedGauge">
                <span id="lvNerdMode">–</span>
                <strong id="lvNerdSpeed">–</strong>
                <small id="lvNerdSpeedUnit">km/h</small>
              </div>

              <div class="lv-tesla-hero-metrics">
                <div><span>Leistung</span><b id="lvNerdPower">–</b><small>kW</small></div>
                <div><span>Gang</span><b id="lvNerdGear">–</b><small>shift</small></div>
              </div>
            </article>

            <article class="lv-tesla-card lv-tesla-battery">
              <div class="lv-tesla-card-head">
                <div><span class="kicker">⚡ Akku & Laden</span><strong id="lvNerdChargingState">–</strong></div>
                <span id="lvNerdUsableBattery">–</span>
              </div>

              <div class="lv-dashboard-ring lv-nerd-battery-ring" id="lvNerdBatteryRing">
                <div><strong id="lvNerdSoc">–</strong><span>%</span><small>STATE OF CHARGE</small></div>
              </div>

              <div class="lv-tesla-metric-grid">
                <div><span>Rated</span><b id="lvNerdRatedRange">–</b><small>km</small></div>
                <div><span>Estimated</span><b id="lvNerdEstimatedRange">–</b><small>km</small></div>
                <div><span>Ladeleistung</span><b id="lvNerdChargerPower">–</b><small>kW</small></div>
                <div><span>Volt / Ampere</span><b id="lvNerdChargeElectrical">–</b><small>V / A</small></div>
              </div>

              <div class="lv-tesla-charge-foot">
                <span id="lvNerdChargeAdded">– kWh geladen</span>
                <span id="lvNerdChargeTime">– bis voll</span>
              </div>
            </article>

            <article class="lv-tesla-card lv-tesla-vehicle">
              <div class="lv-tesla-card-head">
                <div><span class="kicker">🚗 Fahrzeug</span><strong id="lvNerdVehicleModel">Tesla</strong></div>
                <span id="lvNerdSoftware">–</span>
              </div>

              <div class="lv-tesla-odometer">
                <span>Kilometerstand</span>
                <strong id="lvNerdOdometer">–</strong>
                <small>km</small>
              </div>
              <div class="lv-nerd-update" id="lvNerdUpdate" hidden>
                <span>⬆</span>
                <div><small>Software-Update</small><strong id="lvNerdUpdateText">–</strong></div>
              </div>

              <div class="lv-tesla-state-grid lv-tesla-state-grid-compact">
                <div class="lv-tesla-status-wide"><span>Türen & Klappen</span><b id="lvNerdPanels">–</b></div>
                <div><span>Ladeport</span><b id="lvNerdChargePort">–</b></div>
              </div>

              <small class="lv-tesla-vin" id="lvNerdVin">VIN ••••••</small>
            </article>

            <article class="lv-tesla-card lv-tesla-climate">
              <div class="lv-tesla-card-head">
                <div><span class="kicker">🌡 Klima</span><strong id="lvNerdClimateState">–</strong></div>
                <span id="lvNerdFan">–</span>
              </div>

              <div class="lv-tesla-temp-pair">
                <div><span>Innen</span><strong id="lvNerdInsideTemp">–</strong><small>°C</small></div>
                <div><span>Außen</span><strong id="lvNerdOutsideTemp">–</strong><small>°C</small></div>
              </div>

              <div class="lv-tesla-climate-set">
                <div><span>Fahrer</span><b id="lvNerdDriverTemp">–</b></div>
                <div><span>Beifahrer</span><b id="lvNerdPassengerTemp">–</b></div>
              </div>
            </article>

            <article class="lv-tesla-card lv-tesla-position">
              <div class="lv-tesla-card-head">
                <div><span class="kicker">⌖ Position</span><strong id="lvNerdPlace">–</strong></div>
                <span id="lvNerdPositionFresh">Adresse</span>
              </div>

              <div class="lv-tesla-address">
                <span>Adresse</span>
                <strong id="lvNerdAddress">wird ermittelt …</strong>
                <small id="lvNerdCity">–</small>
              </div>

              <div class="lv-position-nav">
                <div class="lv-position-stat">
                  <span>Fahrtrichtung</span>
                  <b id="lvNerdDirection">–</b>
                </div>

                <div class="lv-compass-wrap">
                  <div class="lv-compass" id="lvNerdCompass">
                    <span class="n">N</span><span class="e">O</span><span class="s">S</span><span class="w">W</span>
                    <i id="lvNerdCompassNeedle"></i>
                    <b id="lvNerdCompassDegrees">–°</b>
                  </div>
                </div>

                <div class="lv-position-stat">
                  <span>Höhe</span>
                  <b id="lvNerdElevation">– m</b>
                </div>
              </div>
            </article>
          </div>
        </section>
      </div>
    </main>

    <nav class="lv-swipe-zone" id="lvSwipeZone" aria-label="LiveView-Seiten">
      <button type="button" class="lv-swipe-arrow" data-page-prev aria-label="Vorherige Seite">‹</button>
      <div class="lv-page-indicator">
        <span class="lv-swipe-label">SWIPE</span>
        <div class="lv-dots">
          <button class="active" type="button" data-page-dot="0" aria-label="Fahrzeug"></button>
          <button type="button" data-page-dot="1" aria-label="Karte"></button>
          <button type="button" data-page-dot="2" aria-label="Fahrt und Reise"></button>
          <button type="button" data-page-dot="3" aria-label="NerdView"></button>
        </div>
        <span class="lv-page-name" id="lvPageName">Fahrzeug</span>
      </div>
      <button type="button" class="lv-swipe-arrow" data-page-next aria-label="Nächste Seite">›</button>
    </nav>

    <aside class="lv-info-drawer" id="lvInfoDrawer" hidden>
      <div class="lv-info-head">
        <div><span class="kicker">LiveView</span><strong>Display & Zugang</strong></div>
        <button type="button" id="lvInfoClose" aria-label="Schließen">×</button>
      </div>
      <div class="lv-info-grid">
        <div><span>Viewport</span><b id="lvDiagViewport">–</b></div>
        <div><span>Screen</span><b id="lvDiagScreen">–</b></div>
        <div><span>Pixel Ratio</span><b id="lvDiagDpr">–</b></div>
        <div><span>Layout</span><b id="lvDiagLayout">–</b></div>
        <div><span>Zugang</span><b><?= e($accessKind) ?></b></div>
        <div><span>Version</span><b>V<?= e($version) ?></b></div>
      </div>
      <div class="lv-info-garage-setting">
        <span>Fahrzeug-Effekte</span>
        <button type="button" id="lvCarMotionToggle" aria-pressed="true">Dezent · AN</button>
      </div>
      <?php if (in_array($accessKind,['pin','device'],true)): ?>
        <form method="post" class="lv-info-logout">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="live_logout">
          <button type="submit">Dieses LiveView-Gerät abmelden</button>
        </form>
      <?php endif; ?>
      <small>LiveView liest ausschließlich bereits von TrakFog gespeicherte Daten. Das Offenlassen dieser Seite weckt den Tesla nicht zusätzlich auf.</small>
    </aside>

    <div class="lv-toast" id="lvToast" hidden></div>
  </div>
<?php endif; ?>

<script src="assets/js/liveview.js?v=<?= e($version) ?>"></script>
</body>
</html>