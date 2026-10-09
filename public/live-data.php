<?php
require dirname(__DIR__) . '/src/bootstrap.php';

if (!LiveViewAuth::authorized($pdo)) {
    json_response(['ok' => false, 'error' => 'liveview_auth_required'], 401);
}

$requestedVehicleId = max(0, (int)($_GET['vehicle_id'] ?? 0));
$defaultVehicleId = max(0, (int)setting($pdo, 'liveview_default_vehicle_id', '0'));

$vehicleRows = $pdo->query(
    "SELECT id,display_name,state,battery_level,rated_range_km,last_seen_at,vin,latitude,longitude,heading,speed_kmh,model_name
     FROM vehicles
     ORDER BY
       CASE WHEN state='online' THEN 0 WHEN state IN ('asleep','offline') THEN 2 ELSE 1 END,
       COALESCE(last_seen_at,'1970-01-01') DESC,
       display_name,id"
)->fetchAll();

$vehicleIds = array_map(static fn(array $row): int => (int)$row['id'], $vehicleRows);
$vehicleId = 0;
if ($requestedVehicleId > 0 && in_array($requestedVehicleId, $vehicleIds, true)) {
    $vehicleId = $requestedVehicleId;
} elseif ($defaultVehicleId > 0 && in_array($defaultVehicleId, $vehicleIds, true)) {
    $vehicleId = $defaultVehicleId;
} elseif ($vehicleIds) {
    $vehicleId = $vehicleIds[0];
}

if ($vehicleId <= 0) {
    json_response([
        'ok' => true,
        'version' => app_version(),
        'server_at' => gmdate(DATE_ATOM),
        'vehicles' => [],
        'vehicle' => null,
        'trip' => null,
        'charge' => null,
        'journey' => null,
        'route' => ['points'=>[], 'segments'=>[], 'stops'=>[]],
        'merge_suggestion' => null,
        'merge_preview' => null,
        'can_merge' => Auth::check(),
        'tesla_nerd' => null,
    ]);
}

$vehicleStmt = $pdo->prepare(
    "SELECT *
     FROM vehicles
     WHERE id=?
     LIMIT 1"
);
$vehicleStmt->execute([$vehicleId]);
$vehicle = $vehicleStmt->fetch();

if (!$vehicle) {
    json_response(['ok' => false, 'error' => 'vehicle_not_found'], 404);
}

$streamStmt = $pdo->prepare(
    "SELECT *
     FROM vehicle_stream_samples
     WHERE vehicle_id=?
     ORDER BY recorded_at DESC,id DESC
     LIMIT 1"
);
$streamStmt->execute([$vehicleId]);
$stream = $streamStmt->fetch() ?: null;

$streamStatusStmt = $pdo->prepare(
    "SELECT status,last_event_at,last_error
     FROM vehicle_stream_status
     WHERE vehicle_id=?
     LIMIT 1"
);
$streamStatusStmt->execute([$vehicleId]);
$streamStatus = $streamStatusStmt->fetch() ?: null;

$snapshotStmt = $pdo->prepare(
    "SELECT *
     FROM vehicle_snapshots
     WHERE vehicle_id=?
     ORDER BY recorded_at DESC,id DESC
     LIMIT 1"
);
$snapshotStmt->execute([$vehicleId]);
$snapshot = $snapshotStmt->fetch() ?: null;

$tripStmt = $pdo->prepare(
    "SELECT *
     FROM trips
     WHERE vehicle_id=? AND ended_at IS NULL
     ORDER BY id DESC
     LIMIT 1"
);
$tripStmt->execute([$vehicleId]);
$activeTrip = $tripStmt->fetch() ?: null;

$recentTripStmt = $pdo->prepare(
    "SELECT *
     FROM trips
     WHERE vehicle_id=?
     ORDER BY started_at DESC,id DESC
     LIMIT 1"
);
$recentTripStmt->execute([$vehicleId]);
$recentTrip = $recentTripStmt->fetch() ?: null;

$chargeStmt = $pdo->prepare(
    "SELECT *
     FROM charges
     WHERE vehicle_id=? AND ended_at IS NULL
     ORDER BY id DESC
     LIMIT 1"
);
$chargeStmt->execute([$vehicleId]);
$activeCharge = $chargeStmt->fetch() ?: null;

$journeyStmt = $pdo->prepare(
    "SELECT *
     FROM journeys
     WHERE vehicle_id=? AND status='active'
     ORDER BY started_at DESC,id DESC
     LIMIT 1"
);
$journeyStmt->execute([$vehicleId]);
$activeJourney = $journeyStmt->fetch() ?: null;

$streamTime = $stream['recorded_at'] ?? null;
$streamTs = $streamTime ? strtotime((string)$streamTime . ' UTC') : false;
$streamFresh = $streamTs !== false && (time() - $streamTs) <= 120;

$snapshotTime = $snapshot['recorded_at'] ?? null;
$snapshotTs = $snapshotTime ? strtotime((string)$snapshotTime . ' UTC') : false;
$snapshotFresh = $snapshotTs !== false && (time() - $snapshotTs) <= 180;

$speed = $streamFresh && is_numeric($stream['speed_kmh'] ?? null)
    ? max(0.0, (float)$stream['speed_kmh'])
    : 0.0;
$shift = $streamFresh ? trim((string)($stream['shift_state'] ?? '')) : '';
$power = $streamFresh && is_numeric($stream['power_kw'] ?? null)
    ? (float)$stream['power_kw']
    : ($snapshotFresh && is_numeric($snapshot['power_kw'] ?? null) ? (float)$snapshot['power_kw'] : null);
$battery = is_numeric($stream['soc'] ?? null) && $streamFresh
    ? (float)$stream['soc']
    : (is_numeric($vehicle['battery_level'] ?? null) ? (float)$vehicle['battery_level'] : null);
$range = is_numeric($stream['range_km'] ?? null) && $streamFresh
    ? (float)$stream['range_km']
    : (is_numeric($vehicle['rated_range_km'] ?? null) ? (float)$vehicle['rated_range_km'] : null);
$latitude = $streamFresh && is_numeric($stream['latitude'] ?? null)
    ? (float)$stream['latitude']
    : (is_numeric($vehicle['latitude'] ?? null) ? (float)$vehicle['latitude'] : null);
$longitude = $streamFresh && is_numeric($stream['longitude'] ?? null)
    ? (float)$stream['longitude']
    : (is_numeric($vehicle['longitude'] ?? null) ? (float)$vehicle['longitude'] : null);
$heading = $streamFresh && is_numeric($stream['heading'] ?? null)
    ? (float)$stream['heading']
    : (is_numeric($vehicle['heading'] ?? null) ? (float)$vehicle['heading'] : null);

$snapshotRaw = [];
if (is_string($snapshot['raw_json'] ?? null) && $snapshot['raw_json'] !== '') {
    $decoded = json_decode((string)$snapshot['raw_json'], true);
    if (is_array($decoded)) {
        $snapshotRaw = $decoded;
    }
} elseif (is_array($snapshot['raw_json'] ?? null)) {
    $snapshotRaw = $snapshot['raw_json'];
}
$climateRaw = is_array($snapshotRaw['climate_state'] ?? null) ? $snapshotRaw['climate_state'] : [];
$vehicleRaw = is_array($snapshotRaw['vehicle_state'] ?? null) ? $snapshotRaw['vehicle_state'] : [];
$chargeRaw = is_array($snapshotRaw['charge_state'] ?? null) ? $snapshotRaw['charge_state'] : [];
$driveRaw = is_array($snapshotRaw['drive_state'] ?? null) ? $snapshotRaw['drive_state'] : [];
$configRaw = is_array($snapshotRaw['vehicle_config'] ?? null) ? $snapshotRaw['vehicle_config'] : [];
$detailRaw = [];
if (is_string($vehicle['detail_raw_json'] ?? null) && trim((string)$vehicle['detail_raw_json']) !== '') {
    $decodedDetail = json_decode((string)$vehicle['detail_raw_json'], true);
    $detailRaw = is_array($decodedDetail) ? $decodedDetail : [];
}
$detailConfig = is_array($detailRaw['vehicle_config'] ?? null) ? $detailRaw['vehicle_config'] : [];
// Reuse the stored Tesla model identifier from the main dashboard; do not infer
// a car family from a nickname and never ask the sleeping car for more data.
$liveCarType = trim((string)($configRaw['car_type'] ?? ''));
if ($liveCarType === '') $liveCarType = trim((string)($detailConfig['car_type'] ?? ''));
if ($liveCarType === '') $liveCarType = trim((string)($vehicle['model_name'] ?? ''));
$carArt = TeslaVehicleArt::resolve(
    $liveCarType,(string)($vehicle['model_name'] ?? ''),(string)($vehicle['vin'] ?? ''),
    TeslaVehicleArt::override($pdo,$vehicleId)
);
$softwareUpdateRaw = is_array($vehicleRaw['software_update'] ?? null) ? $vehicleRaw['software_update'] : [];
$softwareUpdateStatus = strtolower(trim((string)($softwareUpdateRaw['status'] ?? '')));
$softwareUpdateVersion = trim((string)($softwareUpdateRaw['version'] ?? ''));
$softwareUpdateAvailable = !in_array($softwareUpdateStatus, ['', 'idle', 'none', 'unknown'], true) || $softwareUpdateVersion !== '';
$routeDestination = trim((string)($driveRaw['active_route_destination'] ?? ''));
$routeMilesToArrival = is_numeric($driveRaw['active_route_miles_to_arrival'] ?? null) ? (float)$driveRaw['active_route_miles_to_arrival'] : null;
$routeKmToArrival = $routeMilesToArrival !== null ? round($routeMilesToArrival * 1.609344, 1) : null;
$routeMinutesToArrival = is_numeric($driveRaw['active_route_minutes_to_arrival'] ?? null) ? (int)$driveRaw['active_route_minutes_to_arrival'] : null;
$routeTrafficDelay = is_numeric($driveRaw['active_route_traffic_minutes_delay'] ?? null) ? (int)$driveRaw['active_route_traffic_minutes_delay'] : null;

$latestOdometer = is_numeric($stream['odometer_km'] ?? null)
    ? (float)$stream['odometer_km']
    : (is_numeric($snapshot['odometer_km'] ?? null)
        ? (float)$snapshot['odometer_km']
        : (is_numeric($vehicle['odometer_km'] ?? null) ? (float)$vehicle['odometer_km'] : null));
$estimatedRange = is_numeric($stream['est_range_km'] ?? null)
    ? (float)$stream['est_range_km']
    : (is_numeric($vehicle['ideal_range_km'] ?? null) ? (float)$vehicle['ideal_range_km'] : null);
$elevation = is_numeric($stream['elevation_m'] ?? null) ? (float)$stream['elevation_m'] : null;
$estimatedHeading = is_numeric($stream['est_heading'] ?? null) ? (float)$stream['est_heading'] : null;
$insideTemp = is_numeric($climateRaw['inside_temp'] ?? null) ? (float)$climateRaw['inside_temp'] : null;
$outsideTemp = is_numeric($climateRaw['outside_temp'] ?? null) ? (float)$climateRaw['outside_temp'] : null;
$driverTemp = is_numeric($climateRaw['driver_temp_setting'] ?? null) ? (float)$climateRaw['driver_temp_setting'] : null;
$passengerTemp = is_numeric($climateRaw['passenger_temp_setting'] ?? null) ? (float)$climateRaw['passenger_temp_setting'] : null;
$fanStatus = is_numeric($climateRaw['fan_status'] ?? null) ? (int)$climateRaw['fan_status'] : null;
$climateOn = array_key_exists('is_climate_on', $climateRaw) ? (bool)$climateRaw['is_climate_on'] : null;

$locked = array_key_exists('locked', $vehicleRaw) ? (bool)$vehicleRaw['locked'] : null;
$sentryMode = array_key_exists('sentry_mode', $vehicleRaw) ? (bool)$vehicleRaw['sentry_mode'] : null;
$carVersion = trim((string)($vehicleRaw['car_version'] ?? ''));
$chargePortOpen = array_key_exists('charge_port_door_open', $chargeRaw) ? (bool)$chargeRaw['charge_port_door_open'] : null;
$chargePortLatch = trim((string)($chargeRaw['charge_port_latch'] ?? ''));
$usableBattery = is_numeric($snapshot['usable_battery_level'] ?? null)
    ? (float)$snapshot['usable_battery_level']
    : (is_numeric($vehicle['usable_battery_level'] ?? null) ? (float)$vehicle['usable_battery_level'] : null);
$chargerPower = is_numeric($chargeRaw['charger_power'] ?? null) ? (float)$chargeRaw['charger_power'] : null;
$chargerVoltage = is_numeric($chargeRaw['charger_voltage'] ?? null) ? (float)$chargeRaw['charger_voltage'] : null;
$chargerCurrent = is_numeric($chargeRaw['charger_actual_current'] ?? null) ? (float)$chargeRaw['charger_actual_current'] : null;
$chargeEnergyAdded = is_numeric($chargeRaw['charge_energy_added'] ?? null) ? (float)$chargeRaw['charge_energy_added'] : null;
$timeToFullCharge = is_numeric($chargeRaw['time_to_full_charge'] ?? null) ? (float)$chargeRaw['time_to_full_charge'] : null;

$doorKeys = ['df','dr','pf','pr','fd','rd'];
$openPanels = [];
$panelLabels = [
    'df' => 'Tür VL',
    'dr' => 'Tür HL',
    'pf' => 'Tür VR',
    'pr' => 'Tür HR',
    'fd' => 'Frunk',
    'rd' => 'Kofferraum',
];
foreach ($doorKeys as $doorKey) {
    if (isset($vehicleRaw[$doorKey]) && (int)$vehicleRaw[$doorKey] !== 0) {
        $openPanels[] = $panelLabels[$doorKey];
    }
}

$tirePressure = [];
foreach ([
    'fl' => 'tpms_pressure_fl',
    'fr' => 'tpms_pressure_fr',
    'rl' => 'tpms_pressure_rl',
    'rr' => 'tpms_pressure_rr',
] as $position => $key) {
    $tirePressure[$position] = is_numeric($vehicleRaw[$key] ?? null)
        ? (float)$vehicleRaw[$key]
        : null;
}

$locations = Geo::loadLocations($pdo);
$geofences = Geo::loadGeofences($pdo, true);
$currentPlace = Geo::label($latitude, $longitude, $locations, $geofences);

$currentGeoKey = Geo::key($latitude, $longitude);
$currentGeo = $currentGeoKey !== null ? ($locations[$currentGeoKey] ?? null) : null;
if (!$currentGeo && is_numeric($latitude) && is_numeric($longitude)) {
    $currentGeo = Geo::nearestResolvedLocation($pdo, $latitude, $longitude, 180.0);
}
$currentRoadType = Geo::roadType($currentGeo);
$currentStreet = trim(
    trim((string)($currentGeo['road'] ?? '')) . ' ' .
    trim((string)($currentGeo['house_number'] ?? ''))
);
$currentCityLine = trim(
    trim((string)($currentGeo['postcode'] ?? '')) . ' ' .
    trim((string)($currentGeo['city'] ?? ''))
);
$currentAddressShort = implode(', ', array_values(array_filter([
    $currentStreet !== '' ? $currentStreet : null,
    $currentCityLine !== '' ? $currentCityLine : null,
])));

$tripPayload = null;
if ($activeTrip) {
    $startedTs = strtotime((string)$activeTrip['started_at'] . ' UTC') ?: time();
    $tripPayload = [
        'id' => (int)$activeTrip['id'],
        'started_at' => (string)$activeTrip['started_at'],
        'duration_seconds' => max(0, time() - $startedTs),
        'distance_km' => is_numeric($activeTrip['distance_km'] ?? null) ? (float)$activeTrip['distance_km'] : 0.0,
        'energy_kwh' => is_numeric($activeTrip['energy_kwh'] ?? null) ? (float)$activeTrip['energy_kwh'] : null,
        'avg_wh_km' => is_numeric($activeTrip['avg_wh_km'] ?? null) ? (float)$activeTrip['avg_wh_km'] : null,
        'max_speed_kmh' => is_numeric($activeTrip['max_speed_kmh'] ?? null) ? (float)$activeTrip['max_speed_kmh'] : null,
        'start_soc' => is_numeric($activeTrip['start_soc'] ?? null) ? (float)$activeTrip['start_soc'] : null,
        'start_range_km' => is_numeric($activeTrip['start_range_km'] ?? null) ? (float)$activeTrip['start_range_km'] : null,
    ];
}

$chargePayload = null;
if ($activeCharge) {
    $startedTs = strtotime((string)$activeCharge['started_at'] . ' UTC') ?: time();
    $chargePlace = Geo::label(
        $activeCharge['latitude'] ?? null,
        $activeCharge['longitude'] ?? null,
        $locations,
        $geofences,
        (string)($activeCharge['location_name'] ?? '')
    );
    $chargePayload = [
        'id' => (int)$activeCharge['id'],
        'started_at' => (string)$activeCharge['started_at'],
        'duration_seconds' => max(0, time() - $startedTs),
        'energy_kwh' => is_numeric($activeCharge['energy_added_kwh'] ?? null) ? (float)$activeCharge['energy_added_kwh'] : null,
        'start_soc' => is_numeric($activeCharge['start_battery_percent'] ?? null) ? (float)$activeCharge['start_battery_percent'] : null,
        'end_soc' => is_numeric($activeCharge['end_battery_percent'] ?? null) ? (float)$activeCharge['end_battery_percent'] : null,
        'max_power_kw' => is_numeric($activeCharge['max_power_kw'] ?? null) ? (float)$activeCharge['max_power_kw'] : null,
        'current_power_kw' => $snapshotFresh && is_numeric($snapshot['power_kw'] ?? null) ? (float)$snapshot['power_kw'] : $power,
        'cost_amount' => is_numeric($activeCharge['cost_amount'] ?? null) ? (float)$activeCharge['cost_amount'] : null,
        'currency' => (string)($activeCharge['cost_currency'] ?: 'EUR'),
        'location' => $chargePlace['label'],
    ];
}

$journeyPayload = null;
if ($activeJourney) {
    $metrics = Journey::metrics($pdo, (int)$activeJourney['id']);
    $startedTs = $activeJourney['started_at']
        ? (strtotime((string)$activeJourney['started_at'] . ' UTC') ?: time())
        : time();
    $journeyPayload = [
        'id' => (int)$activeJourney['id'],
        'title' => (string)$activeJourney['title'],
        'type' => (string)$activeJourney['type'],
        'destination' => (string)($activeJourney['destination_label'] ?? ''),
        'started_at' => $activeJourney['started_at'],
        'duration_seconds' => max(0, time() - $startedTs),
        'distance_km' => (float)$metrics['distance_km'],
        'trip_count' => (int)$metrics['trip_count'],
        'charge_count' => (int)$metrics['charge_count'],
        'charged_kwh' => (float)$metrics['charged_kwh'],
        'charging_cost' => (float)$metrics['charging_cost'],
        'confirmed_cost_count' => (int)$metrics['confirmed_cost_count'],
        'drive_seconds' => (int)$metrics['drive_seconds'],
        'charge_seconds' => (int)$metrics['charge_seconds'],
        'cost_per_100km' => $metrics['cost_per_100km'],
        'budget_amount' => is_numeric($activeJourney['budget_amount'] ?? null) ? (float)$activeJourney['budget_amount'] : null,
    ];
}

$routeTrip = $activeTrip ?: $recentTrip;
$route = [];
$routeTripId = null;
$routeActive = false;
if ($routeTrip) {
    $routeTripId = (int)$routeTrip['id'];
    $routeActive = $activeTrip && (int)$activeTrip['id'] === $routeTripId;
    // Keep enough geometry for long live drives. The LiveView no longer fits
    // the route into the viewport automatically, so the driver can zoom out
    // manually and still see a useful complete red trail.
    $route = Geo::routePoints($pdo, [$routeTripId], 1200)[$routeTripId] ?? [];

    if (!$route && is_numeric($routeTrip['start_latitude'] ?? null) && is_numeric($routeTrip['start_longitude'] ?? null)) {
        $route[] = [
            'lat' => (float)$routeTrip['start_latitude'],
            'lon' => (float)$routeTrip['start_longitude'],
            'at' => (string)$routeTrip['started_at'],
        ];
    }

    if (is_numeric($routeTrip['end_latitude'] ?? null) && is_numeric($routeTrip['end_longitude'] ?? null)) {
        $endLat = (float)$routeTrip['end_latitude'];
        $endLon = (float)$routeTrip['end_longitude'];
        $last = $route ? $route[count($route)-1] : null;
        if (!$last || abs((float)$last['lat']-$endLat)>0.00001 || abs((float)$last['lon']-$endLon)>0.00001) {
            $route[] = [
                'lat' => $endLat,
                'lon' => $endLon,
                'at' => (string)($routeTrip['ended_at'] ?? $routeTrip['last_sample_at'] ?? ''),
            ];
        }
    }
}

// A merged drive traces all original segments without inserting fake GPS points.
$routeSegments = $route ? [$route] : [];
$routeStops = [];
$routeMergeId = null;
$mergeSuggestion = null;
$mergePreview = null;
if ($routeTripId) {
    $routeMergeId = TripMerge::groupId($pdo, $routeTripId);
    if ($routeMergeId !== null) {
        $geometry = TripMerge::geometry($pdo, TripMerge::members($pdo, $routeMergeId), 1800);
        $route = $geometry['points'];
        $routeSegments = $geometry['segments'];
        $routeStops = $geometry['stops'];
    } else {
        $routeSegments = $route ? [$route] : [];
    }
    $mergeSuggestion = TripMerge::suggestionFor($pdo, $vehicleId, $routeTripId);
    if($mergeSuggestion){
        $previousId=(int)$mergeSuggestion['previous_trip_id'];
        $previewRows=$pdo->prepare('SELECT * FROM trips WHERE id=? AND vehicle_id=? LIMIT 1');
        $previewRows->execute([$previousId,$vehicleId]);
        $previewTrip=$previewRows->fetch();
        if($previewTrip){
            $previewGeometry=TripMerge::geometry($pdo,[$previewTrip],500);
            $mergePreview=[
                'previous_points'=>$previewGeometry['points'],
                'stop'=>[ 'lat'=>is_numeric($previewTrip['end_latitude']??null)?(float)$previewTrip['end_latitude']:null,
                    'lon'=>is_numeric($previewTrip['end_longitude']??null)?(float)$previewTrip['end_longitude']:null,
                    'gap_minutes'=>(int)$mergeSuggestion['gap_minutes'] ],
            ];
        }
    }
}

$state = strtolower((string)($vehicle['state'] ?? 'unknown'));
$lastDataAt = $streamFresh
    ? $streamTime
    : ($snapshotTime ?: ($vehicle['last_seen_at'] ?? null));
$lastDataTs = $lastDataAt ? strtotime((string)$lastDataAt . ' UTC') : false;
$dataAgeSeconds = $lastDataTs !== false ? max(0, time() - $lastDataTs) : null;
$streamRuntimeState = strtolower((string)($streamStatus['status'] ?? ''));
$streamSleepSignal = in_array($streamRuntimeState, ['offline','waiting_vehicle'], true);

$mode = $activeCharge
    ? 'charging'
    : (($streamFresh && (in_array(strtoupper($shift), ['D','R'], true) || $speed >= 2)) ? 'driving'
        : ((in_array($state, ['asleep','offline'], true) || ($streamSleepSignal && ($dataAgeSeconds === null || $dataAgeSeconds > 120)))
            ? 'sleeping'
            : (($dataAgeSeconds !== null && $dataAgeSeconds > 300) ? 'stale' : 'parked')));

$effectiveState = $mode === 'stale'
    ? 'stale'
    : ($mode === 'sleeping' ? 'asleep' : $state);

$vehiclesPayload = array_map(
    static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)($row['display_name'] ?: 'Tesla'),
        'state' => (string)($row['state'] ?? 'unknown'),
        'battery' => is_numeric($row['battery_level'] ?? null) ? (float)$row['battery_level'] : null,
        'range_km' => is_numeric($row['rated_range_km'] ?? null) ? (float)$row['rated_range_km'] : null,
        'last_seen_at' => $row['last_seen_at'],
        'latitude' => is_numeric($row['latitude'] ?? null) ? (float)$row['latitude'] : null,
        'longitude' => is_numeric($row['longitude'] ?? null) ? (float)$row['longitude'] : null,
        'heading' => is_numeric($row['heading'] ?? null) ? (float)$row['heading'] : 0,
        'speed_kmh' => is_numeric($row['speed_kmh'] ?? null) ? (float)$row['speed_kmh'] : 0,
        'model' => (string)($row['model_name'] ?? ''),
    ],
    $vehicleRows
);

json_response([
    'ok' => true,
    'version' => app_version(),
    'server_at' => gmdate(DATE_ATOM),
    'access' => LiveViewAuth::accessKind($pdo),
    'vehicles' => $vehiclesPayload,
    'merge_suggestion' => $mergeSuggestion,
    'merge_preview' => $mergePreview,
    'can_merge' => Auth::check(),
    'vehicle' => [
        'id' => (int)$vehicle['id'],
        'name' => (string)($vehicle['display_name'] ?: 'Tesla'),
        'model' => $liveCarType,
        'visual' => $carArt,
        'state' => $effectiveState,
        'stored_state' => $state,
        'mode' => $mode,
        'speed_kmh' => $speed,
        'battery' => $battery,
        'range_km' => $range,
        'power_kw' => $power,
        'shift_state' => $shift,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'heading' => $heading,
        'location' => $currentPlace['label'],
        'last_data_at' => $lastDataAt,
        'data_age_seconds' => $dataAgeSeconds,
        'data_freshness' => $dataAgeSeconds === null
            ? 'unknown'
            : ($dataAgeSeconds <= 180 ? 'fresh' : ($dataAgeSeconds <= 300 ? 'aging' : 'stale')),
        'stream_status' => $streamRuntimeState ?: null,
        'stream_fresh' => $streamFresh,
    ],
    'trip' => $tripPayload,
    'charge' => $chargePayload,
    'journey' => $journeyPayload,
    'route' => [
        'trip_id' => $routeTripId,
        'merge_id' => $routeMergeId,
        'active' => $routeActive,
        'points' => $route,
        'segments' => $routeSegments,
        'stops' => $routeStops,
    ],
    'tesla_nerd' => [
        'model_name' => (string)($vehicle['model_name'] ?? ''),
        'vin_suffix' => !empty($vehicle['vin']) ? substr((string)$vehicle['vin'], -6) : '',
        'software_version' => $carVersion,
        'software_update' => [
            'available' => $softwareUpdateAvailable,
            'status' => $softwareUpdateStatus,
            'version' => $softwareUpdateVersion,
            'download_percent' => is_numeric($softwareUpdateRaw['download_perc'] ?? null) ? (float)$softwareUpdateRaw['download_perc'] : null,
            'install_percent' => is_numeric($softwareUpdateRaw['install_perc'] ?? null) ? (float)$softwareUpdateRaw['install_perc'] : null,
        ],
        'odometer_km' => $latestOdometer,
        'usable_battery' => $usableBattery,
        'rated_range_km' => $range,
        'estimated_range_km' => $estimatedRange,
        'elevation_m' => $elevation,
        'heading_deg' => $heading,
        'latitude' => is_numeric($stream['latitude'] ?? null) ? (float)$stream['latitude'] : $latitude,
        'longitude' => is_numeric($stream['longitude'] ?? null) ? (float)$stream['longitude'] : $longitude,
        'address' => $currentAddressShort !== '' ? $currentAddressShort : ($currentPlace['address'] ?? null),
        'street_name' => trim((string)($currentGeo['road'] ?? '')) ?: null,
        'house_number' => trim((string)($currentGeo['house_number'] ?? '')) ?: null,
        'postcode' => trim((string)($currentGeo['postcode'] ?? '')) ?: null,
        'city' => trim((string)($currentGeo['city'] ?? '')) ?: null,
        'road_type' => $currentRoadType['label'] ?? null,
        'road_ref' => $currentRoadType['ref'] ?? null,
        'inside_temp_c' => $insideTemp,
        'outside_temp_c' => $outsideTemp,
        'driver_temp_c' => $driverTemp,
        'passenger_temp_c' => $passengerTemp,
        'fan_status' => $fanStatus,
        'climate_on' => $climateOn,
        'locked' => $locked,
        'sentry_mode' => $sentryMode,
        'open_panels' => $openPanels,
        'tire_pressure_bar' => $tirePressure,
        'charge_port_open' => $chargePortOpen,
        'charge_port_latch' => $chargePortLatch,
        'charging_state' => (string)($snapshot['charging_state'] ?? $chargeRaw['charging_state'] ?? ''),
        'charger_power_kw' => $chargerPower,
        'charger_voltage_v' => $chargerVoltage,
        'charger_current_a' => $chargerCurrent,
        'charge_energy_added_kwh' => $chargeEnergyAdded,
        'time_to_full_charge_h' => $timeToFullCharge,
        'charge_limit_soc' => is_numeric($chargeRaw['charge_limit_soc'] ?? null) ? (float)$chargeRaw['charge_limit_soc'] : null,
        'charger_phases' => is_numeric($chargeRaw['charger_phases'] ?? null) ? (int)$chargeRaw['charger_phases'] : null,
        'fast_charger_type' => trim((string)($chargeRaw['fast_charger_type'] ?? '')) ?: null,
        'navigation' => [
            'destination' => $routeDestination !== '' ? $routeDestination : null,
            'distance_km' => $routeKmToArrival,
            'minutes' => $routeMinutesToArrival,
            'traffic_delay_minutes' => $routeTrafficDelay,
        ],
        'trim' => trim((string)($configRaw['trim_badging'] ?? '')) ?: null,
        'wheel_type' => trim((string)($configRaw['wheel_type'] ?? '')) ?: null,
        'data_at' => $streamFresh
            ? $streamTime
            : ($snapshotTime ?: ($vehicle['last_seen_at'] ?? null)),
    ],
    'future_layers' => [
        'location_share' => false,
        'other_teslas' => true,
        'community_places' => false,
    ],
]);
