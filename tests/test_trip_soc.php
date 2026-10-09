<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/TripSoc.php';
function checkTripSoc(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$trip = ['soc_charge_overlap' => 1, 'start_soc' => 76.0, 'end_soc' => 79.0];
checkTripSoc(TripSoc::chargeOverlaps($trip), 'charging interval overlaps stored trip');
checkTripSoc(TripSoc::drivingDelta(76.0, 79.0, true) === null, 'no +3 points attributed to driving during charging overlap');
checkTripSoc(TripSoc::drivingDelta(76.0, 74.0, false) === -2.0, 'normal driving battery drain remains visible');
checkTripSoc(TripSoc::drivingDelta(76.0, 77.0, false) === 1.0, 'plausible isolated regen increase is not hidden');
checkTripSoc(TripSoc::drivingDelta(76.0, null, false) === null, 'missing end sample never produces invented values');
checkTripSoc(!TripSoc::chargeOverlaps(['soc_charge_overlap' => 0]), 'noncharging trip unmodified');
checkTripSoc(str_contains(TripSoc::CHARGE_OVERLAP_SQL, 'soc_charge.vehicle_id=t.vehicle_id')
    && str_contains(TripSoc::CHARGE_OVERLAP_SQL, 'soc_charge.started_at < COALESCE(t.ended_at')
    && str_contains(TripSoc::CHARGE_OVERLAP_SQL, 'soc_charge.ended_at > t.started_at'),
    'charge interval overlap checks same vehicle and both time bounds');
$root=dirname(__DIR__);
foreach (['public/trips.php','public/trip.php','public/map.php'] as $view) {
    $code=file_get_contents($root . '/' . $view);
    checkTripSoc(str_contains($code, 'TripSoc::CHARGE_OVERLAP_SQL')
        && str_contains($code, 'TripSoc::chargeOverlaps'), "$view uses charge-overlap guard");
}
