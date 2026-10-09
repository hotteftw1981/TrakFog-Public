from __future__ import annotations

import json
import logging
import signal
import time
from pathlib import Path
from datetime import datetime, timezone
from typing import Any

from .config import Config
from .db import Database
from .geocode import ReverseGeocoder
from .tesla import TeslaClient, TeslaError
from .stream import TeslaStreamSupervisor

LOG = logging.getLogger("trakfog-worker")


def miles_to_km(value: Any) -> float | None:
    try:
        return round(float(value) * 1.609344, 2)
    except (TypeError, ValueError):
        return None


def mph_to_kmh(value: Any) -> float | None:
    try:
        return round(float(value) * 1.609344, 2)
    except (TypeError, ValueError):
        return None


def _float(value: Any) -> float | None:
    try:
        return float(value) if value is not None else None
    except (TypeError, ValueError):
        return None


def app_version() -> str:
    try:
        value = Path("/app/VERSION").read_text(encoding="utf-8").strip()
        return value or "dev"
    except OSError:
        return "dev"


class Worker:
    def __init__(self, config: Config) -> None:
        self.config = config
        self.db = Database(config)
        self.tesla = TeslaClient(config, self.db)
        self.streams = TeslaStreamSupervisor(config, self.db)
        self.geocoder = ReverseGeocoder(config, self.db)
        self.running = True
        self.last_api_poll = 0.0
        self.last_charging_cost_sync = 0.0
        self.last_vehicle_extras_sync = 0.0
        self.last_known_online = False
        self.last_forbidden_refresh = 0.0

    def stop(self, *_args) -> None:
        self.running = False

    def run(self) -> None:
        LOG.info("TrakFog worker starting")
        self.db.heartbeat("starting", {"version": app_version()})
        self.streams.start()

        try:
            while self.running:
                try:
                    self._tick()
                except Exception as exc:
                    LOG.exception("Worker tick failed")
                    self.db.set_setting("worker_last_error", str(exc)[:1000])
                    self.db.heartbeat("error")

                for _ in range(self.config.heartbeat_seconds):
                    if not self.running:
                        break
                    time.sleep(1)
        finally:
            self.streams.stop()
            self.db.heartbeat("stopped")
            LOG.info("TrakFog worker stopped")

    def _tick(self) -> None:
        closed_trips = self.db.finalize_stale_trips()
        if closed_trips:
            LOG.info("Finalized %d stale trip(s)", closed_trips)

        closed_charges = self.db.finalize_stale_charges()
        if closed_charges:
            LOG.info("Finalized %d stale charge session(s)", closed_charges)

        journey_sync = self.db.sync_active_journeys()
        if journey_sync["trips"] or journey_sync["charges"]:
            LOG.info(
                "Journey auto-assignment: trips=%d charges=%d",
                journey_sync["trips"],
                journey_sync["charges"],
            )

        try:
            self.geocoder.tick()
        except Exception:
            LOG.exception("Geo resolver tick failed")

        try:
            self._sync_charging_costs_if_due()
        except Exception as exc:
            LOG.exception("Tesla charging cost sync tick failed")
            self.db.set_setting("tesla_charging_cost_sync_last_error", str(exc)[:1000])

        interval = (
            self.config.poll_online_seconds
            if self.last_known_online
            else self.config.poll_sleep_seconds
        )
        due = (time.monotonic() - self.last_api_poll) >= interval

        if not due:
            # Keep the heartbeat fresh without overwriting the last real poll
            # or replacing the meaningful worker state with a transient "idle".
            self.db.heartbeat()
            return

        self._collect()
        self.last_api_poll = time.monotonic()

    def _sync_charging_costs_if_due(self) -> None:
        if self.db.get_setting("worker_enabled", "1") == "0":
            return

        force = self.db.get_setting("tesla_charging_cost_sync_force", "0") == "1"
        if self.db.get_setting("tesla_charging_cost_sync_enabled", "1") == "0" and not force:
            return
        try:
            interval_minutes = max(
                15,
                int(self.db.get_setting("tesla_charging_cost_sync_interval_minutes", "90") or "90"),
            )
        except (TypeError, ValueError):
            interval_minutes = 90

        if not force and (time.monotonic() - self.last_charging_cost_sync) < interval_minutes * 60:
            return

        integration = self.tesla.integration()
        if not integration or integration.get("status") == "disconnected":
            return

        with self.db.named_lock("trakfog_tesla_charging_cost_sync") as acquired:
            if not acquired:
                return

            if force:
                self.db.set_setting("tesla_charging_cost_sync_force", "0")

            summary = {
                "vehicles": 0,
                "sessions": 0,
                "updated": 0,
                "already_synced": 0,
                "no_match": 0,
                "skipped": 0,
                "ignored": 0,
                "errors": [],
            }

            try:
                for vehicle in self.db.charging_cost_sync_vehicles():
                    vehicle_id = int(vehicle["id"])
                    vin = str(vehicle.get("vin") or "").strip()
                    if not vin:
                        continue

                    summary["vehicles"] += 1
                    backfill_key = f"tesla_cost_backfill_vehicle_{vehicle_id}"
                    backfilled = self.db.get_setting(backfill_key, "0") == "1"
                    page_limit = 1 if backfilled else None

                    try:
                        sessions = self.tesla.charging_history(
                            integration,
                            vin,
                            page_limit=page_limit,
                        )
                    except TeslaError as exc:
                        summary["errors"].append(
                            {
                                "vehicle_id": vehicle_id,
                                "status": exc.status,
                                "message": str(exc)[:300],
                            }
                        )
                        LOG.warning(
                            "Tesla charging history failed for vehicle %s: %s",
                            vehicle_id,
                            exc,
                        )
                        continue

                    summary["sessions"] += len(sessions)
                    for session in sessions:
                        result = self.db.sync_tesla_charging_cost(vehicle_id, session)
                        if result in summary:
                            summary[result] += 1
                        else:
                            summary["skipped"] += 1

                    if not backfilled:
                        self.db.set_setting(backfill_key, "1")

                now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
                self.db.set_setting("tesla_charging_cost_sync_last_at", now)
                self.db.set_setting(
                    "tesla_charging_cost_sync_last_summary",
                    json.dumps(summary, ensure_ascii=False, separators=(",", ":")),
                )
                self.db.set_setting(
                    "tesla_charging_cost_sync_last_error",
                    None if not summary["errors"] else json.dumps(summary["errors"], ensure_ascii=False),
                )
                LOG.info(
                    "Tesla charging cost sync: vehicles=%d sessions=%d updated=%d matched-missing=%d errors=%d",
                    summary["vehicles"],
                    summary["sessions"],
                    summary["updated"],
                    summary["no_match"],
                    len(summary["errors"]),
                )
            finally:
                self.last_charging_cost_sync = time.monotonic()

    def _vehicle_extras_due(self) -> bool:
        if self.db.get_setting("tesla_vehicle_extras_enabled", "1") == "0":
            return False
        try:
            minutes = max(
                60,
                min(
                    1440,
                    int(self.db.get_setting("tesla_vehicle_extras_interval_minutes", "360") or "360"),
                ),
            )
        except (TypeError, ValueError):
            minutes = 360
        return (time.monotonic() - self.last_vehicle_extras_sync) >= minutes * 60

    @staticmethod
    def _age_seconds(value: Any) -> float | None:
        if value is None:
            return None
        if isinstance(value, datetime):
            moment = value
        else:
            try:
                moment = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
            except ValueError:
                return None
        if moment.tzinfo is None:
            moment = moment.replace(tzinfo=timezone.utc)
        return max(0.0, (datetime.now(timezone.utc) - moment.astimezone(timezone.utc)).total_seconds())

    def _known_vehicle_fallback(self) -> list[dict[str, Any]]:
        """Return conservative pseudo-products from the local DB.

        /products is discovery, not our only source of truth. Known vehicles and
        the legacy driving stream can continue to operate when Tesla temporarily
        or permanently rejects discovery. Stale rows are never promoted to
        "online" merely because that was their last stored state.
        """
        products: list[dict[str, Any]] = []
        for row in self.db.known_tesla_vehicles():
            stream_status = str(row.get("stream_status") or "").lower()
            stream_age = self._age_seconds(row.get("stream_last_event_at"))
            seen_age = self._age_seconds(row.get("last_seen_at"))
            stored_state = str(row.get("state") or "").lower()

            if stream_status == "streaming" and stream_age is not None and stream_age <= 150:
                effective_state = "online"
            elif stream_status in {"offline", "waiting_vehicle"}:
                effective_state = "asleep"
            elif stored_state in {"asleep", "offline"}:
                effective_state = stored_state
            elif stored_state == "online" and seen_age is not None and seen_age <= 180:
                effective_state = "online"
            else:
                effective_state = "unknown"

            products.append(
                {
                    "id_s": str(row.get("external_id") or ""),
                    "vehicle_id": str(row.get("vehicle_id") or ""),
                    "vin": row.get("vin"),
                    "display_name": row.get("display_name") or "Tesla",
                    "state": effective_state,
                    "_trakfog_source": "known_vehicle_fallback",
                    "_trakfog_local_id": row.get("id"),
                }
            )
        return products

    def _collect(self) -> None:
        enabled = self.db.get_setting("worker_enabled", "1")
        if enabled == "0":
            self.db.heartbeat("paused")
            return

        integration = self.tesla.integration()
        if not integration or integration.get("status") == "disconnected":
            self.last_known_online = False
            self.db.heartbeat("waiting_for_tesla")
            return

        with self.db.named_lock("trakfog_worker_collect") as acquired:
            if not acquired:
                self.db.heartbeat("busy")
                return

            products: list[dict[str, Any]] | None = None
            products_issue: dict[str, Any] | None = None
            using_fallback = False
            refresh_attempted = False
            refresh_recovered = False

            try:
                products = self.tesla.products(integration)
            except TeslaError as exc:
                final_exc: Exception = exc
                initial_diagnostic = {
                    **exc.diagnostic(),
                    "message": str(exc)[:300],
                    "at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
                    "source": "worker",
                    "token_expires_at": integration.get("token_expires_at"),
                    "refresh_attempted": False,
                    "refresh_recovered": False,
                    "retry_result": "not_attempted",
                    "fallback_active": False,
                }

                # June 2026 Tesla transport changes made token refresh behavior
                # sensitive to HTTP/2 + TLS 1.3. A saved pre-upgrade access token
                # can therefore keep returning 403 until it is minted again over
                # the corrected auth transport. Try this once per six hours, then
                # fall back to known vehicles instead of killing the whole poll.
                can_retry_403 = (
                    exc.status == 403
                    and (
                        self.last_forbidden_refresh == 0.0
                        or (time.monotonic() - self.last_forbidden_refresh) >= 21600
                    )
                )
                if can_retry_403:
                    refresh_attempted = True
                    self.last_forbidden_refresh = time.monotonic()
                    refresh_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
                    self.db.set_setting("tesla_403_refresh_last_at", refresh_at)
                    try:
                        integration = self.tesla.refresh(self.tesla.integration() or integration)
                        products = self.tesla.products(integration)
                        refresh_recovered = True
                        self.db.set_setting("tesla_403_refresh_last_result", "recovered")
                        recovered_diagnostic = {
                            **initial_diagnostic,
                            "refresh_attempted": True,
                            "refresh_recovered": True,
                            "retry_result": "success",
                            "fallback_active": False,
                            "token_expires_at": integration.get("token_expires_at"),
                            "resolved": True,
                        }
                        self.db.set_setting(
                            "tesla_api_last_diagnostic",
                            json.dumps(recovered_diagnostic, ensure_ascii=False, separators=(",", ":")),
                        )
                        LOG.info(
                            "Tesla products recovered after forced refresh: %s",
                            json.dumps(recovered_diagnostic, ensure_ascii=False, separators=(",", ":")),
                        )
                    except Exception as retry_exc:
                        final_exc = retry_exc
                        retry_diagnostic = (
                            retry_exc.diagnostic()
                            if isinstance(retry_exc, TeslaError)
                            else {"status": 0, "area": "auth_or_products", "endpoint": None}
                        )
                        initial_diagnostic["refresh_attempted"] = True
                        initial_diagnostic["retry_result"] = "failed"
                        initial_diagnostic["retry"] = {
                            **retry_diagnostic,
                            "message": str(retry_exc)[:300],
                        }
                        self.db.set_setting(
                            "tesla_403_refresh_last_result",
                            ("failed: " + str(retry_exc))[:500],
                        )

                if products is None:
                    status = final_exc.status if isinstance(final_exc, TeslaError) else 0
                    if status in (403, 408, 412, 429) or exc.status in (403, 408, 412, 429):
                        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
                        final_diagnostic = (
                            final_exc.diagnostic()
                            if isinstance(final_exc, TeslaError)
                            else initial_diagnostic
                        )
                        products_issue = {
                            **initial_diagnostic,
                            **final_diagnostic,
                            "area": "products",
                            "status": status or exc.status,
                            "message": str(final_exc)[:300],
                            "at": now,
                            "token_expires_at": (self.tesla.integration() or integration).get("token_expires_at"),
                            "refresh_attempted": refresh_attempted,
                            "refresh_recovered": refresh_recovered,
                            "retry_result": "failed" if refresh_attempted and not refresh_recovered else "not_attempted",
                            "fallback_active": True,
                            "resolved": False,
                        }
                        self.db.set_setting(
                            "tesla_products_last_error",
                            json.dumps(products_issue, ensure_ascii=False, separators=(",", ":")),
                        )
                        self.db.set_setting(
                            "tesla_api_last_diagnostic",
                            json.dumps(products_issue, ensure_ascii=False, separators=(",", ":")),
                        )
                        products = self._known_vehicle_fallback()
                        using_fallback = True
                        LOG.warning(
                            "Tesla products unavailable; using %d known vehicle(s) as fallback: %s",
                            len(products),
                            final_exc,
                        )
                    else:
                        raise final_exc

            if products is None:
                products = []

            if not using_fallback:
                self.db.set_setting("tesla_products_last_error", None)
                self._sync_products(int(integration["id"]), products)

            online_products = [
                item for item in products
                if str(item.get("state") or "").lower() == "online"
            ]
            self.last_known_online = bool(online_products)

            snapshots = 0
            errors: list[dict[str, Any]] = []
            extras_due = self._vehicle_extras_due()
            extras_attempted = 0
            extras_errors: list[dict[str, Any]] = []

            for product in online_products:
                external_id = str(product.get("id_s") or product.get("id") or "")
                if not external_id:
                    continue

                try:
                    data = self.tesla.vehicle_data(integration, external_id)
                    vehicle_db_id = self._store_vehicle_data(external_id, data)
                    snapshots += 1

                    if extras_due and vehicle_db_id is not None:
                        payloads, optional_errors = self.tesla.vehicle_extras(
                            self.tesla.integration() or integration,
                            external_id,
                        )
                        extras_attempted += 1
                        extras_errors.extend(
                            [{"vehicle": external_id, **entry} for entry in optional_errors]
                        )
                        self.db.upsert_vehicle_extras(
                            vehicle_db_id,
                            payloads,
                            json.dumps(optional_errors, ensure_ascii=False)
                            if optional_errors
                            else None,
                        )
                except TeslaError as exc:
                    vehicle_error = {
                        "vehicle": external_id,
                        **exc.diagnostic(),
                        "message": str(exc)[:300],
                        "at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
                        "source": "worker",
                        "token_expires_at": (self.tesla.integration() or integration).get("token_expires_at"),
                        "fallback_active": False,
                        "resolved": False,
                    }
                    if exc.status not in (408, 429):
                        errors.append(vehicle_error)
                    self.db.set_setting(
                        "tesla_api_last_diagnostic",
                        json.dumps(vehicle_error, ensure_ascii=False, separators=(",", ":")),
                    )
                    LOG.warning(
                        "Vehicle data failed for %s: %s",
                        external_id,
                        json.dumps(vehicle_error, ensure_ascii=False, separators=(",", ":")),
                    )

            now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            if extras_attempted:
                self.last_vehicle_extras_sync = time.monotonic()
                self.db.set_setting("tesla_vehicle_extras_last_at", now)
                self.db.set_setting(
                    "tesla_vehicle_extras_last_error",
                    None
                    if not extras_errors
                    else json.dumps(extras_errors, ensure_ascii=False, separators=(",", ":")),
                )

            successful_rest_data = (not using_fallback) or snapshots > 0
            with self.db.connection() as conn, conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE integrations
                    SET status='connected',
                        last_sync_at=CASE WHEN %s=1 THEN %s ELSE last_sync_at END,
                        last_error=%s
                    WHERE id=%s
                    """,
                    (
                        1 if successful_rest_data else 0,
                        now,
                        None if not errors else f"{len(errors)} worker error(s)",
                        int(integration["id"]),
                    ),
                )

            states = {str(item.get("state") or "").lower() for item in products}
            if online_products:
                worker_state = "online"
            elif errors:
                worker_state = "degraded"
            elif states & {"asleep", "offline"}:
                worker_state = "sleeping"
            elif using_fallback:
                worker_state = "stale"
            else:
                worker_state = "sleeping"

            detail = {
                "vehicles": len(products),
                "online": len(online_products),
                "snapshots": snapshots,
                "errors": errors,
                "products_source": "known_vehicles_fallback" if using_fallback else "tesla_products",
                "products_error": products_issue,
                "forced_refresh": {
                    "attempted": refresh_attempted,
                    "recovered": refresh_recovered,
                },
                "extras_synced": extras_attempted,
                "extras_errors": len(extras_errors),
                "polled_at": now,
            }
            self.db.set_setting("worker_last_error", None if not errors else json.dumps(errors))
            self.db.heartbeat(worker_state, detail)
            LOG.info(
                "Poll complete: vehicles=%d online=%d snapshots=%d source=%s state=%s",
                len(products),
                len(online_products),
                snapshots,
                detail["products_source"],
                worker_state,
            )

    def _sync_products(self, integration_id: int, products: list[dict[str, Any]]) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        observations: list[dict[str, Any]] = []

        with self.db.connection() as conn, conn.cursor() as cur:
            for vehicle in products:
                external = str(vehicle.get("id_s") or vehicle.get("id") or "")
                if not external:
                    continue

                vehicle_state = vehicle.get("vehicle_state")
                if not isinstance(vehicle_state, dict):
                    vehicle_state = {}

                display_name = (
                    vehicle_state.get("vehicle_name")
                    or vehicle.get("display_name")
                    or "Tesla"
                )

                cur.execute(
                    """
                    SELECT id,state,battery_level,rated_range_km,odometer_km
                    FROM vehicles
                    WHERE source_type='tesla_owner_api' AND external_id=%s
                    LIMIT 1
                    """,
                    (external,),
                )
                previous = cur.fetchone()

                cur.execute(
                    """
                    INSERT INTO vehicles(
                        integration_id, source_type, external_id, vehicle_id,
                        vin, display_name, state, last_seen_at, raw_json
                    )
                    VALUES(%s,'tesla_owner_api',%s,%s,%s,%s,%s,%s,%s)
                    ON DUPLICATE KEY UPDATE
                        integration_id=VALUES(integration_id),
                        vehicle_id=VALUES(vehicle_id),
                        vin=VALUES(vin),
                        display_name=VALUES(display_name),
                        state=VALUES(state),
                        last_seen_at=VALUES(last_seen_at)
                    """,
                    (
                        integration_id,
                        external,
                        str(vehicle.get("vehicle_id") or ""),
                        vehicle.get("vin"),
                        display_name,
                        vehicle.get("state"),
                        now,
                        json.dumps(vehicle, ensure_ascii=False, separators=(",", ":")),
                    ),
                )

                cur.execute(
                    """
                    SELECT id,state,battery_level,rated_range_km,odometer_km
                    FROM vehicles
                    WHERE source_type='tesla_owner_api' AND external_id=%s
                    LIMIT 1
                    """,
                    (external,),
                )
                current = cur.fetchone()
                if not current:
                    continue

                observations.append(
                    {
                        "vehicle_id": int(current["id"]),
                        "previous_state": previous.get("state") if previous else None,
                        "current_state": vehicle.get("state"),
                        "battery_level": _float(previous.get("battery_level")) if previous else _float(current.get("battery_level")),
                        "range_km": _float(previous.get("rated_range_km")) if previous else _float(current.get("rated_range_km")),
                        "odometer_km": _float(previous.get("odometer_km")) if previous else _float(current.get("odometer_km")),
                    }
                )

        for observation in observations:
            self.db.observe_vehicle_state(
                observation["vehicle_id"],
                now,
                observation["previous_state"],
                observation["current_state"],
                battery_level=observation["battery_level"],
                range_km=observation["range_km"],
                odometer_km=observation["odometer_km"],
            )

    def _store_vehicle_data(self, external_id: str, data: dict[str, Any]) -> int | None:
        drive = data.get("drive_state") if isinstance(data.get("drive_state"), dict) else {}
        charge = data.get("charge_state") if isinstance(data.get("charge_state"), dict) else {}
        state = data.get("vehicle_state") if isinstance(data.get("vehicle_state"), dict) else {}

        odometer_km = miles_to_km(state.get("odometer"))
        battery_level = charge.get("battery_level")
        usable_battery = charge.get("usable_battery_level")
        rated_range_km = miles_to_km(charge.get("battery_range"))
        ideal_range_km = miles_to_km(charge.get("ideal_battery_range"))
        speed_kmh = mph_to_kmh(drive.get("speed"))
        fetch_mode = str(data.pop("_trakfog_fetch_mode", "full") or "full")
        raw_json = json.dumps(data, ensure_ascii=False, separators=(",", ":"))
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        if fetch_mode != "full":
            LOG.info("Vehicle data fallback used for %s: %s", external_id, fetch_mode)
        vehicle_db_id: int | None = None

        with self.db.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                UPDATE vehicles
                SET state=COALESCE(%s,state),
                    odometer_km=COALESCE(%s,odometer_km),
                    battery_level=COALESCE(%s,battery_level),
                    usable_battery_level=COALESCE(%s,usable_battery_level),
                    rated_range_km=COALESCE(%s,rated_range_km),
                    ideal_range_km=COALESCE(%s,ideal_range_km),
                    latitude=COALESCE(%s,latitude),
                    longitude=COALESCE(%s,longitude),
                    heading=COALESCE(%s,heading),
                    speed_kmh=COALESCE(%s,speed_kmh),
                    last_seen_at=%s,
                    raw_json=%s
                WHERE source_type='tesla_owner_api' AND external_id=%s
                """,
                (
                    data.get("state"),
                    odometer_km,
                    battery_level,
                    usable_battery,
                    rated_range_km,
                    ideal_range_km,
                    drive.get("latitude"),
                    drive.get("longitude"),
                    drive.get("heading"),
                    speed_kmh,
                    now,
                    raw_json,
                    external_id,
                ),
            )

            cur.execute(
                """
                SELECT id FROM vehicles
                WHERE source_type='tesla_owner_api' AND external_id=%s
                LIMIT 1
                """,
                (external_id,),
            )
            row = cur.fetchone()
            if not row:
                return None

            vehicle_db_id = int(row["id"])

            cur.execute(
                """
                INSERT INTO vehicle_snapshots(
                    vehicle_id, recorded_at, latitude, longitude, speed_kmh,
                    heading, battery_level, usable_battery_level, range_km,
                    odometer_km, power_kw, charging_state, raw_json
                )
                VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """,
                (
                    vehicle_db_id,
                    now,
                    drive.get("latitude"),
                    drive.get("longitude"),
                    speed_kmh,
                    drive.get("heading"),
                    battery_level,
                    usable_battery,
                    rated_range_km,
                    odometer_km,
                    drive.get("power"),
                    charge.get("charging_state"),
                    raw_json,
                ),
            )

        if vehicle_db_id is not None:
            self.db.update_charge_from_rest(vehicle_db_id, now, charge, drive)
            self.db.finalize_sleep_session(
                vehicle_db_id,
                now,
                end_state=str(data.get("state") or "online"),
                battery_level=_float(battery_level),
                range_km=_float(rated_range_km),
                odometer_km=_float(odometer_km),
            )

        return vehicle_db_id


def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    config = Config.from_env()
    worker = Worker(config)
    signal.signal(signal.SIGTERM, worker.stop)
    signal.signal(signal.SIGINT, worker.stop)
    worker.run()
