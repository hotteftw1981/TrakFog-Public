<?php

declare(strict_types=1);

final class ErrorPage
{
    public static function render(int $status, ?string $reference = null, ?string $detail = null): never
    {
        $allowed = [400, 401, 403, 404, 405, 409, 422, 429, 500, 503];
        $status = in_array($status, $allowed, true) ? $status : 500;
        http_response_code($status);

        $copy = match ($status) {
            400 => ['Ungültige Anfrage.', 'TrakFog konnte diese Anfrage nicht verarbeiten.'],
            401 => ['Anmeldung erforderlich.', 'Bitte melde dich an, um diesen Bereich zu öffnen.'],
            403 => ['Zugriff verweigert.', 'Für diesen Bereich fehlen dir die nötigen Rechte.'],
            404 => ['Seite nicht gefunden.', 'Die angeforderte Seite existiert nicht oder wurde verschoben.'],
            405 => ['Aktion nicht erlaubt.', 'Diese Aktion ist über diesen Weg nicht verfügbar.'],
            409 => ['Da passt etwas nicht zusammen.', 'Die Aktion kollidiert mit dem aktuellen Datenstand.'],
            422 => ['Eingabe nicht verwendbar.', 'Einige Angaben konnten nicht verarbeitet werden.'],
            429 => ['Zu viele Anfragen.', 'Bitte versuche es in einem Moment erneut.'],
            503 => ['Dienst gerade nicht verfügbar.', 'TrakFog ist vorübergehend nicht erreichbar.'],
            default => ['Interner Fehler.', 'TrakFog hat einen unerwarteten Fehler abgefangen.'],
        };

        $versionFile = dirname(__DIR__) . '/VERSION';
        $version = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : 'dev';
        $safeTitle = htmlspecialchars($copy[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($detail ?: $copy[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeRef = $reference ? htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
        $safeVersion = htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $eyebrow = $status >= 500 ? 'Systemfehler' : 'Hinweis';
        $safeEyebrow = htmlspecialchars($eyebrow, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<!doctype html><html lang="de" data-theme="dark"><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
        echo '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#06111f">';
        echo '<meta name="application-name" content="TrakFog">';
        echo '<title>' . $status . ' · TrakFog</title>';
        echo '<link rel="icon" type="image/svg+xml" href="assets/brand/trakfog-icon.svg">';
        echo '<link rel="shortcut icon" href="assets/brand/trakfog-icon.svg">';
        echo '<link rel="manifest" href="site.webmanifest">';
        echo '<script>(()=>{try{const t=localStorage.getItem("trakfog-theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}})();</script>';
        echo '<style>
:root{--brand:#159cff;--brand-soft:rgba(21,156,255,.10);--brand-border:rgba(21,156,255,.30);--bg:#f4f5f7;--surface:#fff;--surface-soft:#f8fafb;--text:#18202a;--text-2:#34404d;--muted:#74808d;--line:#e1e5e9;--line-soft:#eef0f2;--shadow:0 3px 12px rgba(20,30,40,.06)}
html[data-theme="dark"]{color-scheme:dark;--bg:#11151a;--surface:#1a2027;--surface-soft:#202730;--text:#eef2f5;--text-2:#d9e0e6;--muted:#9aa6b2;--line:#303943;--line-soft:#29313a;--brand-soft:rgba(21,156,255,.14);--brand-border:rgba(21,156,255,.42);--shadow:0 3px 14px rgba(0,0,0,.24)}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}body{min-height:100vh;display:grid;place-items:center;padding:28px}
.error-shell{width:min(760px,100%)}.error-brand{display:grid;gap:4px;margin:0 0 18px 2px}.error-brand-logo{width:190px;max-width:72vw;height:auto;display:block}.brand-copy span{display:block;color:var(--muted);font-size:9px;font-weight:650}
.error-card{overflow:hidden;border:1px solid var(--line);border-radius:14px;background:var(--surface);box-shadow:var(--shadow)}.error-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid var(--line-soft)}.error-kicker{color:var(--brand);font-size:9px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.version{padding:6px 9px;border:1px solid var(--line);border-radius:999px;background:var(--surface-soft);color:var(--muted);font-size:10px;font-weight:800}.error-body{padding:28px}.error-code{margin:0;color:var(--brand);font-size:64px;line-height:.9;font-weight:950;letter-spacing:-.065em}.error-body h1{margin:12px 0 7px;font-size:31px;line-height:1.1;letter-spacing:-.04em}.error-body p{max-width:610px;margin:0;color:var(--muted);font-size:12px;line-height:1.6}
.error-ref{display:grid;gap:4px;margin-top:20px;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:var(--surface-soft)}.error-ref span{color:var(--muted);font-size:8px;font-weight:850;letter-spacing:.06em;text-transform:uppercase}.error-ref code{overflow-wrap:anywhere;color:var(--text-2);font:11px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:22px}.btn{min-height:38px;display:inline-flex;align-items:center;justify-content:center;padding:9px 13px;border-radius:9px;text-decoration:none;font-size:11px;font-weight:850;border:1px solid transparent}.btn-primary{background:linear-gradient(135deg,#2169ff,var(--brand));color:#fff;box-shadow:0 8px 22px rgba(21,156,255,.16)}.btn-ghost{border-color:var(--line);background:var(--surface-soft);color:var(--text)}.error-foot{display:flex;justify-content:space-between;gap:12px;margin-top:10px;padding:0 2px;color:var(--muted);font-size:8px;font-weight:700}
@media(max-width:560px){body{padding:16px}.error-body{padding:22px 18px}.error-code{font-size:52px}.error-body h1{font-size:26px}.actions{flex-direction:column}.btn{width:100%}.error-foot{flex-direction:column;gap:3px}}
</style></head><body>';
        echo '<main class="error-shell">';
        echo '<div class="error-brand"><img class="error-brand-logo" src="assets/brand/trakfog-logo.svg" alt="TrakFog"><span class="brand-copy"><span>Tesla Data Platform</span></span></div>';
        echo '<section class="error-card"><div class="error-head"><span class="error-kicker">' . $safeEyebrow . '</span><span class="version">V' . $safeVersion . '</span></div>';
        echo '<div class="error-body"><div class="error-code">' . $status . '</div><h1>' . $safeTitle . '</h1><p>' . $safeText . '</p>';
        if ($safeRef) {
            echo '<div class="error-ref"><span>Fehler-ID</span><code>' . $safeRef . '</code></div>';
        }
        echo '<div class="actions"><a class="btn btn-primary" href="app.php">Zur Übersicht</a><a class="btn btn-ghost" href="javascript:history.back()">Zurück</a>';
        if ($status >= 500) {
            echo '<a class="btn btn-ghost" href="diagnostics.php">Systemdiagnose</a>';
        }
        echo '</div></div></section>';
        echo '<div class="error-foot"><span>TrakFog · private beta</span><span>Your Tesla. Your data.</span></div>';
        echo '</main></body></html>';
        exit;
    }
}
