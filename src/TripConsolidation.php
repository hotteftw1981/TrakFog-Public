<?php

declare(strict_types=1);

/** Replace consecutive, completed drives by one real trips row. */
final class TripConsolidation
{
    public const MAX_TRIPS = 24;
    public const MAX_GAP_SECONDS = 7200;
    public const MAX_STOP_DISTANCE_METERS = 2000.0;

    /** Pure validation, also used in regression tests. */
    public static function validate(array $rows, array $betweenIds): void
    {
        if (count($rows) < 2 || count($rows) > self::MAX_TRIPS) {
            throw new DomainException('Bitte mindestens zwei und hoechstens 24 Fahrten markieren.');
        }
        $vehicleId = (int)$rows[0]['vehicle_id'];
        if ($vehicleId < 1) throw new DomainException('Unbekanntes Fahrzeug.');
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        foreach ($rows as $row) {
            if ((int)$row['vehicle_id'] !== $vehicleId) {
                throw new DomainException('Nur Fahrten desselben Teslas zusammenfuehren.');
            }
        }
        if ($ids !== array_values($betweenIds)) {
            throw new DomainException('Bitte alle dazwischenliegenden Fahrten ebenfalls markieren.');
        }
        foreach ($rows as $i => $row) {
            if ((int)$row['vehicle_id'] !== $vehicleId) {
                throw new DomainException('Nur Fahrten desselben Teslas zusammenfuehren.');
            }
            if (empty($row['ended_at'])) {
                throw new DomainException('Bitte warten, bis beide Fahrten abgeschlossen sind.');
            }
            if ($i === 0) continue;
            $before = $rows[$i - 1];
            $gap = strtotime($row['started_at'].' UTC') - strtotime($before['ended_at'].' UTC');
            if ($gap < -120 || $gap > self::MAX_GAP_SECONDS) {
                throw new DomainException('Die Unterbrechung ist zu lang oder die Fahrten ueberlappen (maximal 2 Stunden).');
            }
            $meters = TripMerge::metersBetween($before, $row);
            if ($meters !== null && $meters > self::MAX_STOP_DISTANCE_METERS) {
                throw new DomainException('Ziel und naechster Start liegen mehr als 2 km auseinander. Bitte Fahrten pruefen.');
            }
        }
    }

    public static function durationSeconds(array $row): int
    {
        if ($row['drive_seconds'] !== null && is_numeric($row['drive_seconds'])) {
            return max(0, (int)$row['drive_seconds']);
        }
        $start = strtotime((string)$row['started_at'].' UTC');
        $end = strtotime((string)$row['ended_at'].' UTC');
        return $start !== false && $end !== false ? max(0, $end - $start) : 0;
    }

    private static function totalOrNull(array $rows, string $column): ?float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_numeric($row[$column] ?? null)) return null;
            $sum += (float)$row[$column];
        }
        return $sum;
    }

    /** Returns the id of the ONE remaining original trips row. */
    public static function combine(PDO $pdo, array $tripIds, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tripIds), fn(int $id): bool => $id > 0)));
        if (count($ids) < 2 || count($ids) > self::MAX_TRIPS) {
            throw new DomainException('Bitte zwei bis 24 Fahrten markieren.');
        }
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare('SELECT * FROM trips WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY started_at,id FOR UPDATE');
            $lookup->execute($ids);
            $rows = $lookup->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new DomainException('Eine der Fahrten wurde inzwischen entfernt.');
            $vehicleId = (int)$rows[0]['vehicle_id'];
            // Serialize destructive actions per vehicle.
            $lock = $pdo->prepare('SELECT id FROM vehicles WHERE id=? FOR UPDATE');
            $lock->execute([$vehicleId]);
            if (!$lock->fetchColumn()) throw new DomainException('Tesla nicht gefunden.');
            $first = $rows[0]; $last = $rows[count($rows) - 1];
            $all = $pdo->prepare('SELECT id FROM trips WHERE vehicle_id=? AND (started_at>? OR (started_at=? AND id>=?)) AND (started_at<? OR (started_at=? AND id<=?)) ORDER BY started_at,id');
            $all->execute([$vehicleId,$first['started_at'],$first['started_at'],$first['id'],$last['started_at'],$last['started_at'],$last['id']]);
            $allIds = array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN));
            self::validate($rows, $allIds);

            // Two trips attached to different journeys must not silently blend those journeys.
            $journeys = $pdo->prepare('SELECT journey_id FROM journey_trips WHERE included=1 AND trip_id IN ('.implode(',',array_fill(0,count($ids),'?')).') FOR UPDATE');
            $journeys->execute($ids);
            if (count(array_unique($journeys->fetchAll(PDO::FETCH_COLUMN))) > 1) {
                throw new DomainException('Die Fahrten gehoeren zu unterschiedlichen Reisen. Bitte Zuordnung zuerst pruefen.');
            }

            $distance = self::totalOrNull($rows, 'distance_km');
            $energy = self::totalOrNull($rows, 'energy_kwh');
            $average = $distance !== null && $distance > 0 && $energy !== null ? round($energy * 1000 / $distance, 2) : null;
            $speeds = array_values(array_filter(array_map(static fn(array $r) => is_numeric($r['max_speed_kmh'] ?? null) ? (float)$r['max_speed_kmh'] : null,$rows),static fn($v)=>$v !== null));
            $maxSpeed = $speeds ? max($speeds) : null;
            $driveSeconds = array_sum(array_map([self::class, 'durationSeconds'], $rows));
            $samples = array_sum(array_map(static fn(array $r): int => max(0,(int)($r['sample_count'] ?? 0)), $rows));
            $keepId = (int)$first['id'];
            $removeIds = array_values(array_filter($ids, fn(int $id): bool => $id !== $keepId));

            // Keep a private, complete audit snapshot before deleting any source records.
            $audit = $pdo->prepare('INSERT INTO trip_consolidations(surviving_trip_id,vehicle_id,original_trip_ids_json,originals_json,merged_by) VALUES(?,?,?,?,?)');
            $audit->execute([$keepId,$vehicleId,json_encode(array_map('intval',array_column($rows,'id')),JSON_THROW_ON_ERROR),json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$userId]);

            // Transfer journey links so deleting secondary trips does not lose trip associations.
            $links = $pdo->prepare('SELECT journey_id,included,assignment_source FROM journey_trips WHERE trip_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
            $links->execute($ids);
            $insertLink = $pdo->prepare('INSERT INTO journey_trips(journey_id,trip_id,included,assignment_source) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE included=GREATEST(included,VALUES(included))');
            foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $link) {
                $insertLink->execute([(int)$link['journey_id'],$keepId,(int)$link['included'],(string)$link['assignment_source']]);
            }

            // Retire any legacy V0.1.1.60 virtual groups referencing these trips.
            $legacy = $pdo->prepare('SELECT DISTINCT merge_id FROM trip_merge_members WHERE trip_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
            $legacy->execute($ids);
            $oldGroups = array_map('intval', $legacy->fetchAll(PDO::FETCH_COLUMN));
            if ($oldGroups) {
                $drop = $pdo->prepare('DELETE FROM trip_merges WHERE id IN ('.implode(',',array_fill(0,count($oldGroups),'?')).')');
                $drop->execute($oldGroups);
            }

            $update = $pdo->prepare('UPDATE trips SET ended_at=?,end_latitude=?,end_longitude=?,end_odometer_km=?,end_soc=?,end_range_km=?,last_sample_at=?,distance_km=?,energy_kwh=?,avg_wh_km=?,max_speed_kmh=?,sample_count=?,drive_seconds=? WHERE id=? AND ended_at IS NOT NULL');
            $update->execute([$last['ended_at'],$last['end_latitude'],$last['end_longitude'],$last['end_odometer_km'],$last['end_soc'],$last['end_range_km'],$last['last_sample_at'],$distance,$energy,$average,$maxSpeed,$samples,$driveSeconds,$keepId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Speichern fehlgeschlagen. Es wurde nichts zusammengefuehrt.');
            $delete = $pdo->prepare('DELETE FROM trips WHERE id IN ('.implode(',',array_fill(0,count($removeIds),'?')).') AND ended_at IS NOT NULL');
            $delete->execute($removeIds);
            if ($delete->rowCount() !== count($removeIds)) throw new RuntimeException('Zusammenfuehrung unvollstaendig; alle Aenderungen wurden verworfen.');
            $pdo->commit();
            return $keepId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
