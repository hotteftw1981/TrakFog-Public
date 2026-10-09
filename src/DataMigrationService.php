<?php

declare(strict_types=1);

final class DataMigrationService
{
    private const MAX_UPLOAD_BYTES = 8589934592;
    private const EXPORT_FORMAT = 'trakfog-data-export';
    private const EXPORT_FORMAT_VERSION = 1;

    public static function providers(): array
    {
        return [
            'teslamate' => [
                'label' => 'TeslaMate',
                'icon' => 'TM',
                'accept' => '.bck,.backup,.dump',
                'status' => 'ready',
                'description' => 'PostgreSQL-Backup übernehmen: Fahrzeuge, Fahrten, Positionspunkte, Ladevorgänge, Orte und Zustände.',
            ],
            'trakfog' => [
                'label' => 'TrakFog',
                'icon' => 'TF',
                'accept' => '.zip',
                'status' => 'ready',
                'description' => 'Vollständigen TrakFog-Datenexport importieren oder einen portablen Export erstellen.',
            ],
            'teslalogger' => [
                'label' => 'TeslaLogger',
                'icon' => 'TL',
                'accept' => '.zip,.sql,.gz,.csv',
                'status' => 'next',
                'description' => 'MySQL-/CSV-Migration ist als nächster Adapter vorbereitet.',
            ],
            'teslafi' => [
                'label' => 'TeslaFi',
                'icon' => 'Fi',
                'accept' => '.zip,.csv',
                'status' => 'next',
                'description' => 'CSV-Exporte werden über den gemeinsamen Importkern angebunden.',
            ],
            'tessie' => [
                'label' => 'Tessie',
                'icon' => 'Te',
                'accept' => '.zip,.csv,.json',
                'status' => 'next',
                'description' => 'Drive-, Charge- und Rohdatenexporte werden über einen eigenen Adapter angebunden.',
            ],
            'teslascope' => [
                'label' => 'Teslascope',
                'icon' => 'TS',
                'accept' => '.zip,.csv,.json',
                'status' => 'next',
                'description' => 'Exportdateien werden künftig direkt in das TrakFog-Migrationsmodell übersetzt.',
            ],
            'tronity' => [
                'label' => 'TRONITY',
                'icon' => 'TR',
                'accept' => '.zip,.csv,.xlsx',
                'status' => 'next',
                'description' => 'CSV-/XLSX-Historien sind im Adapterplan berücksichtigt.',
            ],
            'tezlab' => [
                'label' => 'TezLab',
                'icon' => 'TZ',
                'accept' => '.zip,.csv',
                'status' => 'next',
                'description' => 'Drive- und Charge-CSV-Exporte sind im Adapterplan berücksichtigt.',
            ],
            'generic' => [
                'label' => 'CSV / JSON',
                'icon' => '↔',
                'accept' => '.zip,.csv,.json,.jsonl',
                'status' => 'next',
                'description' => 'Universeller Feld-Mapper für Eigenbau-Logger, TeslaPy und andere Quellen.',
            ],
        ];
    }

    public static function jobs(PDO $pdo, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        return $pdo->query("SELECT * FROM data_migration_jobs ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    public static function job(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM data_migration_jobs WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['analysis'] = self::decodeJson((string)($row['analysis_json'] ?? ''));
        $row['result'] = self::decodeJson((string)($row['result_json'] ?? ''));
        return $row;
    }

    public static function createImportJob(PDO $pdo, array $file, int $userId): array
    {
        self::ensureDirectories();

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $name = trim((string)($file['name'] ?? 'import.dat'));
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Die hochgeladene Datei wurde nicht gefunden.');
        }
        if ($size <= 0) {
            throw new RuntimeException('Die hochgeladene Datei ist leer.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Die Datei ist größer als 8 GiB.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['bck','backup','dump','zip','sql','gz','csv','json','jsonl','xlsx'];
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Dieses Dateiformat wird vom Migrationscenter nicht akzeptiert.');
        }

        $token = bin2hex(random_bytes(18));
        $target = self::runtimeRoot() . '/incoming/' . $token . ($ext !== '' ? '.' . $ext : '');
        if (!@move_uploaded_file($tmp, $target)) {
            if (!@rename($tmp, $target)) {
                throw new RuntimeException('Die Importdatei konnte nicht im TrakFog-Datenbereich gespeichert werden.');
            }
        }
        @chmod($target, 0640);

        $analysis = self::detectSource($target, $name);
        $source = (string)($analysis['source'] ?? 'generic');

        $stmt = $pdo->prepare(
            "INSERT INTO data_migration_jobs
                (direction,source,status,original_name,stored_path,file_size,sha256,analysis_json,created_by,progress_message)
             VALUES
                ('import',?,'ready',?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $source,
            $name,
            $target,
            filesize($target) ?: $size,
            hash_file('sha256', $target) ?: null,
            json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $userId,
            'Datei erkannt. Bereit für die Vorschau.',
        ]);

        return self::job($pdo, (int)$pdo->lastInsertId())
            ?? throw new RuntimeException('Der Importauftrag konnte nicht angelegt werden.');
    }

    public static function createExportJob(PDO $pdo, int $userId): array
    {
        self::ensureDirectories();

        $stmt = $pdo->prepare(
            "INSERT INTO data_migration_jobs
                (direction,source,status,created_by,progress_message)
             VALUES
                ('export','trakfog','queued',?,'Export wird vorbereitet.')"
        );
        $stmt->execute([$userId]);
        $jobId = (int)$pdo->lastInsertId();
        self::launchWorker($jobId);

        return self::job($pdo, $jobId)
            ?? throw new RuntimeException('Der Exportauftrag konnte nicht angelegt werden.');
    }

    public static function startImport(PDO $pdo, int $jobId): array
    {
        $job = self::job($pdo, $jobId);
        if (!$job || (string)$job['direction'] !== 'import') {
            throw new RuntimeException('Der Importauftrag wurde nicht gefunden.');
        }
        if ((string)$job['status'] !== 'ready' && (string)$job['status'] !== 'failed') {
            throw new RuntimeException('Dieser Importauftrag kann aktuell nicht gestartet werden.');
        }

        $providers = self::providers();
        $source = (string)$job['source'];
        if (!isset($providers[$source]) || ($providers[$source]['status'] ?? '') !== 'ready') {
            throw new RuntimeException(
                'Der ' . ($providers[$source]['label'] ?? $source) . '-Adapter ist vorbereitet, aber noch nicht für echte Importe freigeschaltet.'
            );
        }

        $pdo->prepare(
            "UPDATE data_migration_jobs
             SET status='queued',progress_percent=0,progress_message='Import wartet auf den Migrationsdienst.',
                 error_message=NULL,result_json=NULL,started_at=NULL,completed_at=NULL
             WHERE id=?"
        )->execute([$jobId]);

        self::launchWorker($jobId);
        return self::job($pdo, $jobId)
            ?? throw new RuntimeException('Der Importauftrag konnte nicht gestartet werden.');
    }

    public static function deleteJob(PDO $pdo, int $jobId): void
    {
        $job = self::job($pdo, $jobId);
        if (!$job) {
            return;
        }
        if (in_array((string)$job['status'], ['queued','running'], true)) {
            throw new RuntimeException('Ein laufender Migrationsauftrag kann nicht gelöscht werden.');
        }

        foreach (['stored_path','result_path'] as $key) {
            $path = (string)($job[$key] ?? '');
            if ($path !== '' && self::insideRuntimeRoot($path) && is_file($path)) {
                @unlink($path);
            }
        }
        $pdo->prepare('DELETE FROM data_migration_jobs WHERE id=?')->execute([$jobId]);
    }

    public static function processJob(PDO $pdo, int $jobId): void
    {
        $job = self::job($pdo, $jobId);
        if (!$job) {
            throw new RuntimeException('Migrationsauftrag nicht gefunden.');
        }

        self::updateJob($pdo, $jobId, [
            'status' => 'running',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => null,
            'progress_percent' => 1,
            'progress_message' => 'Migrationsauftrag gestartet.',
            'error_message' => null,
        ]);

        try {
            if ((string)$job['direction'] === 'export') {
                $result = self::exportTrakFog($pdo, $jobId, (int)($job['created_by'] ?? 0));
            } elseif ((string)$job['source'] === 'teslamate') {
                $result = self::importTeslaMate($pdo, $jobId, $job);
            } elseif ((string)$job['source'] === 'trakfog') {
                $result = self::importTrakFog($pdo, $jobId, $job);
            } else {
                throw new RuntimeException('Für diese Quelle ist der Importadapter noch nicht aktiviert.');
            }

            $successUpdate = [
                'status' => 'completed',
                'progress_percent' => 100,
                'progress_message' => (string)($result['message'] ?? 'Migration abgeschlossen.'),
                'result_json' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ];
            if ((string)$job['direction'] === 'import') {
                $sourcePath = (string)($job['stored_path'] ?? '');
                if ($sourcePath !== '' && self::insideRuntimeRoot($sourcePath) && is_file($sourcePath)) {
                    @unlink($sourcePath);
                    $successUpdate['stored_path'] = null;
                }
            }
            self::updateJob($pdo, $jobId, $successUpdate);
        } catch (Throwable $e) {
            AppLogger::exception($e, ['migration_job_id' => $jobId]);
            self::updateJob($pdo, $jobId, [
                'status' => 'failed',
                'progress_message' => 'Migration fehlgeschlagen.',
                'error_message' => $e->getMessage(),
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ]);
            throw $e;
        }
    }

    public static function canDownload(array $job): bool
    {
        $path = (string)($job['result_path'] ?? '');
        return (string)($job['direction'] ?? '') === 'export'
            && (string)($job['status'] ?? '') === 'completed'
            && $path !== ''
            && self::insideRuntimeRoot($path)
            && is_readable($path);
    }

    private static function importTeslaMate(PDO $pdo, int $jobId, array $job): array
    {
        $archive = (string)($job['stored_path'] ?? '');
        if ($archive === '' || !is_readable($archive)) {
            throw new RuntimeException('Das TeslaMate-Backup ist nicht mehr verfügbar.');
        }
        $analysis = is_array($job['analysis'] ?? null) ? $job['analysis'] : [];
        self::$plainCopyIndex = is_array($analysis['copy_index'] ?? null) ? $analysis['copy_index'] : [];
        if (self::isPgCustomDump($archive)) {
            self::assertCommandAvailable('pg_restore');
        }
        $tables = array_fill_keys((array)($analysis['tables'] ?? []), true);
        foreach (['cars','drives','positions','charging_processes'] as $required) {
            if (!isset($tables[$required])) {
                throw new RuntimeException('Das Backup enthält die erwartete TeslaMate-Tabelle "' . $required . '" nicht.');
            }
        }

        $stats = [
            'vehicles' => 0,
            'trips' => 0,
            'positions' => 0,
            'charges' => 0,
            'state_events' => 0,
            'sleep_sessions' => 0,
            'geofences' => 0,
            'duplicates' => 0,
        ];

        self::updateProgress($pdo, $jobId, 4, 'TeslaMate-Backup wird gelesen.');

        $addresses = [];
        if (isset($tables['addresses'])) {
            self::streamPgTable($archive, 'addresses', static function (array $row) use (&$addresses): void {
                $id = (string)($row['id'] ?? '');
                if ($id === '') {
                    return;
                }
                $addresses[$id] = [
                    'latitude' => self::num($row['latitude'] ?? null),
                    'longitude' => self::num($row['longitude'] ?? null),
                    'name' => self::firstText($row, ['name','display_name','city']),
                    'raw' => $row,
                ];
            });
        }

        $geofenceMap = [];
        if (isset($tables['geofences'])) {
            self::streamPgTable($archive, 'geofences', static function (array $row) use ($pdo, &$geofenceMap, &$stats): void {
                $sourceId = (string)($row['id'] ?? '');
                $lat = self::num($row['latitude'] ?? null);
                $lon = self::num($row['longitude'] ?? null);
                $name = trim((string)($row['name'] ?? ''));
                if ($sourceId === '' || $lat === null || $lon === null || $name === '') {
                    return;
                }
                $radius = max(20, min(50000, (int)round((float)($row['radius'] ?? 150))));
                $stmt = $pdo->prepare(
                    'SELECT id FROM geofences WHERE name=? AND ABS(latitude-?)<0.00001 AND ABS(longitude-?)<0.00001 LIMIT 1'
                );
                $stmt->execute([$name,$lat,$lon]);
                $id = (int)($stmt->fetchColumn() ?: 0);
                if ($id <= 0) {
                    $ins = $pdo->prepare(
                        "INSERT INTO geofences(name,kind,shape_type,latitude,longitude,radius_m,active,notes)
                         VALUES(?,'place','circle',?,?,?,1,'Importiert aus TeslaMate')"
                    );
                    $ins->execute([$name,$lat,$lon,$radius]);
                    $id = (int)$pdo->lastInsertId();
                    $stats['geofences']++;
                }
                $geofenceMap[$sourceId] = ['id'=>$id,'name'=>$name,'latitude'=>$lat,'longitude'=>$lon];
            });
        }

        self::updateProgress($pdo, $jobId, 10, 'Fahrzeuge werden zugeordnet.');

        $vehicleMap = [];
        self::streamPgTable($archive, 'cars', static function (array $row) use ($pdo, &$vehicleMap, &$stats): void {
            $sourceId = (string)($row['id'] ?? '');
            if ($sourceId === '') {
                return;
            }
            $vin = trim((string)($row['vin'] ?? ''));
            $displayName = self::firstText($row, ['name','display_name']) ?: ('TeslaMate Fahrzeug ' . $sourceId);
            $model = self::firstText($row, ['model','trim_badging']);
            $vehicleId = 0;

            if ($vin !== '') {
                $stmt = $pdo->prepare('SELECT id FROM vehicles WHERE vin=? ORDER BY id LIMIT 1');
                $stmt->execute([$vin]);
                $vehicleId = (int)($stmt->fetchColumn() ?: 0);
            }
            if ($vehicleId <= 0) {
                $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE source_type='teslamate' AND external_id=? LIMIT 1");
                $stmt->execute([$sourceId]);
                $vehicleId = (int)($stmt->fetchColumn() ?: 0);
            }

            if ($vehicleId <= 0) {
                $ins = $pdo->prepare(
                    "INSERT INTO vehicles
                        (integration_id,source_type,external_id,vehicle_id,vin,display_name,model_name,state,raw_json)
                     VALUES(NULL,'teslamate',?,?,?,?,?,'offline',?)"
                );
                $ins->execute([
                    $sourceId,
                    self::firstText($row, ['vid','eid']),
                    $vin !== '' ? $vin : null,
                    $displayName,
                    $model,
                    json_encode(['migration_source'=>'teslamate','source'=>$row], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $vehicleId = (int)$pdo->lastInsertId();
                $stats['vehicles']++;
            } else {
                $pdo->prepare(
                    "UPDATE vehicles
                     SET display_name=COALESCE(NULLIF(display_name,''),?),
                         model_name=COALESCE(NULLIF(model_name,''),?),
                         vin=COALESCE(NULLIF(vin,''),?)
                     WHERE id=?"
                )->execute([$displayName,$model,$vin !== '' ? $vin : null,$vehicleId]);
            }
            $vehicleMap[$sourceId] = $vehicleId;
        });

        if (!$vehicleMap) {
            throw new RuntimeException('Im TeslaMate-Backup wurden keine Fahrzeuge erkannt.');
        }

        self::updateProgress($pdo, $jobId, 18, 'Fahrten werden übernommen.');

        $tripMap = [];
        self::streamPgTable($archive, 'drives', static function (array $row) use ($pdo, $vehicleMap, &$tripMap, &$stats): void {
            $sourceId = (string)($row['id'] ?? '');
            $vehicleId = $vehicleMap[(string)($row['car_id'] ?? '')] ?? null;
            $started = self::dateSql($row['start_date'] ?? null);
            if ($sourceId === '' || !$vehicleId || !$started) {
                return;
            }
            $ended = self::dateSql($row['end_date'] ?? null);
            $stmt = $pdo->prepare('SELECT id FROM trips WHERE vehicle_id=? AND started_at=? LIMIT 1');
            $stmt->execute([$vehicleId,$started]);
            $tripId = (int)($stmt->fetchColumn() ?: 0);
            if ($tripId <= 0) {
                $distance = self::num($row['distance'] ?? null);
                if ($distance === null) {
                    $startKm = self::num($row['start_km'] ?? null);
                    $endKm = self::num($row['end_km'] ?? null);
                    if ($startKm !== null && $endKm !== null && $endKm >= $startKm) {
                        $distance = $endKm - $startKm;
                    }
                }
                $ins = $pdo->prepare(
                    "INSERT INTO trips
                        (vehicle_id,started_at,ended_at,distance_km,max_speed_kmh)
                     VALUES(?,?,?,?,?)"
                );
                $ins->execute([
                    $vehicleId,
                    $started,
                    $ended,
                    $distance,
                    self::num($row['speed_max'] ?? null),
                ]);
                $tripId = (int)$pdo->lastInsertId();
                $stats['trips']++;
            } else {
                $stats['duplicates']++;
            }
            $tripMap[$sourceId] = $tripId;
        });

        self::updateProgress($pdo, $jobId, 28, 'Positionshistorie wird importiert.');

        self::createStreamStage($pdo);
        $positionBatch = [];
        $driveEdges = [];
        self::streamPgTable($archive, 'positions', static function (array $row) use (
            $pdo, $jobId, $vehicleMap, &$positionBatch, &$driveEdges, &$stats
        ): void {
            $vehicleId = $vehicleMap[(string)($row['car_id'] ?? '')] ?? null;
            $recorded = self::dateSql($row['date'] ?? $row['inserted_at'] ?? null, true);
            if (!$vehicleId || !$recorded) {
                return;
            }

            $lat = self::num($row['latitude'] ?? null);
            $lon = self::num($row['longitude'] ?? null);
            $positionBatch[] = [
                $vehicleId,
                $recorded,
                self::num($row['speed'] ?? null),
                self::num($row['odometer'] ?? null),
                self::num($row['battery_level'] ?? null),
                self::num($row['elevation'] ?? null),
                self::num($row['est_heading'] ?? null),
                $lat,
                $lon,
                self::num($row['power'] ?? null),
                self::firstText($row, ['shift_state']),
                self::num($row['rated_battery_range_km'] ?? $row['ideal_battery_range_km'] ?? null),
                self::num($row['est_battery_range_km'] ?? null),
                self::num($row['heading'] ?? null),
            ];

            $driveId = (string)($row['drive_id'] ?? '');
            if ($driveId !== '' && $lat !== null && $lon !== null) {
                if (!isset($driveEdges[$driveId])) {
                    $driveEdges[$driveId] = ['first'=>[$recorded,$lat,$lon],'last'=>[$recorded,$lat,$lon]];
                } else {
                    if ($recorded < $driveEdges[$driveId]['first'][0]) {
                        $driveEdges[$driveId]['first'] = [$recorded,$lat,$lon];
                    }
                    if ($recorded > $driveEdges[$driveId]['last'][0]) {
                        $driveEdges[$driveId]['last'] = [$recorded,$lat,$lon];
                    }
                }
            }

            if (count($positionBatch) >= 500) {
                self::insertStreamStageBatch($pdo, $positionBatch);
                $stats['positions'] += self::flushStreamStage($pdo);
                $positionBatch = [];
                if (($stats['positions'] % 10000) < 500) {
                    self::updateProgress(
                        $pdo,
                        $jobId,
                        min(64, 28 + (int)floor(log10(max(10, $stats['positions'])) * 7)),
                        number_format($stats['positions'], 0, ',', '.') . ' Positionspunkte übernommen.'
                    );
                }
            }
        });
        if ($positionBatch) {
            self::insertStreamStageBatch($pdo, $positionBatch);
            $stats['positions'] += self::flushStreamStage($pdo);
        }

        $tripUpdate = $pdo->prepare(
            'UPDATE trips SET start_latitude=?,start_longitude=?,end_latitude=?,end_longitude=? WHERE id=?'
        );
        foreach ($driveEdges as $sourceDriveId => $edges) {
            $tripId = $tripMap[(string)$sourceDriveId] ?? null;
            if ($tripId) {
                $tripUpdate->execute([
                    $edges['first'][1],$edges['first'][2],
                    $edges['last'][1],$edges['last'][2],
                    $tripId,
                ]);
            }
        }

        self::updateProgress($pdo, $jobId, 68, 'Ladevorgänge werden übernommen.');

        $chargePower = [];
        if (isset($tables['charges'])) {
            self::streamPgTable($archive, 'charges', static function (array $row) use (&$chargePower): void {
                $processId = (string)($row['charging_process_id'] ?? '');
                if ($processId === '') {
                    return;
                }
                $power = self::num($row['charger_power'] ?? $row['power'] ?? null);
                if ($power !== null) {
                    $chargePower[$processId] = max((float)($chargePower[$processId] ?? 0), $power);
                }
            });
        }

        self::streamPgTable($archive, 'charging_processes', static function (array $row) use (
            $pdo, $vehicleMap, $addresses, $geofenceMap, $chargePower, &$stats
        ): void {
            $vehicleId = $vehicleMap[(string)($row['car_id'] ?? '')] ?? null;
            $started = self::dateSql($row['start_date'] ?? null);
            if (!$vehicleId || !$started) {
                return;
            }

            $stmt = $pdo->prepare('SELECT id FROM charges WHERE vehicle_id=? AND started_at=? LIMIT 1');
            $stmt->execute([$vehicleId,$started]);
            if ($stmt->fetchColumn()) {
                $stats['duplicates']++;
                return;
            }

            $address = $addresses[(string)($row['address_id'] ?? '')] ?? null;
            $geofence = $geofenceMap[(string)($row['geofence_id'] ?? '')] ?? null;
            $lat = $address['latitude'] ?? $geofence['latitude'] ?? null;
            $lon = $address['longitude'] ?? $geofence['longitude'] ?? null;
            $location = $geofence['name'] ?? $address['name'] ?? null;
            $cost = self::num($row['cost'] ?? null);
            $price = self::num($row['cost_per_kwh'] ?? null);
            $sourceProcessId = (string)($row['id'] ?? '');

            $ins = $pdo->prepare(
                "INSERT INTO charges
                    (vehicle_id,started_at,ended_at,energy_added_kwh,start_battery_percent,end_battery_percent,max_power_kw,
                     latitude,longitude,location_name,price_per_kwh,cost_amount,cost_currency,cost_source,cost_locked)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'import_teslamate',?)"
            );
            $ins->execute([
                $vehicleId,
                $started,
                self::dateSql($row['end_date'] ?? null),
                self::num($row['charge_energy_added'] ?? $row['charge_energy_used'] ?? null),
                self::num($row['start_battery_level'] ?? null),
                self::num($row['end_battery_level'] ?? null),
                $chargePower[$sourceProcessId] ?? null,
                $lat,
                $lon,
                $location,
                $price,
                $cost,
                null,
                $cost !== null ? 1 : 0,
            ]);
            $stats['charges']++;
        });

        self::updateProgress($pdo, $jobId, 82, 'Fahrzeugzustände und Schlafhistorie werden übernommen.');

        if (isset($tables['states'])) {
            self::streamPgTable($archive, 'states', static function (array $row) use ($pdo, $vehicleMap, &$stats): void {
                $vehicleId = $vehicleMap[(string)($row['car_id'] ?? '')] ?? null;
                $state = strtolower(trim((string)($row['state'] ?? '')));
                $started = self::dateSql($row['start_date'] ?? null);
                if (!$vehicleId || !$started || $state === '') {
                    return;
                }
                $check = $pdo->prepare(
                    'SELECT id FROM vehicle_state_events WHERE vehicle_id=? AND observed_at=? AND to_state=? LIMIT 1'
                );
                $check->execute([$vehicleId,$started,$state]);
                if (!$check->fetchColumn()) {
                    $pdo->prepare(
                        "INSERT INTO vehicle_state_events(vehicle_id,observed_at,from_state,to_state,source)
                         VALUES(?,?,NULL,?,'import_teslamate')"
                    )->execute([$vehicleId,$started,$state]);
                    $stats['state_events']++;
                }

                if ($state === 'asleep') {
                    $sleepCheck = $pdo->prepare('SELECT id FROM sleep_sessions WHERE vehicle_id=? AND started_at=? LIMIT 1');
                    $sleepCheck->execute([$vehicleId,$started]);
                    if (!$sleepCheck->fetchColumn()) {
                        $ended = self::dateSql($row['end_date'] ?? null);
                        $duration = $ended ? max(0, strtotime($ended . ' UTC') - strtotime($started . ' UTC')) : null;
                        $pdo->prepare(
                            "INSERT INTO sleep_sessions
                                (vehicle_id,started_at,ended_at,start_state,end_state,duration_seconds,quality,excluded_reason)
                             VALUES(?,?,?,'asleep',?,?, 'imported', NULL)"
                        )->execute([$vehicleId,$started,$ended,$ended ? 'asleep' : null,$duration]);
                        $stats['sleep_sessions']++;
                    }
                }
            });
        }

        self::updateProgress($pdo, $jobId, 94, 'Import wird finalisiert.');

        foreach ($vehicleMap as $vehicleId) {
            $pdo->prepare(
                "UPDATE vehicles v
                 SET v.last_seen_at=COALESCE(
                     (SELECT MAX(recorded_at) FROM vehicle_stream_samples s WHERE s.vehicle_id=v.id),
                     v.last_seen_at
                 )
                 WHERE v.id=?"
            )->execute([$vehicleId]);
        }

        return [
            'message' => 'TeslaMate-Migration abgeschlossen.',
            'source' => 'teslamate',
            'stats' => $stats,
        ];
    }

    private static function exportTrakFog(PDO $pdo, int $jobId, int $userId): array
    {
        self::ensureDirectories();
        $stamp = gmdate('Ymd-His');
        $workDir = self::runtimeRoot() . '/work/export-' . $jobId . '-' . bin2hex(random_bytes(5));
        $result = self::runtimeRoot() . '/exports/TrakFog-Data-' . $stamp . '.zip';
        if (!@mkdir($workDir, 0750, true) && !is_dir($workDir)) {
            throw new RuntimeException('Export-Arbeitsverzeichnis konnte nicht angelegt werden.');
        }

        $tables = [
            'vehicles',
            'trips',
            'vehicle_stream_samples',
            'charges',
            'vehicle_state_events',
            'sleep_sessions',
            'geo_locations',
            'geofences',
            'charging_tariffs',
            'journeys',
            'journey_trips',
            'journey_charges',
        ];

        $manifest = [
            'format' => self::EXPORT_FORMAT,
            'format_version' => self::EXPORT_FORMAT_VERSION,
            'trakfog_version' => self::currentVersion(),
            'created_at' => gmdate(DATE_ATOM),
            'tables' => [],
        ];

        $tableCount = count($tables);
        foreach ($tables as $index => $table) {
            self::updateProgress(
                $pdo,
                $jobId,
                5 + (int)floor(($index / max(1, $tableCount)) * 78),
                'Exportiere ' . $table . ' …'
            );

            $file = $workDir . '/' . $table . '.jsonl';
            $fh = fopen($file, 'wb');
            if (!$fh) {
                throw new RuntimeException('Exportdatei konnte nicht erstellt werden.');
            }
            $count = 0;

            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $stmt = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                    $count++;
                }
                $stmt->closeCursor();
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                fclose($fh);
            }

            $manifest['tables'][$table] = [
                'rows' => $count,
                'file' => 'data/' . $table . '.jsonl',
            ];
        }

        file_put_contents(
            $workDir . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        self::updateProgress($pdo, $jobId, 86, 'Export wird gepackt.');

        $zip = new ZipArchive();
        if ($zip->open($result, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Das Export-ZIP konnte nicht erstellt werden.');
        }
        $zip->addFile($workDir . '/manifest.json', 'manifest.json');
        foreach ($tables as $table) {
            $zip->addFile($workDir . '/' . $table . '.jsonl', 'data/' . $table . '.jsonl');
        }
        $zip->close();

        self::removeTree($workDir);
        @chmod($result, 0640);

        $pdo->prepare('UPDATE data_migration_jobs SET result_path=? WHERE id=?')->execute([$result,$jobId]);

        return [
            'message' => 'Portabler TrakFog-Datenexport erstellt.',
            'format' => self::EXPORT_FORMAT,
            'format_version' => self::EXPORT_FORMAT_VERSION,
            'file_name' => basename($result),
            'file_size' => filesize($result) ?: null,
            'tables' => $manifest['tables'],
            'requested_by' => $userId,
        ];
    }

    private static function importTrakFog(PDO $pdo, int $jobId, array $job): array
    {
        $archive = (string)($job['stored_path'] ?? '');
        if ($archive === '' || !is_readable($archive)) {
            throw new RuntimeException('Der TrakFog-Export ist nicht mehr verfügbar.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Der TrakFog-Export konnte nicht geöffnet werden.');
        }

        try {
            $manifestRaw = $zip->getFromName('manifest.json');
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (!is_array($manifest)
                || ($manifest['format'] ?? null) !== self::EXPORT_FORMAT
                || (int)($manifest['format_version'] ?? 0) !== self::EXPORT_FORMAT_VERSION) {
                throw new RuntimeException('Das ZIP ist kein unterstützter TrakFog-Datenexport.');
            }

            $stats = [
                'vehicles'=>0,'trips'=>0,'positions'=>0,'charges'=>0,
                'state_events'=>0,'sleep_sessions'=>0,'geofences'=>0,
                'journeys'=>0,'duplicates'=>0,
            ];
            $vehicleMap = [];
            $tripMap = [];
            $chargeMap = [];
            $tariffMap = [];
            $journeyMap = [];

            self::updateProgress($pdo, $jobId, 8, 'TrakFog-Fahrzeuge werden zugeordnet.');
            self::streamZipJsonl($zip, 'data/vehicles.jsonl', static function (array $row) use ($pdo, &$vehicleMap, &$stats): void {
                $oldId = (string)($row['id'] ?? '');
                if ($oldId === '') {
                    return;
                }
                $vin = trim((string)($row['vin'] ?? ''));
                $sourceType = trim((string)($row['source_type'] ?? 'trakfog_import')) ?: 'trakfog_import';
                $externalId = trim((string)($row['external_id'] ?? ''));
                $id = 0;
                if ($vin !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM vehicles WHERE vin=? ORDER BY id LIMIT 1');
                    $stmt->execute([$vin]);
                    $id = (int)($stmt->fetchColumn() ?: 0);
                }
                if ($id <= 0 && $externalId !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM vehicles WHERE source_type=? AND external_id=? LIMIT 1');
                    $stmt->execute([$sourceType,$externalId]);
                    $id = (int)($stmt->fetchColumn() ?: 0);
                }
                if ($id <= 0) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO vehicles
                          (integration_id,source_type,external_id,vehicle_id,vin,display_name,model_name,state,odometer_km,
                           battery_level,usable_battery_level,rated_range_km,ideal_range_km,latitude,longitude,heading,speed_kmh,last_seen_at,raw_json)
                         VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $sourceType,
                        $externalId !== '' ? $externalId : null,
                        $row['vehicle_id'] ?? null,
                        $vin !== '' ? $vin : null,
                        $row['display_name'] ?? null,
                        $row['model_name'] ?? null,
                        $row['state'] ?? null,
                        $row['odometer_km'] ?? null,
                        $row['battery_level'] ?? null,
                        $row['usable_battery_level'] ?? null,
                        $row['rated_range_km'] ?? null,
                        $row['ideal_range_km'] ?? null,
                        $row['latitude'] ?? null,
                        $row['longitude'] ?? null,
                        $row['heading'] ?? null,
                        $row['speed_kmh'] ?? null,
                        self::dateSql($row['last_seen_at'] ?? null),
                        $row['raw_json'] ?? null,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    $stats['vehicles']++;
                }
                $vehicleMap[$oldId] = $id;
            });

            self::updateProgress($pdo, $jobId, 18, 'Tarife und Orte werden übernommen.');
            self::streamZipJsonl($zip, 'data/charging_tariffs.jsonl', static function (array $row) use ($pdo, &$tariffMap): void {
                $oldId = (string)($row['id'] ?? '');
                if ($oldId === '' || trim((string)($row['name'] ?? '')) === '') {
                    return;
                }
                $stmt = $pdo->prepare(
                    "SELECT id FROM charging_tariffs WHERE name=? AND COALESCE(provider,'')=COALESCE(?,'') LIMIT 1"
                );
                $stmt->execute([$row['name'],$row['provider'] ?? null]);
                $id = (int)($stmt->fetchColumn() ?: 0);
                if ($id <= 0) {
                    $ins = $pdo->prepare(
                        'INSERT INTO charging_tariffs(name,provider,price_per_kwh,currency,is_default,active,notes) VALUES(?,?,?,?,0,?,?)'
                    );
                    $ins->execute([
                        $row['name'],$row['provider'] ?? null,$row['price_per_kwh'] ?? 0,
                        $row['currency'] ?? 'EUR',$row['active'] ?? 1,$row['notes'] ?? null,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                }
                $tariffMap[$oldId] = $id;
            });

            self::streamZipJsonl($zip, 'data/geofences.jsonl', static function (array $row) use ($pdo, &$stats): void {
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '' || !isset($row['latitude'],$row['longitude'])) {
                    return;
                }
                $check = $pdo->prepare(
                    'SELECT id FROM geofences WHERE name=? AND ABS(latitude-?)<0.00001 AND ABS(longitude-?)<0.00001 LIMIT 1'
                );
                $check->execute([$name,$row['latitude'],$row['longitude']]);
                if ($check->fetchColumn()) {
                    return;
                }
                $pdo->prepare(
                    'INSERT INTO geofences(name,kind,shape_type,latitude,longitude,radius_m,polygon_json,active,notes)
                     VALUES(?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $name,$row['kind'] ?? 'place',$row['shape_type'] ?? 'circle',
                    $row['latitude'],$row['longitude'],$row['radius_m'] ?? 150,
                    $row['polygon_json'] ?? null,$row['active'] ?? 1,$row['notes'] ?? null,
                ]);
                $stats['geofences']++;
            });

            self::streamZipJsonl($zip, 'data/geo_locations.jsonl', static function (array $row) use ($pdo): void {
                if (!isset($row['latitude'],$row['longitude'],$row['lat_key'],$row['lon_key'])) {
                    return;
                }
                $pdo->prepare(
                    "INSERT IGNORE INTO geo_locations
                      (latitude,longitude,lat_key,lon_key,display_name,road,house_number,postcode,city,state,country,country_code,status,provider,attempts,last_attempt_at,resolved_at,raw_json)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $row['latitude'],$row['longitude'],$row['lat_key'],$row['lon_key'],
                    $row['display_name'] ?? null,$row['road'] ?? null,$row['house_number'] ?? null,
                    $row['postcode'] ?? null,$row['city'] ?? null,$row['state'] ?? null,
                    $row['country'] ?? null,$row['country_code'] ?? null,$row['status'] ?? 'resolved',
                    $row['provider'] ?? 'trakfog_import',$row['attempts'] ?? 0,
                    self::dateSql($row['last_attempt_at'] ?? null),self::dateSql($row['resolved_at'] ?? null),
                    $row['raw_json'] ?? null,
                ]);
            });

            self::updateProgress($pdo, $jobId, 32, 'Fahrten werden übernommen.');
            self::streamZipJsonl($zip, 'data/trips.jsonl', static function (array $row) use ($pdo, $vehicleMap, &$tripMap, &$stats): void {
                $oldId = (string)($row['id'] ?? '');
                $vehicleId = $vehicleMap[(string)($row['vehicle_id'] ?? '')] ?? null;
                $started = self::dateSql($row['started_at'] ?? null);
                if ($oldId === '' || !$vehicleId || !$started) {
                    return;
                }
                $check = $pdo->prepare('SELECT id FROM trips WHERE vehicle_id=? AND started_at=? LIMIT 1');
                $check->execute([$vehicleId,$started]);
                $id = (int)($check->fetchColumn() ?: 0);
                if ($id <= 0) {
                    $pdo->prepare(
                        'INSERT INTO trips(vehicle_id,started_at,ended_at,start_latitude,start_longitude,end_latitude,end_longitude,distance_km,energy_kwh,avg_wh_km,max_speed_kmh)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $vehicleId,$started,self::dateSql($row['ended_at'] ?? null),
                        $row['start_latitude'] ?? null,$row['start_longitude'] ?? null,
                        $row['end_latitude'] ?? null,$row['end_longitude'] ?? null,
                        $row['distance_km'] ?? null,$row['energy_kwh'] ?? null,
                        $row['avg_wh_km'] ?? null,$row['max_speed_kmh'] ?? null,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    $stats['trips']++;
                } else {
                    $stats['duplicates']++;
                }
                $tripMap[$oldId] = $id;
            });

            self::updateProgress($pdo, $jobId, 44, 'Positionspunkte werden übernommen.');
            self::createStreamStage($pdo);
            $batch = [];
            self::streamZipJsonl($zip, 'data/vehicle_stream_samples.jsonl', static function (array $row) use (
                $pdo,$jobId,$vehicleMap,&$batch,&$stats
            ): void {
                $vehicleId = $vehicleMap[(string)($row['vehicle_id'] ?? '')] ?? null;
                $recorded = self::dateSql($row['recorded_at'] ?? null, true);
                if (!$vehicleId || !$recorded) {
                    return;
                }
                $batch[] = [
                    $vehicleId,$recorded,$row['speed_kmh'] ?? null,$row['odometer_km'] ?? null,
                    $row['soc'] ?? null,$row['elevation_m'] ?? null,$row['est_heading'] ?? null,
                    $row['latitude'] ?? null,$row['longitude'] ?? null,$row['power_kw'] ?? null,
                    $row['shift_state'] ?? null,$row['range_km'] ?? null,$row['est_range_km'] ?? null,
                    $row['heading'] ?? null,
                ];
                if (count($batch) >= 500) {
                    self::insertStreamStageBatch($pdo, $batch);
                    $stats['positions'] += self::flushStreamStage($pdo);
                    $batch = [];
                    if (($stats['positions'] % 10000) < 500) {
                        self::updateProgress($pdo,$jobId,min(68,44+(int)floor(log10(max(10,$stats['positions']))*6)),
                            number_format($stats['positions'],0,',','.') . ' Positionspunkte übernommen.');
                    }
                }
            });
            if ($batch) {
                self::insertStreamStageBatch($pdo, $batch);
                $stats['positions'] += self::flushStreamStage($pdo);
            }

            self::updateProgress($pdo, $jobId, 70, 'Ladevorgänge werden übernommen.');
            self::streamZipJsonl($zip, 'data/charges.jsonl', static function (array $row) use (
                $pdo,$vehicleMap,$tariffMap,&$chargeMap,&$stats
            ): void {
                $oldId = (string)($row['id'] ?? '');
                $vehicleId = $vehicleMap[(string)($row['vehicle_id'] ?? '')] ?? null;
                $started = self::dateSql($row['started_at'] ?? null);
                if ($oldId === '' || !$vehicleId || !$started) {
                    return;
                }
                $check = $pdo->prepare('SELECT id FROM charges WHERE vehicle_id=? AND started_at=? LIMIT 1');
                $check->execute([$vehicleId,$started]);
                $id = (int)($check->fetchColumn() ?: 0);
                if ($id <= 0) {
                    $pdo->prepare(
                        "INSERT INTO charges
                          (vehicle_id,started_at,ended_at,energy_added_kwh,start_battery_percent,end_battery_percent,max_power_kw,
                           latitude,longitude,location_name,tariff_id,price_per_kwh,cost_amount,cost_currency,cost_source,cost_locked)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([
                        $vehicleId,$started,self::dateSql($row['ended_at'] ?? null),
                        $row['energy_added_kwh'] ?? null,$row['start_battery_percent'] ?? null,
                        $row['end_battery_percent'] ?? null,$row['max_power_kw'] ?? null,
                        $row['latitude'] ?? null,$row['longitude'] ?? null,$row['location_name'] ?? null,
                        isset($row['tariff_id']) ? ($tariffMap[(string)$row['tariff_id']] ?? null) : null,
                        $row['price_per_kwh'] ?? null,$row['cost_amount'] ?? null,$row['cost_currency'] ?? null,
                        'import_trakfog',$row['cost_locked'] ?? 0,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    $stats['charges']++;
                } else {
                    $stats['duplicates']++;
                }
                $chargeMap[$oldId] = $id;
            });

            self::updateProgress($pdo, $jobId, 80, 'Zustände und Schlafhistorie werden übernommen.');
            self::streamZipJsonl($zip, 'data/vehicle_state_events.jsonl', static function (array $row) use ($pdo,$vehicleMap,&$stats): void {
                $vehicleId = $vehicleMap[(string)($row['vehicle_id'] ?? '')] ?? null;
                $observed = self::dateSql($row['observed_at'] ?? null);
                $to = trim((string)($row['to_state'] ?? ''));
                if (!$vehicleId || !$observed || $to === '') return;
                $check=$pdo->prepare('SELECT id FROM vehicle_state_events WHERE vehicle_id=? AND observed_at=? AND to_state=? LIMIT 1');
                $check->execute([$vehicleId,$observed,$to]);
                if (!$check->fetchColumn()) {
                    $pdo->prepare(
                        "INSERT INTO vehicle_state_events(vehicle_id,observed_at,from_state,to_state,source) VALUES(?,?,?,?, 'import_trakfog')"
                    )->execute([$vehicleId,$observed,$row['from_state'] ?? null,$to]);
                    $stats['state_events']++;
                }
            });

            self::streamZipJsonl($zip, 'data/sleep_sessions.jsonl', static function (array $row) use ($pdo,$vehicleMap,&$stats): void {
                $vehicleId = $vehicleMap[(string)($row['vehicle_id'] ?? '')] ?? null;
                $started = self::dateSql($row['started_at'] ?? null);
                if (!$vehicleId || !$started) return;
                $check=$pdo->prepare('SELECT id FROM sleep_sessions WHERE vehicle_id=? AND started_at=? LIMIT 1');
                $check->execute([$vehicleId,$started]);
                if ($check->fetchColumn()) return;
                $pdo->prepare(
                    "INSERT INTO sleep_sessions
                      (vehicle_id,started_at,ended_at,start_state,end_state,start_soc,end_soc,start_range_km,end_range_km,
                       start_odometer_km,end_odometer_km,duration_seconds,soc_delta,range_delta_km,drain_percent,drain_range_km,
                       drain_percent_per_day,moved_km,had_charge,quality,excluded_reason)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $vehicleId,$started,self::dateSql($row['ended_at'] ?? null),
                    $row['start_state'] ?? null,$row['end_state'] ?? null,$row['start_soc'] ?? null,$row['end_soc'] ?? null,
                    $row['start_range_km'] ?? null,$row['end_range_km'] ?? null,$row['start_odometer_km'] ?? null,
                    $row['end_odometer_km'] ?? null,$row['duration_seconds'] ?? null,$row['soc_delta'] ?? null,
                    $row['range_delta_km'] ?? null,$row['drain_percent'] ?? null,$row['drain_range_km'] ?? null,
                    $row['drain_percent_per_day'] ?? null,$row['moved_km'] ?? null,$row['had_charge'] ?? 0,
                    $row['quality'] ?? 'imported',$row['excluded_reason'] ?? null,
                ]);
                $stats['sleep_sessions']++;
            });

            self::updateProgress($pdo, $jobId, 88, 'Reisen werden zugeordnet.');
            self::streamZipJsonl($zip, 'data/journeys.jsonl', static function (array $row) use (
                $pdo,$vehicleMap,&$journeyMap,&$stats,$job
            ): void {
                $oldId=(string)($row['id'] ?? '');
                $vehicleId=$vehicleMap[(string)($row['vehicle_id'] ?? '')] ?? null;
                $title=trim((string)($row['title'] ?? ''));
                if ($oldId==='' || !$vehicleId || $title==='') return;
                $started=self::dateSql($row['started_at'] ?? null);
                $check=$pdo->prepare(
                    'SELECT id FROM journeys WHERE vehicle_id=? AND title=? AND ((started_at IS NULL AND ? IS NULL) OR started_at=?) LIMIT 1'
                );
                $check->execute([$vehicleId,$title,$started,$started]);
                $id=(int)($check->fetchColumn() ?: 0);
                if ($id<=0) {
                    $pdo->prepare(
                        "INSERT INTO journeys
                          (vehicle_id,title,type,status,destination_label,planned_start_at,planned_end_at,started_at,ended_at,
                           auto_assign,planned_distance_km,budget_amount,currency,notes,created_by)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([
                        $vehicleId,$title,$row['type'] ?? 'travel',$row['status'] ?? 'completed',
                        $row['destination_label'] ?? null,self::dateSql($row['planned_start_at'] ?? null),
                        self::dateSql($row['planned_end_at'] ?? null),$started,self::dateSql($row['ended_at'] ?? null),
                        $row['auto_assign'] ?? 1,$row['planned_distance_km'] ?? null,$row['budget_amount'] ?? null,
                        $row['currency'] ?? 'EUR',$row['notes'] ?? null,(int)($job['created_by'] ?? 0) ?: null,
                    ]);
                    $id=(int)$pdo->lastInsertId();
                    $stats['journeys']++;
                }
                $journeyMap[$oldId]=$id;
            });
            self::streamZipJsonl($zip, 'data/journey_trips.jsonl', static function (array $row) use ($pdo,$journeyMap,$tripMap): void {
                $journeyId=$journeyMap[(string)($row['journey_id'] ?? '')] ?? null;
                $tripId=$tripMap[(string)($row['trip_id'] ?? '')] ?? null;
                if (!$journeyId || !$tripId) return;
                $pdo->prepare(
                    "INSERT INTO journey_trips(journey_id,trip_id,included,assignment_source)
                     VALUES(?,?,?,'import') ON DUPLICATE KEY UPDATE included=VALUES(included)"
                )->execute([$journeyId,$tripId,$row['included'] ?? 1]);
            });
            self::streamZipJsonl($zip, 'data/journey_charges.jsonl', static function (array $row) use ($pdo,$journeyMap,$chargeMap): void {
                $journeyId=$journeyMap[(string)($row['journey_id'] ?? '')] ?? null;
                $chargeId=$chargeMap[(string)($row['charge_id'] ?? '')] ?? null;
                if (!$journeyId || !$chargeId) return;
                $pdo->prepare(
                    "INSERT INTO journey_charges(journey_id,charge_id,included,assignment_source)
                     VALUES(?,?,?,'import') ON DUPLICATE KEY UPDATE included=VALUES(included)"
                )->execute([$journeyId,$chargeId,$row['included'] ?? 1]);
            });

            self::updateProgress($pdo, $jobId, 96, 'TrakFog-Import wird finalisiert.');

            return [
                'message' => 'TrakFog-Datenimport abgeschlossen.',
                'source' => 'trakfog',
                'stats' => $stats,
                'source_version' => $manifest['trakfog_version'] ?? null,
            ];
        } finally {
            $zip->close();
        }
    }

    private static function detectSource(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $lowerName = strtolower($originalName);

        if (in_array($ext, ['bck','backup','dump','sql'], true)) {
            $copyIndex = [];
            if (self::isPgCustomDump($path)) {
                $toc = self::pgRestoreList($path);
                $tables = self::parsePgTables($toc);
                $format = 'PostgreSQL Custom Dump';
            } else {
                $copyIndex = self::parsePgSqlCopyIndex($path);
                $tables = array_keys($copyIndex);
                sort($tables);
                $format = 'PostgreSQL SQL Dump';
            }

            if (in_array('cars', $tables, true) && in_array('drives', $tables, true) && in_array('positions', $tables, true)) {
                return [
                    'source' => 'teslamate',
                    'label' => 'TeslaMate',
                    'format' => $format,
                    'tables' => $tables,
                    'copy_index' => $copyIndex,
                    'counts' => array_map(
                        static fn(array $entry): int => (int)($entry['rows'] ?? 0),
                        $copyIndex
                    ),
                    'ready' => true,
                    'summary' => 'TeslaMate-Backup erkannt. Fahrzeuge, Fahrten, Positionshistorie und Ladevorgänge können übernommen werden.',
                ];
            }
        }

        if ($ext === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                try {
                    $manifestRaw = $zip->getFromName('manifest.json');
                    if (is_string($manifestRaw)) {
                        $manifest = json_decode($manifestRaw, true);
                        if (is_array($manifest) && ($manifest['format'] ?? null) === self::EXPORT_FORMAT) {
                            return [
                                'source' => 'trakfog',
                                'label' => 'TrakFog',
                                'format' => 'TrakFog Data Export',
                                'ready' => (int)($manifest['format_version'] ?? 0) === self::EXPORT_FORMAT_VERSION,
                                'source_version' => $manifest['trakfog_version'] ?? null,
                                'tables' => array_keys((array)($manifest['tables'] ?? [])),
                                'summary' => 'Portabler TrakFog-Datenexport erkannt.',
                            ];
                        }
                    }

                    $names = [];
                    for ($i=0; $i<$zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        if (is_array($stat) && isset($stat['name'])) {
                            $names[] = strtolower((string)$stat['name']);
                        }
                    }
                    $joined = implode("\n", $names);
                    foreach ([
                        'teslafi'=>'teslafi',
                        'tessie'=>'tessie',
                        'teslascope'=>'teslascope',
                        'tronity'=>'tronity',
                        'tezlab'=>'tezlab',
                        'teslalogger'=>'teslalogger',
                    ] as $needle=>$source) {
                        if (str_contains($joined, $needle)) {
                            return self::plannedAnalysis($source, 'ZIP-Export erkannt');
                        }
                    }
                } finally {
                    $zip->close();
                }
            }
        }

        $head = '';
        $fh = @fopen($path, 'rb');
        if ($fh) {
            $head = (string)fread($fh, 131072);
            fclose($fh);
        }
        $haystack = strtolower($lowerName . "\n" . $head);
        foreach ([
            'teslalogger'=>'teslalogger',
            'teslafi'=>'teslafi',
            'tessie'=>'tessie',
            'teslascope'=>'teslascope',
            'tronity'=>'tronity',
            'tezlab'=>'tezlab',
        ] as $needle=>$source) {
            if (str_contains($haystack, $needle)) {
                return self::plannedAnalysis($source, strtoupper($ext) . '-Export erkannt');
            }
        }

        return self::plannedAnalysis('generic', strtoupper($ext ?: 'Datei') . '-Import erkannt');
    }

    private static function plannedAnalysis(string $source, string $format): array
    {
        $providers = self::providers();
        return [
            'source' => $source,
            'label' => $providers[$source]['label'] ?? $source,
            'format' => $format,
            'ready' => false,
            'summary' => 'Datei erkannt. Der Adapter ist im Migrationscenter vorbereitet und wird schrittweise freigeschaltet.',
        ];
    }

    private static array $plainCopyIndex = [];

    private static function isPgCustomDump(string $archive): bool
    {
        $fh = @fopen($archive, 'rb');
        if (!$fh) {
            return false;
        }
        $magic = (string)fread($fh, 5);
        fclose($fh);
        return $magic === 'PGDMP';
    }

    private static function parsePgSqlCopyIndex(string $archive): array
    {
        $fh = @fopen($archive, 'rb');
        if (!$fh) {
            throw new RuntimeException('Das PostgreSQL-Backup konnte nicht geöffnet werden.');
        }

        $index = [];
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if (!preg_match('/^COPY\\s+(?:(?:"?public"?)\\.)?"?([A-Za-z0-9_]+)"?\\s+\\((.+)\\)\\s+FROM\\s+stdin;$/i', $line, $m)) {
                    continue;
                }

                $table = (string)$m[1];
                $columns = array_map(
                    static fn(string $v): string => trim(trim($v), '"'),
                    explode(',', $m[2])
                );
                $index[$table] = [
                    'data_offset' => ftell($fh),
                    'columns' => $columns,
                    'rows' => 0,
                ];

                while (($dataLine = fgets($fh)) !== false) {
                    if (rtrim($dataLine, "\r\n") === '\\.') {
                        break;
                    }
                    $index[$table]['rows']++;
                }
            }
        } finally {
            fclose($fh);
        }

        ksort($index);
        return $index;
    }

    private static function pgRestoreList(string $archive): string
    {
        self::assertCommandAvailable('pg_restore');
        $proc = proc_open(
            ['pg_restore','--list',$archive],
            [1=>['pipe','w'],2=>['pipe','w']],
            $pipes
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('pg_restore konnte nicht gestartet werden.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException(
                'Dieses Custom-Format-Backup benötigt eine kompatible pg_restore-Version. '
                . 'Am einfachsten den offiziellen TeslaMate-Backupbefehl ohne -Fc verwenden. '
                . trim((string)$stderr)
            );
        }
        return (string)$stdout;
    }

    private static function parsePgTables(string $toc): array
    {
        $tables = [];
        foreach (preg_split('/\\R/', $toc) ?: [] as $line) {
            if (preg_match('/\\bTABLE DATA\\s+public\\s+([^\\s]+)\\s+/i', $line, $m)) {
                $tables[] = trim($m[1], '"');
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables);
        return $tables;
    }

    private static function streamPgTable(string $archive, string $table, callable $callback): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Ungültiger Tabellenname im TeslaMate-Import.');
        }

        if (!self::isPgCustomDump($archive)) {
            $entry = self::$plainCopyIndex[$table] ?? null;
            if (!is_array($entry) || !isset($entry['data_offset'],$entry['columns']) || !is_array($entry['columns'])) {
                return;
            }

            $fh = @fopen($archive, 'rb');
            if (!$fh) {
                throw new RuntimeException('TeslaMate-Tabelle ' . $table . ' konnte nicht gelesen werden.');
            }
            try {
                if (fseek($fh, (int)$entry['data_offset']) !== 0) {
                    throw new RuntimeException('TeslaMate-Tabelle ' . $table . ' konnte im Backup nicht angesprungen werden.');
                }
                self::streamPgCopyRows($fh, $entry['columns'], $callback);
            } finally {
                fclose($fh);
            }
            return;
        }

        $proc = proc_open(
            ['pg_restore','--data-only','--table=' . $table,'--file=-',$archive],
            [1=>['pipe','w'],2=>['pipe','w']],
            $pipes
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('TeslaMate-Tabelle ' . $table . ' konnte nicht gelesen werden.');
        }

        try {
            $columns = null;
            while (($line = fgets($pipes[1])) !== false) {
                $line = rtrim($line, "\r\n");
                if ($columns === null) {
                    if (preg_match('/^COPY\\s+(?:public\\.)?"?' . preg_quote($table, '/') . '"?\\s+\\((.+)\\)\\s+FROM\\s+stdin;$/i', $line, $m)) {
                        $columns = array_map(
                            static fn(string $v): string => trim(trim($v), '"'),
                            explode(',', $m[1])
                        );
                    }
                    continue;
                }

                if ($line === '\\.') {
                    break;
                }
                self::dispatchPgCopyRow($columns, $line, $callback);
            }
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code !== 0) {
                throw new RuntimeException(
                    'TeslaMate-Tabelle ' . $table . ' konnte nicht extrahiert werden: ' . trim((string)$stderr)
                );
            }
        } catch (Throwable $e) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @proc_terminate($proc);
            @proc_close($proc);
            throw $e;
        }
    }

    private static function streamPgCopyRows($fh, array $columns, callable $callback): void
    {
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '\\.') {
                break;
            }
            self::dispatchPgCopyRow($columns, $line, $callback);
        }
    }

    private static function dispatchPgCopyRow(array $columns, string $line, callable $callback): void
    {
        $values = explode("\t", $line);
        $row = [];
        foreach ($columns as $index => $column) {
            $raw = $values[$index] ?? '\\N';
            $row[$column] = self::decodePgCopyValue($raw);
        }
        $callback($row);
    }

    private static function decodePgCopyValue(string $value): ?string
    {
        if ($value === '\\N') {
            return null;
        }
        return preg_replace_callback(
            '/\\\\([0-7]{1,3}|.)/s',
            static function (array $m): string {
                $v = $m[1];
                if (preg_match('/^[0-7]{1,3}$/', $v)) {
                    return chr(octdec($v));
                }
                return match ($v) {
                    'b' => "\x08",
                    'f' => "\x0c",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'v' => "\x0b",
                    '\\' => '\\',
                    default => $v,
                };
            },
            $value
        );
    }

    private static function createStreamStage(PDO $pdo): void
    {
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_migration_stream');
        $pdo->exec(
            "CREATE TEMPORARY TABLE tmp_migration_stream (
                vehicle_id BIGINT UNSIGNED NOT NULL,
                recorded_at DATETIME(3) NOT NULL,
                speed_kmh DECIMAL(8,2) NULL,
                odometer_km DECIMAL(12,3) NULL,
                soc DECIMAL(5,2) NULL,
                elevation_m DECIMAL(10,2) NULL,
                est_heading DECIMAL(7,2) NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                power_kw DECIMAL(10,2) NULL,
                shift_state VARCHAR(8) NULL,
                range_km DECIMAL(10,3) NULL,
                est_range_km DECIMAL(10,3) NULL,
                heading DECIMAL(7,2) NULL,
                UNIQUE KEY uq_tmp_stream (vehicle_id,recorded_at)
            ) ENGINE=InnoDB"
        );
    }

    private static function insertStreamStageBatch(PDO $pdo, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $width = 14;
        $rowSql = '(' . implode(',', array_fill(0, $width, '?')) . ')';
        $values = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== $width) {
                throw new RuntimeException('Ungültiger Positionsdatensatz im Migrationspuffer.');
            }
            foreach ($row as $value) {
                $values[] = $value;
            }
        }
        $sql =
            'INSERT IGNORE INTO tmp_migration_stream
             (vehicle_id,recorded_at,speed_kmh,odometer_km,soc,elevation_m,est_heading,latitude,longitude,power_kw,shift_state,range_km,est_range_km,heading)
             VALUES ' . implode(',', array_fill(0, count($rows), $rowSql));
        $pdo->prepare($sql)->execute($values);
    }

    private static function flushStreamStage(PDO $pdo): int
    {
        $pdo->exec(
            "INSERT INTO vehicle_stream_samples
                (vehicle_id,recorded_at,speed_kmh,odometer_km,soc,elevation_m,est_heading,latitude,longitude,power_kw,shift_state,range_km,est_range_km,heading)
             SELECT
                t.vehicle_id,t.recorded_at,t.speed_kmh,t.odometer_km,t.soc,t.elevation_m,t.est_heading,t.latitude,t.longitude,
                t.power_kw,t.shift_state,t.range_km,t.est_range_km,t.heading
             FROM tmp_migration_stream t
             LEFT JOIN vehicle_stream_samples s
               ON s.vehicle_id=t.vehicle_id AND s.recorded_at=t.recorded_at
             WHERE s.id IS NULL"
        );
        $count = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
        $pdo->exec('TRUNCATE TABLE tmp_migration_stream');
        return max(0, (int)$count);
    }

    private static function streamZipJsonl(ZipArchive $zip, string $name, callable $callback): void
    {
        $stream = $zip->getStream($name);
        if (!$stream) {
            return;
        }
        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    throw new RuntimeException('Ungültiger Datensatz in ' . $name . '.');
                }
                $callback($row);
            }
        } finally {
            fclose($stream);
        }
    }

    private static function updateProgress(PDO $pdo, int $jobId, float $percent, string $message): void
    {
        self::updateJob($pdo, $jobId, [
            'progress_percent' => max(0, min(99, $percent)),
            'progress_message' => $message,
        ]);
    }

    private static function updateJob(PDO $pdo, int $jobId, array $values): void
    {
        $allowed = [
            'status','source_version','stored_path','result_path','progress_percent','progress_message',
            'analysis_json','result_json','error_message','started_at','completed_at',
        ];
        $sets = [];
        $params = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = $key . '=?';
            $params[] = $value;
        }
        if (!$sets) {
            return;
        }
        $params[] = $jobId;
        $pdo->prepare('UPDATE data_migration_jobs SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
    }

    private static function launchWorker(int $jobId): void
    {
        self::ensureDirectories();
        $php = PHP_BINARY ?: '/usr/local/bin/php';
        $script = dirname(__DIR__) . '/bin/data-migration-worker.php';
        $log = self::runtimeRoot() . '/logs/job-' . $jobId . '.log';
        $command = sprintf(
            'nohup %s %s %d > %s 2>&1 < /dev/null &',
            escapeshellarg($php),
            escapeshellarg($script),
            $jobId,
            escapeshellarg($log)
        );
        @shell_exec($command);
    }

    private static function runtimeRoot(): string
    {
        return '/var/lib/trakfog/migrations';
    }

    private static function ensureDirectories(): void
    {
        foreach (['incoming','exports','work','logs'] as $dir) {
            $path = self::runtimeRoot() . '/' . $dir;
            if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException('Migrationsverzeichnis konnte nicht angelegt werden.');
            }
        }
    }

    private static function insideRuntimeRoot(string $path): bool
    {
        $root = realpath(self::runtimeRoot());
        $real = realpath($path);
        return $root !== false && $real !== false && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) self::removeTree($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }

    private static function assertCommandAvailable(string $command): void
    {
        $out = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        if (!is_string($out) || trim($out) === '') {
            throw new RuntimeException($command . ' ist im TrakFog-Webcontainer nicht verfügbar.');
        }
    }

    private static function decodeJson(string $value): ?array
    {
        if (trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private static function firstText(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private static function dateSql(mixed $value, bool $milliseconds = false): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $date = $date->setTimezone(new DateTimeZone('UTC'));
            return $date->format($milliseconds ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function currentVersion(): string
    {
        $version = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));
        return $version !== '' ? $version : 'dev';
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Importdatei ist größer als das erlaubte Upload-Limit.',
            UPLOAD_ERR_PARTIAL => 'Die Importdatei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Bitte zuerst eine Importdatei auswählen.',
            UPLOAD_ERR_NO_TMP_DIR => 'Das temporäre Upload-Verzeichnis fehlt.',
            UPLOAD_ERR_CANT_WRITE => 'Die Importdatei konnte nicht auf den Datenträger geschrieben werden.',
            UPLOAD_ERR_EXTENSION => 'Eine PHP-Erweiterung hat den Upload abgebrochen.',
            default => 'Die Importdatei konnte nicht hochgeladen werden.',
        };
    }
}
