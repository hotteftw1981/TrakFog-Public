<?php

declare(strict_types=1);

/** Delete an ended trip and cascade its memberships, never the telemetry. */
final class TripDeletion
{
    public static function remove(PDO $pdo, int $tripId): array
    {
        if ($tripId <= 0) {
            throw new DomainException('Ungueltige Fahrt-ID.');
        }
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare(
                'SELECT t.id, t.vehicle_id, t.started_at, t.ended_at, t.distance_km, '
                . 'mm.merge_id FROM trips t LEFT JOIN trip_merge_members mm ON mm.trip_id=t.id '
                . 'WHERE t.id=? FOR UPDATE'
            );
            $lookup->execute([$tripId]);
            $trip = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$trip) {
                throw new DomainException('Fahrt nicht gefunden oder bereits geloescht.');
            }
            if ($trip['ended_at'] === null) {
                throw new DomainException('Eine laufende Fahrt kann nicht geloescht werden.');
            }
            $delete = $pdo->prepare('DELETE FROM trips WHERE id=? AND ended_at IS NOT NULL');
            $delete->execute([$tripId]);
            if ($delete->rowCount() !== 1) {
                throw new DomainException('Fahrt konnte nicht geloescht werden.');
            }
            // FKs cascade journey_trips and trip_merge_members. Clean up groups
            // with fewer than two trips; remaining multi-segment tours survive.
            if ($trip['merge_id'] !== null) {
                $cleanup = $pdo->prepare(
                    'DELETE FROM trip_merges WHERE id=? AND '
                    . '(SELECT COUNT(*) FROM trip_merge_members WHERE merge_id=?) < 2'
                );
                $cleanup->execute([(int)$trip['merge_id'], (int)$trip['merge_id']]);
            }
            $pdo->commit();
            return [
                'trip_id' => $tripId,
                'vehicle_id' => (int)$trip['vehicle_id'],
                'started_at' => (string)$trip['started_at'],
                'distance_km' => $trip['distance_km'] !== null ? (float)$trip['distance_km'] : null,
                'merge_id' => $trip['merge_id'] !== null ? (int)$trip['merge_id'] : null,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
