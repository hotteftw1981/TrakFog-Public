<?php

declare(strict_types=1);

/** Read-only comparison of vehicle energy and an explicitly assigned meter reading. */
final class ChargeEnergyComparison
{
    public static function calculate(mixed $vehicleKwh, mixed $meterKwh): ?array
    {
        if (!is_numeric($vehicleKwh) || !is_numeric($meterKwh)) return null;
        $vehicle = (float)$vehicleKwh;
        $meter = (float)$meterKwh;
        if (!is_finite($vehicle) || !is_finite($meter) || $vehicle < 0 || $meter < 0) return null;
        $delta = abs($meter - $vehicle);
        $percent = $meter > 0 ? $delta / $meter * 100 : null;
        $max = max($vehicle, $meter, 0.001);
        return [
            'vehicle_kwh' => $vehicle,
            'meter_kwh' => $meter,
            'difference_kwh' => $delta,
            'difference_pct_meter' => $percent,
            'vehicle_share' => min(100.0, max(0.0, $vehicle / $max * 100)),
            'meter_share' => min(100.0, max(0.0, $meter / $max * 100)),
            'meter_exceeds_vehicle' => $meter >= $vehicle,
        ];
    }
}
