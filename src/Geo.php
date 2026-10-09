<?php

declare(strict_types=1);

final class Geo
{
    public static function key(mixed $latitude, mixed $longitude): ?string
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return number_format((float)$latitude, 5, '.', '') . '|' .
            number_format((float)$longitude, 5, '.', '');
    }

    public static function loadLocations(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT latitude,longitude,lat_key,lon_key,display_name,road,house_number,postcode,city,state,country,country_code,status,raw_json
                 FROM geo_locations
                 WHERE status='resolved'"
            )->fetchAll();
        } catch (Throwable) {
            return [];
        }

        $locations = [];
        foreach ($rows as $row) {
            $key = self::key($row['lat_key'] ?? null, $row['lon_key'] ?? null);
            if ($key !== null) {
                $locations[$key] = $row;
            }
        }

        return $locations;
    }

    public static function loadGeofences(PDO $pdo, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT id,name,kind,shape_type,latitude,longitude,radius_m,polygon_json,active,notes
                    FROM geofences";
            if ($activeOnly) {
                $sql .= " WHERE active=1";
            }
            $sql .= " ORDER BY name,id";
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                $row['shape_type'] = in_array((string)($row['shape_type'] ?? 'circle'), ['circle','polygon'], true)
                    ? (string)$row['shape_type']
                    : 'circle';
                $row['polygon_points'] = self::decodePolygon($row['polygon_json'] ?? null);
            }
            unset($row);
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    public static function decodePolygon(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $points = [];
        foreach ($decoded as $point) {
            if (!is_array($point)) {
                continue;
            }
            $lat = $point['lat'] ?? ($point[0] ?? null);
            $lon = $point['lon'] ?? ($point['lng'] ?? ($point[1] ?? null));
            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }
            $lat = (float)$lat;
            $lon = (float)$lon;
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }
            $points[] = ['lat' => $lat, 'lon' => $lon];
        }

        return count($points) >= 3 ? $points : [];
    }

    public static function pointInPolygon(float $latitude, float $longitude, array $points): bool
    {
        $count = count($points);
        if ($count < 3) {
            return false;
        }

        $inside = false;
        $x = $longitude;
        $y = $latitude;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float)$points[$i]['lon'];
            $yi = (float)$points[$i]['lat'];
            $xj = (float)$points[$j]['lon'];
            $yj = (float)$points[$j]['lat'];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12)) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    public static function distanceMeters(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earth = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lon2 - $lon1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    public static function matchingGeofence(
        mixed $latitude,
        mixed $longitude,
        array $geofences
    ): ?array {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $lat = (float)$latitude;
        $lon = (float)$longitude;
        $best = null;
        $bestDistance = INF;

        foreach ($geofences as $geofence) {
            if (!is_numeric($geofence['latitude'] ?? null) || !is_numeric($geofence['longitude'] ?? null)) {
                continue;
            }

            $distance = self::distanceMeters(
                $lat,
                $lon,
                (float)$geofence['latitude'],
                (float)$geofence['longitude']
            );

            $shapeType = (string)($geofence['shape_type'] ?? 'circle');
            $matches = false;

            if ($shapeType === 'polygon') {
                $points = is_array($geofence['polygon_points'] ?? null)
                    ? $geofence['polygon_points']
                    : self::decodePolygon($geofence['polygon_json'] ?? null);
                $matches = self::pointInPolygon($lat, $lon, $points);
            } else {
                $radius = max(10.0, (float)($geofence['radius_m'] ?? 100));
                $matches = $distance <= $radius;
            }

            if ($matches && $distance < $bestDistance) {
                $best = $geofence + ['distance_m' => $distance];
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    public static function routePoints(PDO $pdo, array $tripIds, int $maxPointsPerTrip = 350): array
    {
        $tripIds = array_values(array_unique(array_filter(
            array_map('intval', $tripIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$tripIds) {
            return [];
        }

        $maxPointsPerTrip = max(25, min(1200, $maxPointsPerTrip));
        $placeholders = implode(',', array_fill(0, count($tripIds), '?'));

        $sql = "
            WITH route_source AS (
                SELECT
                    t.id AS trip_id,
                    s.id AS sample_id,
                    s.recorded_at,
                    s.latitude,
                    s.longitude,
                    ROW_NUMBER() OVER (
                        PARTITION BY t.id
                        ORDER BY s.recorded_at,s.id
                    ) AS rn,
                    COUNT(*) OVER (PARTITION BY t.id) AS cnt
                FROM trips t
                JOIN vehicle_stream_samples s
                  ON s.vehicle_id=t.vehicle_id
                 AND s.recorded_at>=t.started_at
                 AND s.recorded_at<=COALESCE(t.ended_at,t.last_sample_at,UTC_TIMESTAMP())
                WHERE t.id IN ({$placeholders})
                  AND s.latitude IS NOT NULL
                  AND s.longitude IS NOT NULL
                  AND s.latitude BETWEEN -90 AND 90
                  AND s.longitude BETWEEN -180 AND 180
                  AND NOT (s.latitude=0 AND s.longitude=0)
            )
            SELECT trip_id,recorded_at,latitude,longitude,rn,cnt
            FROM route_source
            WHERE rn=1
               OR rn=cnt
               OR MOD(
                    rn-1,
                    GREATEST(1,CEIL(cnt / ?))
                  )=0
            ORDER BY trip_id,rn
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([...$tripIds, $maxPointsPerTrip]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            if (class_exists('AppLogger')) {
                AppLogger::exception($e);
            }
            return [];
        }

        $routes = [];
        foreach ($rows as $row) {
            $tripId = (int)($row['trip_id'] ?? 0);
            if ($tripId <= 0 || !is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) {
                continue;
            }
            $routes[$tripId][] = [
                'lat' => (float)$row['latitude'],
                'lon' => (float)$row['longitude'],
                'at' => (string)($row['recorded_at'] ?? ''),
            ];
        }

        return $routes;
    }

    public static function nearestResolvedLocation(
        PDO $pdo,
        mixed $latitude,
        mixed $longitude,
        float $maxMeters = 180.0
    ): ?array {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $lat = (float)$latitude;
        $lon = (float)$longitude;
        $latSpan = max(0.001, $maxMeters / 111320.0 * 1.35);
        $lonFactor = max(0.2, cos(deg2rad($lat)));
        $lonSpan = max(0.001, $maxMeters / (111320.0 * $lonFactor) * 1.35);

        try {
            $stmt = $pdo->prepare(
                "SELECT latitude,longitude,lat_key,lon_key,display_name,road,house_number,postcode,city,state,country,country_code,status,raw_json
                 FROM geo_locations
                 WHERE status='resolved'
                   AND latitude BETWEEN ? AND ?
                   AND longitude BETWEEN ? AND ?
                 ORDER BY
                   POW(latitude-?,2)+POW(longitude-?,2)
                 LIMIT 12"
            );
            $stmt->execute([
                $lat-$latSpan,$lat+$latSpan,
                $lon-$lonSpan,$lon+$lonSpan,
                $lat,$lon,
            ]);
            foreach ($stmt->fetchAll() as $row) {
                if (!is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) {
                    continue;
                }
                $distance = self::distanceMeters(
                    $lat,$lon,
                    (float)$row['latitude'],(float)$row['longitude']
                );
                if ($distance <= $maxMeters) {
                    $row['distance_m'] = $distance;
                    return $row;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function roadType(?array $location): array
    {
        if (!$location) {
            return ['label' => null, 'ref' => null];
        }

        $payload = [];
        $raw = $location['raw_json'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (Throwable) {
                $payload = [];
            }
        } elseif (is_array($raw)) {
            $payload = $raw;
        }

        $extras = is_array($payload['extratags'] ?? null) ? $payload['extratags'] : [];
        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $ref = trim((string)($extras['ref'] ?? $payload['ref'] ?? ''));
        $type = strtolower(trim((string)($payload['type'] ?? $payload['addresstype'] ?? '')));

        if ($ref !== '') {
            if (preg_match('/^A\s?\d+/i', $ref)) return ['label' => 'Autobahn', 'ref' => $ref];
            if (preg_match('/^B\s?\d+/i', $ref)) return ['label' => 'Bundesstraße', 'ref' => $ref];
            if (preg_match('/^L\s?\d+/i', $ref)) return ['label' => 'Landesstraße', 'ref' => $ref];
            if (preg_match('/^K\s?\d+/i', $ref)) return ['label' => 'Kreisstraße', 'ref' => $ref];
        }

        foreach ([
            'motorway' => 'Autobahn',
            'motorway_link' => 'Autobahnauffahrt',
            'trunk' => 'Schnellstraße',
            'trunk_link' => 'Schnellstraßenauffahrt',
            'primary' => 'Hauptverkehrsstraße',
            'secondary' => 'Hauptstraße',
            'tertiary' => 'Verbindungsstraße',
            'residential' => 'Wohnstraße',
            'living_street' => 'Verkehrsberuhigter Bereich',
            'service' => 'Zufahrts-/Nebenstraße',
            'unclassified' => 'Nebenstraße',
            'pedestrian' => 'Fußgängerzone',
            'cycleway' => 'Radweg',
            'footway' => 'Fußweg',
            'path' => 'Weg',
        ] as $key => $label) {
            if ($type === $key || array_key_exists($key, $address)) {
                return ['label' => $label, 'ref' => $ref !== '' ? $ref : null];
            }
        }

        return [
            'label' => trim((string)($location['road'] ?? '')) !== '' ? 'Straße' : null,
            'ref' => $ref !== '' ? $ref : null,
        ];
    }

    public static function addressLine(?array $location): ?string
    {
        if (!$location) {
            return null;
        }

        $display = trim((string)($location['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }

        $street = trim(
            trim((string)($location['road'] ?? '')) . ' ' .
            trim((string)($location['house_number'] ?? ''))
        );
        $city = trim(
            trim((string)($location['postcode'] ?? '')) . ' ' .
            trim((string)($location['city'] ?? ''))
        );

        $parts = array_values(array_filter([$street, $city, trim((string)($location['country'] ?? ''))]));
        return $parts ? implode(', ', $parts) : null;
    }

    public static function label(
        mixed $latitude,
        mixed $longitude,
        array $locations,
        array $geofences,
        ?string $manual = null
    ): array {
        $manual = trim((string)$manual);
        if ($manual !== '') {
            return ['label' => $manual, 'source' => 'manual', 'geofence' => null, 'address' => null];
        }

        $geofence = self::matchingGeofence($latitude, $longitude, $geofences);
        $key = self::key($latitude, $longitude);
        $location = $key !== null ? ($locations[$key] ?? null) : null;
        $address = self::addressLine($location);

        if ($geofence) {
            return [
                'label' => (string)$geofence['name'],
                'source' => 'geofence',
                'geofence' => $geofence,
                'address' => $address,
            ];
        }

        if ($address) {
            return ['label' => $address, 'source' => 'address', 'geofence' => null, 'address' => $address];
        }

        if (is_numeric($latitude) && is_numeric($longitude)) {
            return [
                'label' => number_format((float)$latitude, 5, '.', '') . ', ' .
                    number_format((float)$longitude, 5, '.', ''),
                'source' => 'coordinates',
                'geofence' => null,
                'address' => null,
            ];
        }

        return ['label' => 'Standort unbekannt', 'source' => 'unknown', 'geofence' => null, 'address' => null];
    }
}
