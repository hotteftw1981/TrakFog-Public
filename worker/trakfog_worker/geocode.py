from __future__ import annotations

import logging
from typing import Any

import requests

from .config import Config
from .db import Database

LOG = logging.getLogger("trakfog-geocoder")


class ReverseGeocoder:
    def __init__(self, config: Config, db: Database) -> None:
        self.config = config
        self.db = db

    def tick(self) -> None:
        if not self.config.geocoder_enabled:
            return

        queued = self.db.queue_geo_locations()
        if queued:
            LOG.info("Queued %d new geo point(s)", queued)

        row = self.db.next_geo_location()
        if not row:
            return

        location_id = int(row["id"])
        latitude = float(row["latitude"])
        longitude = float(row["longitude"])
        self.db.mark_geo_attempt(location_id)

        try:
            response = requests.get(
                f"{self.config.geocoder_url}/reverse",
                params={
                    "format": "jsonv2",
                    "lat": f"{latitude:.6f}",
                    "lon": f"{longitude:.6f}",
                    "addressdetails": "1",
                    "extratags": "1",
                    "zoom": "18",
                },
                headers={
                    "User-Agent": self.config.geocoder_user_agent or "TrakFog self-hosted",
                    "Accept-Language": "de,en;q=0.7",
                },
                timeout=8,
            )
        except requests.RequestException as exc:
            self.db.fail_geo_location(location_id, retry=True)
            LOG.warning("Reverse geocoding request failed for geo=%s: %s", location_id, exc)
            return

        if response.status_code == 404:
            self.db.fail_geo_location(location_id, retry=False)
            return

        if response.status_code == 429 or response.status_code >= 500:
            self.db.fail_geo_location(location_id, retry=True)
            LOG.warning("Reverse geocoder returned HTTP %s for geo=%s", response.status_code, location_id)
            return

        if response.status_code != 200:
            self.db.fail_geo_location(location_id, retry=False)
            LOG.warning("Reverse geocoder returned HTTP %s for geo=%s", response.status_code, location_id)
            return

        try:
            payload: dict[str, Any] = response.json()
        except ValueError:
            self.db.fail_geo_location(location_id, retry=True)
            return

        address = payload.get("address")
        if not isinstance(address, dict):
            address = {}

        road = (
            address.get("road")
            or address.get("pedestrian")
            or address.get("residential")
            or address.get("footway")
            or address.get("path")
        )
        city = (
            address.get("city")
            or address.get("town")
            or address.get("village")
            or address.get("municipality")
            or address.get("county")
        )

        self.db.resolve_geo_location(
            location_id,
            display_name=str(payload.get("display_name") or "").strip() or None,
            road=str(road).strip() if road else None,
            house_number=str(address.get("house_number") or "").strip() or None,
            postcode=str(address.get("postcode") or "").strip() or None,
            city=str(city).strip() if city else None,
            state=str(address.get("state") or "").strip() or None,
            country=str(address.get("country") or "").strip() or None,
            country_code=str(address.get("country_code") or "").strip().upper() or None,
            provider="nominatim",
            raw_payload=payload,
        )
