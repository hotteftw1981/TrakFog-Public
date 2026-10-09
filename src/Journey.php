<?php

declare(strict_types=1);

final class Journey
{
    public static function typeLabels(): array
    {
        return [
            'travel' => 'Reise',
            'vacation' => 'Urlaub',
            'business' => 'Dienstreise',
            'roadtrip' => 'Roadtrip',
            'custom' => 'Eigene Tour',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            'planned' => 'Geplant',
            'active' => 'Läuft',
            'completed' => 'Beendet',
        ];
    }

    public static function syncAutoAssignments(PDO $pdo, int $journeyId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id,vehicle_id,status,auto_assign,started_at,ended_at
             FROM journeys
             WHERE id=?
             LIMIT 1"
        );
        $stmt->execute([$journeyId]);
        $journey = $stmt->fetch();

        if (!$journey || (int)$journey['auto_assign'] !== 1 || !in_array((string)$journey['status'], ['active','completed'], true)) {
            return ['trips' => 0, 'charges' => 0];
        }

        $start = $journey['started_at'] ?? null;
        if (!$start) {
            return ['trips' => 0, 'charges' => 0];
        }

        $end = $journey['ended_at'] ?: gmdate('Y-m-d H:i:s');
        $journeyId = (int)$journey['id'];
        $vehicleId = (int)$journey['vehicle_id'];

        $tripSql = "
            INSERT INTO journey_trips(journey_id,trip_id,included,assignment_source)
            SELECT ?,t.id,1,'auto'
            FROM trips t
            WHERE t.vehicle_id=?
              AND t.started_at>=?
              AND t.started_at<=?
              AND NOT EXISTS (
                    SELECT 1
                    FROM journey_trips existing
                    WHERE existing.journey_id=? AND existing.trip_id=t.id
              )
              AND NOT EXISTS (
                    SELECT 1
                    FROM journey_trips other_link
                    WHERE other_link.trip_id=t.id
                      AND other_link.included=1
                      AND other_link.journey_id<>?
              )
        ";
        $tripStmt = $pdo->prepare($tripSql);
        $tripStmt->execute([$journeyId,$vehicleId,$start,$end,$journeyId,$journeyId]);
        $tripCount = max(0, (int)$tripStmt->rowCount());

        $chargeSql = "
            INSERT INTO journey_charges(journey_id,charge_id,included,assignment_source)
            SELECT ?,c.id,1,'auto'
            FROM charges c
            WHERE c.vehicle_id=?
              AND c.started_at>=?
              AND c.started_at<=?
              AND NOT EXISTS (
                    SELECT 1
                    FROM journey_charges existing
                    WHERE existing.journey_id=? AND existing.charge_id=c.id
              )
              AND NOT EXISTS (
                    SELECT 1
                    FROM journey_charges other_link
                    WHERE other_link.charge_id=c.id
                      AND other_link.included=1
                      AND other_link.journey_id<>?
              )
        ";
        $chargeStmt = $pdo->prepare($chargeSql);
        $chargeStmt->execute([$journeyId,$vehicleId,$start,$end,$journeyId,$journeyId]);
        $chargeCount = max(0, (int)$chargeStmt->rowCount());

        return ['trips' => $tripCount, 'charges' => $chargeCount];
    }

    public static function metrics(PDO $pdo, int $journeyId): array
    {
        $tripStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS trip_count,
                COALESCE(SUM(t.distance_km),0) AS distance_km,
                COALESCE(SUM(t.energy_kwh),0) AS drive_energy_kwh,
                COALESCE(MAX(t.distance_km),0) AS longest_trip_km,
                COALESCE(SUM(COALESCE(t.drive_seconds,TIMESTAMPDIFF(SECOND,t.started_at,COALESCE(t.ended_at,t.last_sample_at,UTC_TIMESTAMP())))),0) AS drive_seconds,
                MIN(t.started_at) AS first_trip_at,
                MAX(COALESCE(t.ended_at,t.last_sample_at,t.started_at)) AS last_trip_at
             FROM journey_trips jt
             JOIN trips t ON t.id=jt.trip_id
             WHERE jt.journey_id=? AND jt.included=1"
        );
        $tripStmt->execute([$journeyId]);
        $trip = $tripStmt->fetch() ?: [];

        $chargeStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS charge_count,
                COALESCE(SUM(c.energy_added_kwh),0) AS charged_kwh,
                COALESCE(SUM(CASE WHEN c.cost_amount IS NOT NULL THEN c.cost_amount ELSE 0 END),0) AS charging_cost,
                SUM(CASE WHEN c.cost_amount IS NOT NULL THEN 1 ELSE 0 END) AS confirmed_cost_count,
                COALESCE(MAX(c.energy_added_kwh),0) AS biggest_charge_kwh,
                COALESCE(MAX(TIMESTAMPDIFF(SECOND,c.started_at,COALESCE(c.ended_at,c.last_sample_at,UTC_TIMESTAMP()))),0) AS longest_charge_seconds,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND,c.started_at,COALESCE(c.ended_at,c.last_sample_at,UTC_TIMESTAMP()))),0) AS charge_seconds
             FROM journey_charges jc
             JOIN charges c ON c.id=jc.charge_id
             WHERE jc.journey_id=? AND jc.included=1"
        );
        $chargeStmt->execute([$journeyId]);
        $charge = $chargeStmt->fetch() ?: [];

        $distance = (float)($trip['distance_km'] ?? 0);
        $driveEnergy = (float)($trip['drive_energy_kwh'] ?? 0);
        $cost = (float)($charge['charging_cost'] ?? 0);

        return [
            'trip_count' => (int)($trip['trip_count'] ?? 0),
            'charge_count' => (int)($charge['charge_count'] ?? 0),
            'distance_km' => $distance,
            'drive_energy_kwh' => $driveEnergy,
            'charged_kwh' => (float)($charge['charged_kwh'] ?? 0),
            'charging_cost' => $cost,
            'confirmed_cost_count' => (int)($charge['confirmed_cost_count'] ?? 0),
            'longest_trip_km' => (float)($trip['longest_trip_km'] ?? 0),
            'longest_charge_seconds' => (int)($charge['longest_charge_seconds'] ?? 0),
            'drive_seconds' => (int)($trip['drive_seconds'] ?? 0),
            'charge_seconds' => (int)($charge['charge_seconds'] ?? 0),
            'avg_wh_km' => $distance > 0.05 ? ($driveEnergy * 1000 / $distance) : null,
            'cost_per_100km' => $distance > 0.05 && (int)($charge['confirmed_cost_count'] ?? 0) > 0
                ? ($cost / $distance * 100)
                : null,
            'first_trip_at' => $trip['first_trip_at'] ?? null,
            'last_trip_at' => $trip['last_trip_at'] ?? null,
        ];
    }

    public static function candidateWindow(array $journey): array
    {
        $start = $journey['started_at']
            ?: ($journey['planned_start_at'] ?? null);

        $end = $journey['ended_at']
            ?: (($journey['status'] ?? '') === 'active'
                ? gmdate('Y-m-d H:i:s')
                : ($journey['planned_end_at'] ?? null));

        return [$start, $end];
    }

    public static function setTrip(PDO $pdo, int $journeyId, int $tripId, bool $included): void
    {
        if ($included) {
            $pdo->prepare(
                "UPDATE journey_trips
                 SET included=0,assignment_source='manual'
                 WHERE trip_id=? AND journey_id<>? AND included=1"
            )->execute([$tripId,$journeyId]);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO journey_trips(journey_id,trip_id,included,assignment_source)
             VALUES(?,?,?,'manual')
             ON DUPLICATE KEY UPDATE included=VALUES(included),assignment_source='manual'"
        );
        $stmt->execute([$journeyId,$tripId,$included ? 1 : 0]);
    }

    public static function setCharge(PDO $pdo, int $journeyId, int $chargeId, bool $included): void
    {
        if ($included) {
            $pdo->prepare(
                "UPDATE journey_charges
                 SET included=0,assignment_source='manual'
                 WHERE charge_id=? AND journey_id<>? AND included=1"
            )->execute([$chargeId,$journeyId]);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO journey_charges(journey_id,charge_id,included,assignment_source)
             VALUES(?,?,?,'manual')
             ON DUPLICATE KEY UPDATE included=VALUES(included),assignment_source='manual'"
        );
        $stmt->execute([$journeyId,$chargeId,$included ? 1 : 0]);
    }
}
