<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireLogin();

$user = Auth::user($pdo);
$canManage = in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
if (!$canManage) {
    http_response_code(403);
    throw new RuntimeException('Datenmigration ist nur für Owner und Administratoren verfügbar.');
}

$providers = DataMigrationService::providers();
$message = $error = null;

$redirect = static function (string $query = ''): never {
    header('Location: migration.php' . ($query !== '' ? '?' . $query : ''));
    exit;
};

if (isset($_GET['download'])) {
    $job = DataMigrationService::job($pdo, max(0, (int)$_GET['download']));
    if (!$job || !DataMigrationService::canDownload($job)) {
        http_response_code(404);
        exit('Export nicht gefunden.');
    }
    $path = (string)$job['result_path'];
    $name = basename($path);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if (isset($_GET['status']) && isset($_GET['json'])) {
    $job = DataMigrationService::job($pdo, max(0, (int)$_GET['status']));
    if (!$job) {
        json_response(['ok'=>false,'error'=>'Job nicht gefunden.'], 404);
    }
    json_response([
        'ok' => true,
        'job' => [
            'id' => (int)$job['id'],
            'direction' => (string)$job['direction'],
            'source' => (string)$job['source'],
            'status' => (string)$job['status'],
            'progress_percent' => (float)$job['progress_percent'],
            'progress_message' => (string)($job['progress_message'] ?? ''),
            'error_message' => (string)($job['error_message'] ?? ''),
            'result' => $job['result'],
            'download' => DataMigrationService::canDownload($job)
                ? 'migration.php?download=' . (int)$job['id']
                : null,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen. Bitte erneut versuchen.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'upload_import') {
                $job = DataMigrationService::createImportJob($pdo, $_FILES['import_file'] ?? [], (int)Auth::id());
                $redirect('uploaded=' . (int)$job['id']);
            }

            if ($action === 'start_import') {
                $jobId = max(0, (int)($_POST['job_id'] ?? 0));
                DataMigrationService::startImport($pdo, $jobId);
                $redirect('started=' . $jobId);
            }

            if ($action === 'create_export') {
                $job = DataMigrationService::createExportJob($pdo, (int)Auth::id());
                $redirect('export=' . (int)$job['id']);
            }

            if ($action === 'delete_job') {
                $jobId = max(0, (int)($_POST['job_id'] ?? 0));
                DataMigrationService::deleteJob($pdo, $jobId);
                $redirect('deleted=1');
            }
        } catch (Throwable $e) {
            $error = report_exception($e, 'Die Migration konnte nicht vorbereitet werden.');
        }
    }
}

if (isset($_GET['uploaded'])) {
    $message = 'Datei analysiert. Bitte die erkannte Quelle prüfen und den Import anschließend starten.';
} elseif (isset($_GET['started'])) {
    $message = 'Import gestartet. Der Fortschritt wird automatisch aktualisiert.';
} elseif (isset($_GET['export'])) {
    $message = 'TrakFog-Datenexport gestartet. Du kannst die Seite geöffnet lassen oder später zurückkommen.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Migrationsauftrag gelöscht.';
}

$jobs = DataMigrationService::jobs($pdo, 40);
$focusId = max(
    0,
    (int)($_GET['uploaded'] ?? 0),
    (int)($_GET['started'] ?? 0),
    (int)($_GET['export'] ?? 0)
);
$focusJob = $focusId > 0 ? DataMigrationService::job($pdo, $focusId) : null;

$formatBytes = static function (?int $bytes): string {
    if (!$bytes) return '–';
    $units = ['B','KB','MB','GB','TB'];
    $value = (float)$bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units)-1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 1, ',', '.') . ' ' . $units[$i];
};

render_header('Datenmigration', 'system');
?>
<div class="page-wrap migration-page">
  <section class="page-title-row migration-title-row">
    <div>
      <span class="kicker">System · Datenmigration</span>
      <h1>Daten mitnehmen. Nicht neu anfangen.</h1>
      <p>Historie aus anderen Tesla-Loggern übernehmen oder TrakFog vollständig und portabel exportieren.</p>
    </div>
    <div class="migration-hero-badge"><span>↔</span><b>Import / Export</b><small>Daten bleiben deine.</small></div>
  </section>

  <?php if ($message): ?><div class="alert alert-ok"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <section class="migration-actions-grid">
    <article class="panel migration-primary-card">
      <div class="panel-head">
        <div><h3>⬆ Daten übernehmen</h3><span class="small">Backup oder Export hochladen – TrakFog erkennt die Quelle.</span></div>
        <span class="pill ok">TeslaMate aktiv</span>
      </div>
      <div class="panel-body">
        <form method="post" enctype="multipart/form-data" class="migration-upload-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="upload_import">
          <label class="migration-dropzone">
            <input type="file" name="import_file" required
              accept=".bck,.backup,.dump,.zip,.sql,.gz,.csv,.json,.jsonl,.xlsx">
            <span class="migration-drop-icon">⇧</span>
            <strong>Datei auswählen</strong>
            <small>TeslaMate .bck · TrakFog .zip · weitere CSV/JSON-Adapter werden schrittweise aktiviert</small>
          </label>
          <div class="migration-safety-note">
            <span>🛡️</span>
            <p><b>Import mit Dubletten-Schutz.</b> Bestehende Fahrzeuge werden primär über VIN zugeordnet; Fahrten, Lade- und Positionsdaten werden nicht blind doppelt angelegt.</p>
          </div>
          <details class="migration-help">
            <summary>Wie erstelle ich ein TeslaMate-Backup?</summary>
            <p>Im Verzeichnis deiner TeslaMate-<code>docker-compose.yml</code>:</p>
            <code>docker compose exec -T database pg_dump -U teslamate teslamate &gt; ./teslamate.bck</code>
            <small>Falls dein Datenbank-Service oder Benutzer anders heißt, die Werte entsprechend anpassen. TrakFog liest dieses offizielle Plain-SQL-Backup direkt und importiert es nicht in eine fremde PostgreSQL-Datenbank.</small>
          </details>
          <button class="btn btn-primary" type="submit">Datei analysieren</button>
        </form>
      </div>
    </article>

    <article class="panel migration-primary-card">
      <div class="panel-head">
        <div><h3>⬇ TrakFog exportieren</h3><span class="small">Portabler Datenexport für Backup, Umzug oder eine andere TrakFog-Instanz.</span></div>
        <span class="pill ok">Format V1</span>
      </div>
      <div class="panel-body">
        <div class="migration-export-list">
          <span>✓ Fahrzeuge &amp; Fahrten</span>
          <span>✓ Positionshistorie</span>
          <span>✓ Ladevorgänge &amp; Kosten</span>
          <span>✓ Orte &amp; Geofences</span>
          <span>✓ Sleep-/Statushistorie</span>
          <span>✓ Reisen &amp; Zuordnungen</span>
        </div>
        <p class="hint">Nicht enthalten: Benutzerpasswörter, Tesla-Tokens, GitHub-Tokens oder andere Zugangsdaten.</p>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create_export">
          <button class="btn btn-primary" type="submit">Portablen Export erstellen</button>
        </form>
      </div>
    </article>
  </section>

  <?php if ($focusJob): ?>
    <?php
      $analysis = is_array($focusJob['analysis'] ?? null) ? $focusJob['analysis'] : [];
      $provider = $providers[(string)$focusJob['source']] ?? null;
      $ready = !empty($analysis['ready']) && ($provider['status'] ?? '') === 'ready';
    ?>
    <section class="panel migration-preview-card" id="migrationFocus">
      <div class="panel-head">
        <div>
          <span class="kicker">Erkannt</span>
          <h3><?= e((string)($analysis['label'] ?? $provider['label'] ?? $focusJob['source'])) ?></h3>
          <span class="small"><?= e((string)($analysis['format'] ?? 'Importdatei')) ?> · <?= e($formatBytes((int)($focusJob['file_size'] ?? 0))) ?></span>
        </div>
        <span class="pill <?= $ready ? 'ok' : 'warn' ?>"><?= $ready ? 'Import bereit' : 'Adapter vorbereitet' ?></span>
      </div>
      <div class="panel-body">
        <p class="migration-preview-summary"><?= e((string)($analysis['summary'] ?? 'Datei analysiert.')) ?></p>

        <?php if ((string)$focusJob['source'] === 'teslamate' && !empty($analysis['counts']) && is_array($analysis['counts'])): ?>
          <?php
            $previewCounts = [
              ['Fahrzeuge', (int)($analysis['counts']['cars'] ?? 0)],
              ['Fahrten', (int)($analysis['counts']['drives'] ?? 0)],
              ['Positionspunkte', (int)($analysis['counts']['positions'] ?? 0)],
              ['Ladevorgänge', (int)($analysis['counts']['charging_processes'] ?? 0)],
              ['Orte', (int)($analysis['counts']['geofences'] ?? 0)],
              ['Zustände', (int)($analysis['counts']['states'] ?? 0)],
            ];
          ?>
          <div class="migration-preview-counts">
            <?php foreach ($previewCounts as [$label,$count]): ?>
              <div><span><?= e($label) ?></span><strong><?= number_format($count,0,',','.') ?></strong></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($analysis['tables']) && is_array($analysis['tables'])): ?>
          <div class="migration-detected-tags">
            <?php foreach (array_slice($analysis['tables'], 0, 18) as $table): ?>
              <span><?= e((string)$table) ?></span>
            <?php endforeach; ?>
            <?php if (count($analysis['tables']) > 18): ?><span>+<?= count($analysis['tables']) - 18 ?></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($ready && (string)$focusJob['direction'] === 'import' && in_array((string)$focusJob['status'], ['ready','failed'], true)): ?>
          <div class="migration-import-warning">
            <strong>Vor dem Import</strong>
            <p>Bei großen TeslaMate-Backups können Millionen Positionspunkte verarbeitet werden. Der Import läuft deshalb im Hintergrund weiter, auch wenn du die Seite verlässt.</p>
          </div>
          <form method="post" class="split-actions">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="start_import">
            <input type="hidden" name="job_id" value="<?= (int)$focusJob['id'] ?>">
            <button class="btn btn-primary" type="submit"><?= (string)$focusJob['status'] === 'failed' ? 'Import erneut versuchen' : 'Import starten' ?></button>
          </form>
        <?php elseif (!$ready): ?>
          <div class="alert alert-warn">Diese Quelle wird bereits erkannt, aber der eigentliche Datenadapter ist noch nicht freigeschaltet. Die Datei wird nicht verändert oder teilweise importiert.</div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <div><h3>Quellen</h3><span class="small">Ein gemeinsamer Migrationskern – Adapter pro Datenquelle.</span></div>
      <span class="pill"><?= count($providers) ?> Quellen berücksichtigt</span>
    </div>
    <div class="panel-body">
      <div class="migration-provider-grid">
        <?php foreach ($providers as $key=>$provider): ?>
          <article class="migration-provider-card <?= ($provider['status'] ?? '') === 'ready' ? 'ready' : 'planned' ?>">
            <div class="migration-provider-icon"><?= e((string)$provider['icon']) ?></div>
            <div>
              <strong><?= e((string)$provider['label']) ?></strong>
              <small><?= e((string)$provider['description']) ?></small>
            </div>
            <span class="pill <?= ($provider['status'] ?? '') === 'ready' ? 'ok' : '' ?>">
              <?= ($provider['status'] ?? '') === 'ready' ? 'aktiv' : 'vorbereitet' ?>
            </span>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <div><h3>Migrationsverlauf</h3><span class="small">Importe und Exporte mit Status, Fortschritt und Ergebnis.</span></div>
    </div>
    <div class="panel-body">
      <?php if (!$jobs): ?>
        <div class="empty-state"><strong>Noch keine Migrationen</strong><span>Der erste Import oder Export erscheint hier.</span></div>
      <?php else: ?>
        <div class="migration-job-list">
          <?php foreach ($jobs as $job): ?>
            <?php
              $status = (string)$job['status'];
              $provider = $providers[(string)$job['source']] ?? ['label'=>(string)$job['source']];
              $result = is_string($job['result_json'] ?? null) ? json_decode((string)$job['result_json'], true) : null;
              $running = in_array($status, ['queued','running'], true);
            ?>
            <article class="migration-job" data-migration-job="<?= (int)$job['id'] ?>" data-running="<?= $running ? '1' : '0' ?>">
              <div class="migration-job-main">
                <div class="migration-job-icon"><?= (string)$job['direction'] === 'export' ? '⇩' : '⇧' ?></div>
                <div>
                  <strong><?= e((string)$provider['label']) ?> · <?= (string)$job['direction'] === 'export' ? 'Export' : 'Import' ?></strong>
                  <small><?= e((string)($job['original_name'] ?: ('Auftrag #' . $job['id']))) ?></small>
                </div>
              </div>
              <div class="migration-job-progress">
                <div class="migration-progress-track"><span data-progress-bar style="width:<?= max(0,min(100,(float)$job['progress_percent'])) ?>%"></span></div>
                <small data-progress-text><?= e((string)($job['progress_message'] ?: $status)) ?></small>
              </div>
              <div class="migration-job-state">
                <span class="pill <?= $status === 'completed' ? 'ok' : ($status === 'failed' ? 'bad' : ($running ? 'warn' : '')) ?>" data-status-pill>
                  <?= e($status) ?>
                </span>
                <?php if ((string)$job['direction'] === 'export' && $status === 'completed'): ?>
                  <a class="btn btn-ghost btn-small" data-download href="migration.php?download=<?= (int)$job['id'] ?>">ZIP laden</a>
                <?php endif; ?>
              </div>
              <?php if ($status === 'completed' && is_array($result['stats'] ?? null)): ?>
                <div class="migration-result-chips">
                  <?php foreach ($result['stats'] as $label=>$value): ?>
                    <?php if ((int)$value > 0): ?><span><?= e((string)$label) ?> <b><?= number_format((int)$value,0,',','.') ?></b></span><?php endif; ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($status === 'failed' && !empty($job['error_message'])): ?>
                <div class="migration-job-error">⚠ <?= e((string)$job['error_message']) ?></div>
              <?php endif; ?>
              <?php if (!$running): ?>
                <form method="post" class="migration-job-delete">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete_job">
                  <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                  <button class="btn btn-ghost btn-small" type="submit">Entfernen</button>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
(() => {
  const jobs = [...document.querySelectorAll('[data-migration-job][data-running="1"]')];
  if (!jobs.length) return;

  let pending = jobs.length;
  const poll = async (card) => {
    const id = card.dataset.migrationJob;
    try {
      const response = await fetch('migration.php?status=' + encodeURIComponent(id) + '&json=1', {
        credentials: 'same-origin',
        headers: {'Accept':'application/json'}
      });
      if (!response.ok) return true;
      const payload = await response.json();
      const job = payload.job;
      const bar = card.querySelector('[data-progress-bar]');
      const text = card.querySelector('[data-progress-text]');
      const pill = card.querySelector('[data-status-pill]');
      if (bar) bar.style.width = Math.max(0, Math.min(100, Number(job.progress_percent || 0))) + '%';
      if (text) text.textContent = job.progress_message || job.status;
      if (pill) pill.textContent = job.status;
      if (job.status === 'completed' || job.status === 'failed') return false;
    } catch (_) {}
    return true;
  };

  const tick = async () => {
    pending = 0;
    for (const card of jobs) {
      if (card.dataset.done === '1') continue;
      const keep = await poll(card);
      if (keep) pending++;
      else card.dataset.done = '1';
    }
    if (pending) window.setTimeout(tick, 1800);
    else window.setTimeout(() => window.location.reload(), 700);
  };
  window.setTimeout(tick, 900);
})();
</script>
<?php render_footer(); ?>
