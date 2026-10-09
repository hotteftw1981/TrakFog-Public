from __future__ import annotations

import json
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from typing import Any, Iterator

import pymysql
from pymysql.cursors import DictCursor

from .config import Config
from .trip_quality import discard_tentative_trip


def _float(value: Any) -> float | None:
    if value is None:
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _as_utc(value: Any) -> datetime | None:
    if value is None:
        return None
    if isinstance(value, datetime):
        moment = value
    else:
        text = str(value).strip()
        if not text:
            return None
        try:
            moment = datetime.fromisoformat(text.replace("Z", "+00:00"))
        except ValueError:
            try:
                moment = datetime.strptime(text, "%Y-%m-%d %H:%M:%S.%f")
            except ValueError:
                try:
                    moment = datetime.strptime(text, "%Y-%m-%d %H:%M:%S")
                except ValueError:
                    return None

    if moment.tzinfo is None:
        return moment.replace(tzinfo=timezone.utc)
    return moment.astimezone(timezone.utc)


class Database:
    def __init__(self, config: Config) -> None:
        self.config = config

    def connect(self):
        return pymysql.connect(
            host=self.config.db_host,
            port=self.config.db_port,
            user=self.config.db_user,
            password=self.config.db_password,
            database=self.config.db_name,
            charset="utf8mb4",
            autocommit=True,
            cursorclass=DictCursor,
            connect_timeout=10,
            read_timeout=30,
            write_timeout=30,
        )

    @contextmanager
    def connection(self) -> Iterator[Any]:
        conn = self.connect()
        try:
            yield conn
        finally:
            conn.close()

    def set_setting(self, key: str, value: str | None) -> None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO settings(setting_key, setting_value)
                VALUES(%s, %s)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
                """,
                (key, value),
            )

    def get_setting(self, key: str, default: str | None = None) -> str | None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute("SELECT setting_value FROM settings WHERE setting_key=%s LIMIT 1", (key,))
            row = cur.fetchone()
        if not row:
            return default
        return row["setting_value"]

    def upsert_vehicle_extras(
        self,
        vehicle_id: int,
        payloads: dict[str, Any],
        last_error: str | None = None,
    ) -> None:
        def encode(value: Any) -> str | None:
            if value is None:
                return None
            return json.dumps(value, ensure_ascii=False, separators=(",", ":"))

        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO tesla_vehicle_extras(
                    vehicle_id, nearby_charging_json, recent_alerts_json,
                    release_notes_json, service_data_json, synced_at, last_error
                )
                VALUES(%s,%s,%s,%s,%s,UTC_TIMESTAMP(),%s)
                ON DUPLICATE KEY UPDATE
                    nearby_charging_json=COALESCE(VALUES(nearby_charging_json),nearby_charging_json),
                    recent_alerts_json=COALESCE(VALUES(recent_alerts_json),recent_alerts_json),
                    release_notes_json=COALESCE(VALUES(release_notes_json),release_notes_json),
                    service_data_json=COALESCE(VALUES(service_data_json),service_data_json),
                    synced_at=VALUES(synced_at),
                    last_error=VALUES(last_error)
                """,
                (
                    vehicle_id,
                    encode(payloads.get("nearby_charging")),
                    encode(payloads.get("recent_alerts")),
                    encode(payloads.get("release_notes")),
                    encode(payloads.get("service_data")),
                    last_error,
                ),
            )

    def charging_cost_sync_vehicles(self) -> list[dict[str, Any]]:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT id, vin, display_name
                FROM vehicles
                WHERE source_type='tesla_owner_api'
                  AND vin IS NOT NULL
                  AND TRIM(vin)<>''
                ORDER BY id
                """
            )
            return list(cur.fetchall())

    def sync_tesla_charging_cost(
        self,
        vehicle_id: int,
        session: dict[str, Any],
    ) -> str:
        start_at = _as_utc(session.get("start_at"))
        cost = _float(session.get("cost_amount"))
        if start_at is None or cost is None:
            return "ignored"

        session_id = str(session.get("session_id") or "").strip() or None
        energy = _float(session.get("energy_kwh"))
        location = str(session.get("location") or "").strip() or None
        currency = str(session.get("currency") or "").strip().upper() or None
        raw = session.get("raw") if isinstance(session.get("raw"), dict) else session

        if session_id:
            with self.connection() as conn, conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id,cost_source,cost_amount,cost_currency,tesla_site_name
                    FROM charges
                    WHERE tesla_charge_session_id=%s
                    LIMIT 1
                    """,
                    (session_id,),
                )
                existing = cur.fetchone()
                if existing and str(existing.get("cost_source") or "") == "tesla_invoice":
                    same_cost = _float(existing.get("cost_amount")) == cost
                    same_currency = str(existing.get("cost_currency") or "").upper() == (currency or "EUR")
                    same_location = str(existing.get("tesla_site_name") or "") == (location or "")
                    if same_cost and same_currency and same_location:
                        return "already_synced"

        def candidates(window_minutes: int) -> list[dict[str, Any]]:
            low = (start_at - timedelta(minutes=window_minutes)).strftime("%Y-%m-%d %H:%M:%S")
            high = (start_at + timedelta(minutes=window_minutes)).strftime("%Y-%m-%d %H:%M:%S")
            start_text = start_at.strftime("%Y-%m-%d %H:%M:%S")
            with self.connection() as conn, conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id,started_at,energy_added_kwh,cost_amount,cost_currency,
                           cost_source,cost_locked,tesla_charge_session_id
                    FROM charges
                    WHERE vehicle_id=%s
                      AND ended_at IS NOT NULL
                      AND started_at BETWEEN %s AND %s
                      AND (cost_locked=0 OR cost_source='tesla_invoice')
                      AND (
                            tesla_charge_session_id IS NULL
                            OR tesla_charge_session_id=%s
                          )
                    ORDER BY ABS(TIMESTAMPDIFF(SECOND,started_at,%s)),id
                    """,
                    (vehicle_id, low, high, session_id, start_text),
                )
                return list(cur.fetchall())

        rows = candidates(10)
        if not rows:
            rows = candidates(30)
        if not rows:
            return "no_match"

        def score(row: dict[str, Any]) -> float:
            row_start = _as_utc(row.get("started_at"))
            time_score = (
                abs((row_start - start_at).total_seconds())
                if row_start is not None
                else 999999.0
            )
            local_energy = _float(row.get("energy_added_kwh"))
            if energy is not None and local_energy is not None:
                relative_error = abs(energy - local_energy) / max(abs(energy), 0.1)
                return time_score + relative_error * 36000.0
            return time_score

        match = min(rows, key=score)
        effective_energy = _float(match.get("energy_added_kwh"))
        if effective_energy is None or effective_energy <= 0.001:
            effective_energy = energy
        effective_price = (
            round(cost / effective_energy, 4)
            if effective_energy is not None and effective_energy > 0.001
            else None
        )

        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                UPDATE charges
                SET tariff_id=NULL,
                    price_per_kwh=%s,
                    cost_amount=%s,
                    cost_currency=%s,
                    cost_source='tesla_invoice',
                    cost_locked=1,
                    tesla_charge_session_id=%s,
                    tesla_site_name=%s,
                    tesla_cost_synced_at=UTC_TIMESTAMP(),
                    tesla_history_json=%s,
                    location_name=CASE
                        WHEN (location_name IS NULL OR TRIM(location_name)='')
                             AND %s IS NOT NULL
                        THEN %s
                        ELSE location_name
                    END
                WHERE id=%s
                  AND (cost_locked=0 OR cost_source='tesla_invoice')
                """,
                (
                    effective_price,
                    round(cost, 2),
                    currency or str(match.get("cost_currency") or "").strip().upper() or "EUR",
                    session_id,
                    location,
                    json.dumps(raw, ensure_ascii=False, separators=(",", ":")),
                    location,
                    location,
                    int(match["id"]),
                ),
            )
            if cur.rowcount:
                return "updated"

        return "skipped"

    def heartbeat(self, state: str | None = None, detail: dict[str, Any] | None = None) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        self.set_setting("worker_last_heartbeat", now)
        if state is not None:
            self.set_setting("worker_state", state)
        if detail is not None:
            self.set_setting(
                "worker_last_result",
                json.dumps(detail, ensure_ascii=False, separators=(",", ":")),
            )

    def queue_geo_locations(self) -> int:
        statements = [
            """
            INSERT IGNORE INTO geo_locations(latitude,longitude,lat_key,lon_key)
            SELECT start_latitude,start_longitude,ROUND(start_latitude,5),ROUND(start_longitude,5)
            FROM trips
            WHERE start_latitude IS NOT NULL AND start_longitude IS NOT NULL
              AND start_latitude BETWEEN -90 AND 90
              AND start_longitude BETWEEN -180 AND 180
              AND NOT (start_latitude=0 AND start_longitude=0)
            """,
            """
            INSERT IGNORE INTO geo_locations(latitude,longitude,lat_key,lon_key)
            SELECT end_latitude,end_longitude,ROUND(end_latitude,5),ROUND(end_longitude,5)
            FROM trips
            WHERE ended_at IS NOT NULL
              AND end_latitude IS NOT NULL AND end_longitude IS NOT NULL
              AND end_latitude BETWEEN -90 AND 90
              AND end_longitude BETWEEN -180 AND 180
              AND NOT (end_latitude=0 AND end_longitude=0)
            """,
            """
            INSERT IGNORE INTO geo_locations(latitude,longitude,lat_key,lon_key)
            SELECT latitude,longitude,ROUND(latitude,5),ROUND(longitude,5)
            FROM charges
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
              AND latitude BETWEEN -90 AND 90
              AND longitude BETWEEN -180 AND 180
              AND NOT (latitude=0 AND longitude=0)
            """,
            """
            INSERT IGNORE INTO geo_locations(latitude,longitude,lat_key,lon_key)
            SELECT latitude,longitude,ROUND(latitude,5),ROUND(longitude,5)
            FROM vehicles
            WHERE state IN ('asleep','offline')
              AND latitude IS NOT NULL AND longitude IS NOT NULL
              AND latitude BETWEEN -90 AND 90
              AND longitude BETWEEN -180 AND 180
              AND NOT (latitude=0 AND longitude=0)
            """,
            """
            INSERT IGNORE INTO geo_locations(latitude,longitude,lat_key,lon_key)
            SELECT latitude,longitude,ROUND(latitude,5),ROUND(longitude,5)
            FROM geofences
            WHERE active=1
              AND latitude BETWEEN -90 AND 90
              AND longitude BETWEEN -180 AND 180
              AND NOT (latitude=0 AND longitude=0)
            """,
        ]

        queued = 0
        with self.connection() as conn, conn.cursor() as cur:
            for statement in statements:
                cur.execute(statement)
                queued += max(0, int(cur.rowcount or 0))
        return queued

    def next_geo_location(self) -> dict[str, Any] | None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT id,latitude,longitude,attempts,status
                FROM geo_locations
                WHERE status='pending'
                   OR (
                        status='retry'
                        AND attempts<5
                        AND (
                            last_attempt_at IS NULL
                            OR last_attempt_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
                        )
                   )
                   OR (
                        status='processing'
                        AND attempts<5
                        AND last_attempt_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
                   )
                ORDER BY
                    CASE WHEN status='pending' THEN 0 ELSE 1 END,
                    created_at,
                    id
                LIMIT 1
                """
            )
            return cur.fetchone()

    def mark_geo_attempt(self, location_id: int) -> None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                UPDATE geo_locations
                SET status='processing',
                    attempts=attempts+1,
                    last_attempt_at=UTC_TIMESTAMP()
                WHERE id=%s
                """,
                (location_id,),
            )

    def resolve_geo_location(
        self,
        location_id: int,
        *,
        display_name: str | None,
        road: str | None,
        house_number: str | None,
        postcode: str | None,
        city: str | None,
        state: str | None,
        country: str | None,
        country_code: str | None,
        provider: str,
        raw_payload: dict[str, Any],
    ) -> None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                UPDATE geo_locations
                SET display_name=%s,
                    road=%s,
                    house_number=%s,
                    postcode=%s,
                    city=%s,
                    state=%s,
                    country=%s,
                    country_code=%s,
                    provider=%s,
                    raw_json=%s,
                    status='resolved',
                    resolved_at=UTC_TIMESTAMP()
                WHERE id=%s
                """,
                (
                    display_name,
                    road,
                    house_number,
                    postcode,
                    city,
                    state,
                    country,
                    country_code,
                    provider,
                    json.dumps(raw_payload, ensure_ascii=False, separators=(",", ":")),
                    location_id,
                ),
            )

    def fail_geo_location(self, location_id: int, *, retry: bool) -> None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                UPDATE geo_locations
                SET status=CASE
                    WHEN %s=1 AND attempts<5 THEN 'retry'
                    ELSE 'failed'
                END
                WHERE id=%s
                """,
                (1 if retry else 0, location_id),
            )

    def observe_vehicle_state(
        self,
        vehicle_id: int,
        observed_at: str,
        previous_state: str | None,
        current_state: str | None,
        *,
        battery_level: float | None = None,
        range_km: float | None = None,
        odometer_km: float | None = None,
    ) -> None:
        previous = str(previous_state or "").strip().lower()
        current = str(current_state or "").strip().lower()
        if not current:
            return

        sleep_states = {"asleep", "offline"}

        with self.connection() as conn, conn.cursor() as cur:
            if current != previous:
                cur.execute(
                    """
                    INSERT INTO vehicle_state_events(
                        vehicle_id, observed_at, from_state, to_state, source
                    )
                    VALUES(%s,%s,%s,%s,'tesla_products')
                    """,
                    (
                        vehicle_id,
                        observed_at,
                        previous or None,
                        current,
                    ),
                )

            if current not in sleep_states:
                return

            cur.execute(
                """
                SELECT id
                FROM sleep_sessions
                WHERE vehicle_id=%s AND ended_at IS NULL
                ORDER BY id DESC
                LIMIT 1
                """,
                (vehicle_id,),
            )
            if cur.fetchone():
                return

            cur.execute(
                """
                INSERT INTO sleep_sessions(
                    vehicle_id, started_at, start_state,
                    start_soc, start_range_km, start_odometer_km,
                    quality
                )
                VALUES(%s,%s,%s,%s,%s,%s,'pending')
                """,
                (
                    vehicle_id,
                    observed_at,
                    current,
                    battery_level,
                    range_km,
                    odometer_km,
                ),
            )

    def finalize_sleep_session(
        self,
        vehicle_id: int,
        ended_at: str,
        *,
        end_state: str | None,
        battery_level: float | None,
        range_km: float | None,
        odometer_km: float | None,
    ) -> bool:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT *
                FROM sleep_sessions
                WHERE vehicle_id=%s AND ended_at IS NULL
                ORDER BY id DESC
                LIMIT 1
                """,
                (vehicle_id,),
            )
            session = cur.fetchone()
            if not session:
                return False

            started = _as_utc(session.get("started_at"))
            ended = _as_utc(ended_at)
            if started is None or ended is None or ended <= started:
                return False

            duration_seconds = int((ended - started).total_seconds())
            start_soc = _float(session.get("start_soc"))
            start_range = _float(session.get("start_range_km"))
            start_odometer = _float(session.get("start_odometer_km"))

            soc_delta = (
                battery_level - start_soc
                if battery_level is not None and start_soc is not None
                else None
            )
            range_delta = (
                range_km - start_range
                if range_km is not None and start_range is not None
                else None
            )
            drain_percent = max(0.0, -soc_delta) if soc_delta is not None else None
            drain_range = max(0.0, -range_delta) if range_delta is not None else None
            moved_km = (
                max(0.0, odometer_km - start_odometer)
                if odometer_km is not None and start_odometer is not None
                else 0.0
            )
            drain_per_day = (
                drain_percent * 86400.0 / duration_seconds
                if drain_percent is not None and duration_seconds > 0
                else None
            )

            cur.execute(
                """
                SELECT COUNT(*) AS charge_count
                FROM charges
                WHERE vehicle_id=%s
                  AND started_at<=%s
                  AND COALESCE(ended_at,%s)>=%s
                """,
                (
                    vehicle_id,
                    ended_at,
                    ended_at,
                    session["started_at"],
                ),
            )
            charge_row = cur.fetchone() or {}
            had_charge = int(charge_row.get("charge_count") or 0) > 0

            quality = "valid"
            excluded_reason = None
            if duration_seconds < 900:
                quality = "excluded"
                excluded_reason = "too_short"
            elif had_charge:
                quality = "excluded"
                excluded_reason = "charging"
            elif moved_km > 0.2:
                quality = "excluded"
                excluded_reason = "movement"
            elif start_soc is None or battery_level is None:
                quality = "insufficient"
                excluded_reason = "missing_soc"

            cur.execute(
                """
                UPDATE sleep_sessions
                SET ended_at=%s,
                    end_state=%s,
                    end_soc=%s,
                    end_range_km=%s,
                    end_odometer_km=%s,
                    duration_seconds=%s,
                    soc_delta=%s,
                    range_delta_km=%s,
                    drain_percent=%s,
                    drain_range_km=%s,
                    drain_percent_per_day=%s,
                    moved_km=%s,
                    had_charge=%s,
                    quality=%s,
                    excluded_reason=%s
                WHERE id=%s AND ended_at IS NULL
                """,
                (
                    ended_at,
                    str(end_state or "online").lower(),
                    battery_level,
                    range_km,
                    odometer_km,
                    duration_seconds,
                    round(soc_delta, 2) if soc_delta is not None else None,
                    round(range_delta, 2) if range_delta is not None else None,
                    round(drain_percent, 2) if drain_percent is not None else None,
                    round(drain_range, 2) if drain_range is not None else None,
                    round(drain_per_day, 3) if drain_per_day is not None else None,
                    round(moved_km, 3),
                    1 if had_charge else 0,
                    quality,
                    excluded_reason,
                    int(session["id"]),
                ),
            )
            return cur.rowcount > 0

    def known_tesla_vehicles(self) -> list[dict[str, Any]]:
        """Known vehicles used when Tesla's /products discovery endpoint is unavailable."""
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                    v.id,
                    v.external_id,
                    v.vehicle_id,
                    v.display_name,
                    v.vin,
                    v.state,
                    v.last_seen_at,
                    s.status AS stream_status,
                    s.last_event_at AS stream_last_event_at
                FROM vehicles v
                LEFT JOIN vehicle_stream_status s ON s.vehicle_id=v.id
                WHERE v.source_type='tesla_owner_api'
                  AND v.external_id IS NOT NULL
                  AND v.external_id <> ''
                ORDER BY v.id
                """
            )
            return list(cur.fetchall())

    def stream_vehicles(self) -> list[dict[str, Any]]:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT id, vehicle_id, display_name, vin
                FROM vehicles
                WHERE source_type='tesla_owner_api'
                  AND vehicle_id IS NOT NULL
                  AND vehicle_id <> ''
                ORDER BY id
                """
            )
            return list(cur.fetchall())

    def set_stream_status(
        self,
        vehicle_id: int,
        status: str,
        *,
        connected_at: str | None = None,
        last_error: str | None = None,
    ) -> None:
        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO vehicle_stream_status(vehicle_id, status, connected_at, last_error)
                VALUES(%s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    connected_at=COALESCE(VALUES(connected_at), connected_at),
                    last_error=VALUES(last_error)
                """,
                (vehicle_id, status, connected_at, last_error),
            )

    def _energy_delta_kwh(
        self,
        previous: dict[str, Any] | None,
        sample: dict[str, Any],
    ) -> float:
        if not previous:
            return 0.0

        previous_at = _as_utc(previous.get("recorded_at"))
        current_at = _as_utc(sample.get("recorded_at"))
        previous_power = _float(previous.get("power_kw"))
        current_power = _float(sample.get("power_kw"))

        if previous_at is None or current_at is None:
            return 0.0
        if previous_power is None or current_power is None:
            return 0.0

        seconds = (current_at - previous_at).total_seconds()
        if seconds <= 0 or seconds > 30:
            return 0.0

        average_power = (previous_power + current_power) / 2.0
        return average_power * (seconds / 3600.0)

    @staticmethod
    def _delete_tentative_trip(cur, trip_id: int) -> None:
        """Drop only an unqualified OPEN stream trip; keep its raw samples.

        Deleting a trip cascades its journey/merge member links. If it was
        already linked to a two-part tour, remove the stranded tour shell.
        """
        cur.execute("SELECT merge_id FROM trip_merge_members WHERE trip_id=%s", (trip_id,))
        merged = cur.fetchone()
        cur.execute(
            "DELETE FROM trips WHERE id=%s AND ended_at IS NULL AND source='tesla_stream'",
            (trip_id,),
        )
        if cur.rowcount and merged:
            cur.execute(
                """DELETE m FROM trip_merges m
                   WHERE m.id=%s AND (SELECT COUNT(*) FROM trip_merge_members mm
                                      WHERE mm.merge_id=m.id)<2""",
                (merged["merge_id"],),
            )

    def _close_open_trip_at_charge_start(self, cur, vehicle_id: int, charge_at: str) -> None:
        """The car cannot drive while actively charging.

        Tesla's P/park event can arrive late or not at all. End at the last
        documented drive sample BEFORE charging, not at the post-charge SoC.
        Raw stream samples and charge records are left untouched.
        """
        cur.execute(
            """SELECT * FROM trips
               WHERE vehicle_id=%s AND ended_at IS NULL AND source='tesla_stream'
                 AND started_at<=%s
               ORDER BY id DESC LIMIT 1""",
            (vehicle_id, charge_at),
        )
        trip = cur.fetchone()
        if not trip:
            return

        cur.execute(
            """SELECT recorded_at,latitude,longitude,odometer_km,soc,range_km,speed_kmh
               FROM vehicle_stream_samples
               WHERE vehicle_id=%s AND recorded_at>=%s AND recorded_at<%s
                 AND (shift_state IN ('D','R') OR
                      (speed_kmh>=2 AND (shift_state IS NULL OR shift_state<>'P')))
               ORDER BY CASE WHEN speed_kmh>=2 THEN 1 ELSE 0 END DESC,
                        recorded_at DESC,id DESC LIMIT 1""",
            (vehicle_id, trip["started_at"], charge_at),
        )
        last_drive = cur.fetchone()
        if last_drive:
            end_at = last_drive["recorded_at"]
        else:
            # Never use the first charging measurement as proof of a drive end.
            previous_at = _as_utc(trip.get("last_sample_at"))
            charge_time = _as_utc(charge_at)
            end_at = (trip.get("last_sample_at")
                      if previous_at and charge_time and previous_at < charge_time
                      else trip["started_at"])

        last_drive = last_drive or {}
        odo = _float(last_drive.get("odometer_km"))
        end_lat = last_drive.get("latitude")
        end_lon = last_drive.get("longitude")
        start_odo = _float(trip.get("start_odometer_km"))
        distance = _float(trip.get("distance_km")) or 0.0
        if odo is not None and start_odo is not None and odo >= start_odo:
            distance = max(distance, odo - start_odo)
        if discard_tentative_trip(
            {**trip, "distance_km": distance},
            end_odometer_km=odo,
            end_latitude=end_lat,
            end_longitude=end_lon,
            minimum_meters=self.config.min_trip_distance_meters,
        ):
            self._delete_tentative_trip(cur, int(trip["id"]))
            return

        energy = _float(trip.get("energy_kwh")) or 0.0
        avg = round(energy * 1000.0 / distance, 2) if distance >= .05 else None
        cur.execute(
            """UPDATE trips
               SET ended_at=%s,
                   end_latitude=COALESCE(%s,end_latitude),
                   end_longitude=COALESCE(%s,end_longitude),
                   end_odometer_km=COALESCE(%s,end_odometer_km),
                   end_soc=COALESCE(%s,end_soc),
                   end_range_km=COALESCE(%s,end_range_km),
                   distance_km=%s,
                   avg_wh_km=%s,
                   last_sample_at=%s
               WHERE id=%s AND ended_at IS NULL""",
            (end_at, end_lat, end_lon, odo,
             _float(last_drive.get("soc")), _float(last_drive.get("range_km")),
             round(distance, 3), avg, end_at, int(trip["id"])),
        )

    def _update_trip_from_sample(
        self,
        cur,
        vehicle_id: int,
        sample: dict[str, Any],
        previous: dict[str, Any] | None,
    ) -> None:
        recorded_at = str(sample["recorded_at"])
        shift = str(sample.get("shift_state") or "").upper().strip()
        speed = _float(sample.get("speed_kmh"))
        odometer = _float(sample.get("odometer_km"))
        soc = _float(sample.get("soc"))
        range_km = _float(sample.get("range_km"))
        latitude = _float(sample.get("latitude"))
        longitude = _float(sample.get("longitude"))

        driving = shift in {"D", "R"} or (shift != "P" and speed is not None and speed >= 2.0)
        parked = shift == "P"

        cur.execute(
            """
            SELECT *
            FROM trips
            WHERE vehicle_id=%s AND ended_at IS NULL
            ORDER BY id DESC
            LIMIT 1
            """,
            (vehicle_id,),
        )
        trip = cur.fetchone()

        if driving and not trip:
            cur.execute(
                """
                INSERT INTO trips(
                    vehicle_id, started_at,
                    start_latitude, start_longitude,
                    end_latitude, end_longitude,
                    start_odometer_km, end_odometer_km,
                    start_soc, end_soc,
                    start_range_km, end_range_km,
                    distance_km, energy_kwh, avg_wh_km, max_speed_kmh,
                    sample_count, last_sample_at, source
                )
                VALUES(
                    %s,%s,
                    %s,%s,
                    %s,%s,
                    %s,%s,
                    %s,%s,
                    %s,%s,
                    0,0,NULL,%s,
                    1,%s,'tesla_stream'
                )
                """,
                (
                    vehicle_id, recorded_at,
                    latitude, longitude,
                    latitude, longitude,
                    odometer, odometer,
                    soc, soc,
                    range_km, range_km,
                    speed,
                    recorded_at,
                ),
            )
            return

        if not trip:
            return

        start_odometer = _float(trip.get("start_odometer_km"))
        current_distance = _float(trip.get("distance_km")) or 0.0
        if start_odometer is not None and odometer is not None and odometer >= start_odometer:
            current_distance = max(current_distance, odometer - start_odometer)

        current_energy = _float(trip.get("energy_kwh")) or 0.0
        previous_shift = str((previous or {}).get("shift_state") or "").upper().strip()
        previous_speed = _float((previous or {}).get("speed_kmh"))
        previous_driving = previous_shift in {"D", "R"} or (
            previous_shift != "P" and previous_speed is not None and previous_speed >= 2.0
        )
        if driving and previous_driving:
            current_energy = max(0.0, current_energy + self._energy_delta_kwh(previous, sample))

        max_speed = _float(trip.get("max_speed_kmh"))
        if speed is not None:
            max_speed = speed if max_speed is None else max(max_speed, speed)

        avg_wh_km = None
        if current_distance >= 0.05:
            avg_wh_km = current_energy * 1000.0 / current_distance

        ended_at = recorded_at if parked else None
        if parked and discard_tentative_trip(
            {**trip, "distance_km": current_distance, "max_speed_kmh": max_speed},
            end_odometer_km=odometer,
            end_latitude=latitude,
            end_longitude=longitude,
            max_speed_kmh=max_speed,
            minimum_meters=self.config.min_trip_distance_meters,
        ):
            self._delete_tentative_trip(cur, int(trip["id"]))
            return

        cur.execute(
            """
            UPDATE trips
            SET ended_at=COALESCE(%s,ended_at),
                end_latitude=COALESCE(%s,end_latitude),
                end_longitude=COALESCE(%s,end_longitude),
                end_odometer_km=COALESCE(%s,end_odometer_km),
                end_soc=COALESCE(%s,end_soc),
                end_range_km=COALESCE(%s,end_range_km),
                distance_km=%s,
                energy_kwh=%s,
                avg_wh_km=%s,
                max_speed_kmh=%s,
                sample_count=sample_count+1,
                last_sample_at=%s
            WHERE id=%s AND ended_at IS NULL
            """,
            (
                ended_at,
                latitude, longitude,
                odometer, soc if driving else None, range_km if driving else None,
                round(current_distance, 3),
                round(current_energy, 4),
                round(avg_wh_km, 2) if avg_wh_km is not None else None,
                round(max_speed, 2) if max_speed is not None else None,
                recorded_at,
                int(trip["id"]),
            ),
        )

    def store_stream_sample(self, vehicle_id: int, sample: dict[str, Any]) -> None:
        recorded_at = str(sample["recorded_at"])
        shift_state = sample.get("shift_state")
        moving = shift_state in {"D", "R", "N"}

        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT recorded_at, power_kw, speed_kmh, shift_state
                FROM vehicle_stream_samples
                WHERE vehicle_id=%s
                ORDER BY id DESC
                LIMIT 1
                """,
                (vehicle_id,),
            )
            previous = cur.fetchone()

            cur.execute(
                """
                INSERT INTO vehicle_stream_samples(
                    vehicle_id, recorded_at, speed_kmh, odometer_km, soc,
                    elevation_m, est_heading, latitude, longitude, power_kw,
                    shift_state, range_km, est_range_km, heading, raw_value
                )
                VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """,
                (
                    vehicle_id, recorded_at, sample.get("speed_kmh"),
                    sample.get("odometer_km"), sample.get("soc"),
                    sample.get("elevation_m"), sample.get("est_heading"),
                    sample.get("latitude"), sample.get("longitude"),
                    sample.get("power_kw"), shift_state, sample.get("range_km"),
                    sample.get("est_range_km"), sample.get("heading"),
                    sample.get("raw_value"),
                ),
            )

            cur.execute(
                """
                UPDATE vehicles
                SET state=CASE WHEN %s=1 THEN 'online' ELSE state END,
                    odometer_km=COALESCE(%s,odometer_km),
                    battery_level=COALESCE(%s,battery_level),
                    rated_range_km=COALESCE(%s,rated_range_km),
                    latitude=COALESCE(%s,latitude),
                    longitude=COALESCE(%s,longitude),
                    heading=COALESCE(%s,heading),
                    speed_kmh=%s,
                    last_seen_at=%s
                WHERE id=%s
                """,
                (
                    1 if moving else 0, sample.get("odometer_km"),
                    sample.get("soc"), sample.get("range_km"),
                    sample.get("latitude"), sample.get("longitude"),
                    sample.get("heading") or sample.get("est_heading"),
                    sample.get("speed_kmh"), recorded_at, vehicle_id,
                ),
            )

            cur.execute(
                """
                INSERT INTO vehicle_stream_status(
                    vehicle_id, status, last_event_at, last_error,
                    update_count, shift_state
                )
                VALUES(%s,'streaming',%s,NULL,1,%s)
                ON DUPLICATE KEY UPDATE
                    status='streaming',
                    last_event_at=VALUES(last_event_at),
                    last_error=NULL,
                    update_count=update_count+1,
                    shift_state=VALUES(shift_state)
                """,
                (vehicle_id, recorded_at, shift_state),
            )

            self._update_trip_from_sample(cur, vehicle_id, sample, previous)

    def finalize_stale_trips(self) -> int:
        now = datetime.now(timezone.utc)
        closed = 0

        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                    t.id, t.vehicle_id, t.start_odometer_km, t.end_odometer_km,
                    t.start_latitude, t.start_longitude, t.end_latitude, t.end_longitude,
                    t.distance_km, t.source,
                    t.energy_kwh, t.max_speed_kmh, t.last_sample_at,
                    v.state AS vehicle_state,
                    s.recorded_at, s.shift_state, s.speed_kmh, s.odometer_km,
                    s.soc, s.range_km, s.latitude, s.longitude
                FROM trips t
                JOIN vehicles v ON v.id=t.vehicle_id
                LEFT JOIN vehicle_stream_samples s
                  ON s.id=(
                    SELECT s2.id
                    FROM vehicle_stream_samples s2
                    WHERE s2.vehicle_id=t.vehicle_id
                    ORDER BY s2.id DESC
                    LIMIT 1
                  )
                WHERE t.ended_at IS NULL
                """
            )
            rows = list(cur.fetchall())

            for row in rows:
                sample_at = _as_utc(row.get("recorded_at") or row.get("last_sample_at"))
                if sample_at is None:
                    continue

                age = max(0.0, (now - sample_at).total_seconds())
                shift = str(row.get("shift_state") or "").upper().strip()
                speed = _float(row.get("speed_kmh")) or 0.0
                vehicle_state = str(row.get("vehicle_state") or "").lower()

                should_close = False
                if shift == "P":
                    should_close = True
                elif age >= 180 and shift not in {"D", "R", "N"} and speed <= 1.0:
                    should_close = True
                elif age >= 300 and vehicle_state in {"asleep", "offline"}:
                    should_close = True

                if not should_close:
                    continue

                start_odometer = _float(row.get("start_odometer_km"))
                end_odometer = _float(row.get("odometer_km"))
                distance = _float(row.get("distance_km")) or 0.0
                if start_odometer is not None and end_odometer is not None and end_odometer >= start_odometer:
                    distance = max(distance, end_odometer - start_odometer)

                if discard_tentative_trip(
                    {**row, "distance_km": distance},
                    end_odometer_km=row.get("odometer_km"),
                    end_latitude=row.get("latitude"),
                    end_longitude=row.get("longitude"),
                    minimum_meters=self.config.min_trip_distance_meters,
                ):
                    self._delete_tentative_trip(cur, int(row["id"]))
                    continue

                energy = _float(row.get("energy_kwh")) or 0.0
                avg_wh_km = (energy * 1000.0 / distance) if distance >= 0.05 else None

                cur.execute(
                    """
                    UPDATE trips
                    SET ended_at=%s,
                        end_latitude=COALESCE(%s,end_latitude),
                        end_longitude=COALESCE(%s,end_longitude),
                        end_odometer_km=COALESCE(%s,end_odometer_km),
                        distance_km=%s,
                        avg_wh_km=%s,
                        last_sample_at=COALESCE(%s,last_sample_at)
                    WHERE id=%s AND ended_at IS NULL
                    """,
                    (
                        row.get("recorded_at") or row.get("last_sample_at"),
                        row.get("latitude"), row.get("longitude"),
                        row.get("odometer_km"),
                        round(distance, 3),
                        round(avg_wh_km, 2) if avg_wh_km is not None else None,
                        row.get("recorded_at"),
                        int(row["id"]),
                    ),
                )
                if cur.rowcount:
                    closed += 1

        return closed

    def update_charge_from_rest(
        self,
        vehicle_id: int,
        recorded_at: str,
        charge: dict[str, Any],
        drive: dict[str, Any],
    ) -> None:
        state_raw = str(charge.get("charging_state") or "").strip()
        state = state_raw.lower()
        power_kw = _float(charge.get("charger_power"))
        battery = _float(charge.get("battery_level"))
        energy_added = _float(charge.get("charge_energy_added"))
        latitude = _float(drive.get("latitude"))
        longitude = _float(drive.get("longitude"))

        active = state in {"charging", "starting"} or (power_kw is not None and power_kw > 0.1)
        terminal = state in {"complete", "disconnected"}

        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT *
                FROM charges
                WHERE vehicle_id=%s AND ended_at IS NULL
                ORDER BY id DESC
                LIMIT 1
                """,
                (vehicle_id,),
            )
            session = cur.fetchone()

            if active:
                self._close_open_trip_at_charge_start(cur, vehicle_id, recorded_at)

            if active and not session:
                cur.execute(
                    """
                    INSERT INTO charges(
                        vehicle_id, started_at, ended_at,
                        energy_added_kwh,
                        start_battery_percent, end_battery_percent,
                        max_power_kw,
                        latitude, longitude,
                        tariff_id, price_per_kwh,
                        cost_amount, cost_currency, cost_source, cost_locked,
                        last_state, last_active_at, last_sample_at,
                        sample_count, source
                    )
                    VALUES(
                        %s,%s,NULL,
                        %s,%s,%s,%s,%s,%s,
                        NULL,NULL,
                        NULL,NULL,NULL,0,
                        %s,%s,%s,
                        1,'tesla_rest'
                    )
                    """,
                    (
                        vehicle_id, recorded_at, energy_added,
                        battery, battery, power_kw,
                        latitude, longitude,
                        state_raw or None,
                        recorded_at, recorded_at,
                    ),
                )
                return

            if not session:
                return

            previous_energy = _float(session.get("energy_added_kwh"))
            next_energy = previous_energy
            if energy_added is not None:
                next_energy = max(previous_energy or 0.0, energy_added)

            automatic_cost = None
            session_price = _float(session.get("price_per_kwh"))
            if not bool(session.get("cost_locked")) and session_price is not None and next_energy is not None:
                automatic_cost = round(max(0.0, next_energy) * session_price, 2)

            cur.execute(
                """
                UPDATE charges
                SET energy_added_kwh=
                        CASE
                            WHEN %s IS NULL THEN energy_added_kwh
                            ELSE GREATEST(COALESCE(energy_added_kwh,0),%s)
                        END,
                    end_battery_percent=COALESCE(%s,end_battery_percent),
                    max_power_kw=
                        CASE
                            WHEN %s IS NULL THEN max_power_kw
                            ELSE GREATEST(COALESCE(max_power_kw,0),%s)
                        END,
                    latitude=COALESCE(latitude,%s),
                    longitude=COALESCE(longitude,%s),
                    last_state=%s,
                    last_sample_at=%s,
                    last_active_at=CASE WHEN %s=1 THEN %s ELSE last_active_at END,
                    cost_amount=
                        CASE
                            WHEN cost_locked=0 AND price_per_kwh IS NOT NULL THEN %s
                            ELSE cost_amount
                        END,
                    sample_count=sample_count+1
                WHERE id=%s
                """,
                (
                    energy_added, energy_added,
                    battery,
                    power_kw, power_kw,
                    latitude, longitude,
                    state_raw or None,
                    recorded_at,
                    1 if active else 0, recorded_at,
                    automatic_cost,
                    int(session["id"]),
                ),
            )

            if terminal:
                reason = "complete" if state == "complete" else "disconnected"
                cur.execute(
                    """
                    UPDATE charges
                    SET ended_at=%s,
                        end_reason=%s,
                        last_state=%s,
                        last_sample_at=%s
                    WHERE id=%s AND ended_at IS NULL
                    """,
                    (
                        recorded_at,
                        reason,
                        state_raw or None,
                        recorded_at,
                        int(session["id"]),
                    ),
                )

    def finalize_stale_charges(self) -> int:
        now = datetime.now(timezone.utc)
        closed = 0

        with self.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT c.id, c.last_state, c.last_active_at, c.last_sample_at,
                       v.state AS vehicle_state
                FROM charges c
                JOIN vehicles v ON v.id=c.vehicle_id
                WHERE c.ended_at IS NULL
                  AND c.last_active_at IS NOT NULL
                """
            )
            rows = list(cur.fetchall())

            for row in rows:
                active_at = _as_utc(row.get("last_active_at"))
                if active_at is None:
                    continue

                age = max(0.0, (now - active_at).total_seconds())
                vehicle_state = str(row.get("vehicle_state") or "").lower()

                should_close = age >= 7200
                if vehicle_state in {"asleep", "offline"} and age >= 600:
                    should_close = True

                if not should_close:
                    continue

                ended_at = row.get("last_sample_at") or row.get("last_active_at")
                cur.execute(
                    """
                    UPDATE charges
                    SET ended_at=%s,
                        end_reason='inactive_timeout'
                    WHERE id=%s AND ended_at IS NULL
                    """,
                    (ended_at, int(row["id"])),
                )
                if cur.rowcount:
                    closed += 1

        return closed

    def sync_active_journeys(self) -> dict[str, int]:
        assigned_trips = 0
        assigned_charges = 0

        try:
            with self.connection() as conn, conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id,vehicle_id,started_at
                    FROM journeys
                    WHERE status='active'
                      AND auto_assign=1
                      AND started_at IS NOT NULL
                    ORDER BY id
                    """
                )
                journeys = list(cur.fetchall())

                for journey in journeys:
                    journey_id = int(journey["id"])
                    vehicle_id = int(journey["vehicle_id"])
                    started_at = journey["started_at"]

                    cur.execute(
                        """
                        INSERT INTO journey_trips(journey_id,trip_id,included,assignment_source)
                        SELECT %s,t.id,1,'auto'
                        FROM trips t
                        WHERE t.vehicle_id=%s
                          AND t.started_at>=%s
                          AND NOT EXISTS (
                              SELECT 1
                              FROM journey_trips existing
                              WHERE existing.journey_id=%s
                                AND existing.trip_id=t.id
                          )
                          AND NOT EXISTS (
                              SELECT 1
                              FROM journey_trips other_link
                              WHERE other_link.trip_id=t.id
                                AND other_link.included=1
                                AND other_link.journey_id<>%s
                          )
                        """,
                        (journey_id, vehicle_id, started_at, journey_id, journey_id),
                    )
                    assigned_trips += max(0, int(cur.rowcount or 0))

                    cur.execute(
                        """
                        INSERT INTO journey_charges(journey_id,charge_id,included,assignment_source)
                        SELECT %s,c.id,1,'auto'
                        FROM charges c
                        WHERE c.vehicle_id=%s
                          AND c.started_at>=%s
                          AND NOT EXISTS (
                              SELECT 1
                              FROM journey_charges existing
                              WHERE existing.journey_id=%s
                                AND existing.charge_id=c.id
                          )
                          AND NOT EXISTS (
                              SELECT 1
                              FROM journey_charges other_link
                              WHERE other_link.charge_id=c.id
                                AND other_link.included=1
                                AND other_link.journey_id<>%s
                          )
                        """,
                        (journey_id, vehicle_id, started_at, journey_id, journey_id),
                    )
                    assigned_charges += max(0, int(cur.rowcount or 0))
        except Exception:
            # During a rolling update the worker can briefly run before the
            # web container has applied the journey migration.
            return {"trips": 0, "charges": 0}

        return {"trips": assigned_trips, "charges": assigned_charges}

    @contextmanager
    def named_lock(self, name: str) -> Iterator[bool]:
        conn = self.connect()
        acquired = False
        try:
            with conn.cursor() as cur:
                cur.execute("SELECT GET_LOCK(%s, 0) AS locked", (name,))
                row = cur.fetchone()
                acquired = bool(row and int(row["locked"] or 0) == 1)

            yield acquired
        finally:
            if acquired:
                try:
                    with conn.cursor() as cur:
                        cur.execute("SELECT RELEASE_LOCK(%s)", (name,))
                except Exception:
                    pass
            conn.close()
