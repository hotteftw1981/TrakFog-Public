"""Classify closed Tesla stream trips without trusting gear position alone.

A trip is provisionally stored while live; at closure a sub-100m candidate is
removed only when telemetry gives enough evidence that it never travelled.
Missing telemetry or significant speed favours retaining the trip.
"""

from __future__ import annotations

import math
from typing import Any

MIN_TRIP_METERS = 100


def _number(value: Any) -> float | None:
    try:
        if value is None or value == "":
            return None
        result = float(value)
        return result if math.isfinite(result) else None
    except (ValueError, TypeError, OverflowError):
        return None


def _haversine_meters(start_lat: Any, start_lon: Any, end_lat: Any, end_lon: Any) -> float | None:
    coordinates = [_number(x) for x in (start_lat, start_lon, end_lat, end_lon)]
    if any(x is None for x in coordinates):
        return None
    lat1, lon1, lat2, lon2 = coordinates
    if (not (-90 <= lat1 <= 90 and -90 <= lat2 <= 90 and -180 <= lon1 <= 180
             and -180 <= lon2 <= 180) or (lat1 == lon1 == 0) or (lat2 == lon2 == 0)):
        return None
    dlat, dlon = math.radians(lat2 - lat1), math.radians(lon2 - lon1)
    h = math.sin(dlat / 2) ** 2 + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.sin(dlon / 2) ** 2
    return 6371000.0 * 2 * math.asin(min(1.0, math.sqrt(h)))


def discard_tentative_trip(
    trip: dict[str, Any], *,
    end_odometer_km: Any = None,
    end_latitude: Any = None,
    end_longitude: Any = None,
    max_speed_kmh: Any = None,
    minimum_meters: int = MIN_TRIP_METERS,
) -> bool:
    """Return True only for a closed, demonstrably stationary/very short trip.

    At least one reliable distance measurement is necessary. A high measured
    speed makes an underreported odometer/GPS distance suspect; preserve then.
    This avoids deleting actual journeys after an intermittent Tesla stream.
    """
    if str(trip.get("source") or "") != "tesla_stream":
        return False  # never delete user-imported TeslaMate/lade data here
    minimum = max(0, int(minimum_meters))
    if minimum == 0:
        return False
    distance = _number(trip.get("distance_km"))
    start_odo = _number(trip.get("start_odometer_km"))
    final_odo = _number(end_odometer_km)
    if final_odo is None:
        final_odo = _number(trip.get("end_odometer_km"))
    # Ignore odometer resets, which cannot be trusted as a short journey.
    odometer_distance = (final_odo - start_odo) * 1000 if (
        final_odo is not None and start_odo is not None and final_odo >= start_odo
    ) else None
    gps_distance = _haversine_meters(
        trip.get("start_latitude"), trip.get("start_longitude"),
        end_latitude if end_latitude is not None else trip.get("end_latitude"),
        end_longitude if end_longitude is not None else trip.get("end_longitude"),
    )
    measurements = [v for v in (odometer_distance, gps_distance) if v is not None]
    # Stream trip.distance_km is derived from odometer, not an independent source.
    # If no independent evidence exists, do not automatically discard it.
    if not measurements:
        return False
    if distance is not None and distance * 1000 >= minimum:
        return False
    if any(value >= minimum for value in measurements):
        return False
    # A vehicle cannot reach 15 km/h without movement: telemetry is incomplete.
    speed = _number(max_speed_kmh)
    if speed is None:
        speed = _number(trip.get("max_speed_kmh"))
    if speed is not None and speed >= 15:
        return False
    return True
