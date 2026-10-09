<?php

declare(strict_types=1);

/**
 * Keep charging energy and SoC increases out of the driving SoC balance.
 * The raw trip and charging records are preserved for later reconstruction.
 */
final class TripSoc
{
    public const CHARGE_OVERLAP_SQL = "EXISTS (
        SELECT 1 FROM charges soc_charge
        WHERE soc_charge.vehicle_id=t.vehicle_id
          AND soc_charge.started_at < COALESCE(t.ended_at,t.last_sample_at,UTC_TIMESTAMP())
          AND (soc_charge.ended_at IS NULL OR soc_charge.ended_at > t.started_at)
    )";

    public static function chargeOverlaps(array $trip): bool
    {
        return (int)($trip['soc_charge_overlap'] ?? 0) === 1;
    }

    public static function drivingDelta(?float $start, ?float $end, bool $chargeOverlaps): ?float
    {
        if ($chargeOverlaps || $start === null || $end === null) {
            return null;
        }
        return $end - $start;
    }
}
