from __future__ import annotations

import json
import logging
import threading
import time
from datetime import datetime, timezone
from typing import Any

from websockets.exceptions import ConnectionClosed
from websockets.sync.client import connect

from .config import Config
from .db import Database
from .tesla import TeslaClient, TeslaError

LOG = logging.getLogger("trakfog-stream")

STREAM_COLUMNS = [
    "speed",
    "odometer",
    "soc",
    "elevation",
    "est_heading",
    "est_lat",
    "est_lng",
    "power",
    "shift_state",
    "range",
    "est_range",
    "heading",
]


def _number(value: Any) -> float | None:
    if value is None:
        return None
    text = str(value).strip()
    if text == "" or text.lower() in {"null", "none"}:
        return None
    try:
        return float(text)
    except (TypeError, ValueError):
        return None


def _miles_to_km(value: Any) -> float | None:
    number = _number(value)
    return round(number * 1.609344, 3) if number is not None else None


def _mph_to_kmh(value: Any) -> float | None:
    number = _number(value)
    return round(number * 1.609344, 2) if number is not None else None


def _timestamp(value: Any) -> str:
    number = _number(value)
    if number is None:
        moment = datetime.now(timezone.utc)
    else:
        moment = datetime.fromtimestamp(number / 1000.0, tz=timezone.utc)
    return moment.strftime("%Y-%m-%d %H:%M:%S.%f")[:-3]


def parse_stream_value(value: str) -> dict[str, Any]:
    parts = value.split(",")
    expected = 1 + len(STREAM_COLUMNS)
    if len(parts) < expected:
        parts.extend([""] * (expected - len(parts)))

    row = dict(zip(["time", *STREAM_COLUMNS], parts[:expected]))
    shift_state = str(row.get("shift_state") or "").strip() or None

    return {
        "recorded_at": _timestamp(row.get("time")),
        "speed_kmh": _mph_to_kmh(row.get("speed")),
        "odometer_km": _miles_to_km(row.get("odometer")),
        "soc": _number(row.get("soc")),
        "elevation_m": _number(row.get("elevation")),
        "est_heading": _number(row.get("est_heading")),
        "latitude": _number(row.get("est_lat")),
        "longitude": _number(row.get("est_lng")),
        "power_kw": _number(row.get("power")),
        "shift_state": shift_state,
        "range_km": _miles_to_km(row.get("range")),
        "est_range_km": _miles_to_km(row.get("est_range")),
        "heading": _number(row.get("heading")),
        "raw_value": value,
    }


class VehicleStream:
    def __init__(self, config: Config, db: Database, vehicle: dict[str, Any]) -> None:
        self.config = config
        self.db = db
        self.vehicle = vehicle
        self.vehicle_db_id = int(vehicle["id"])
        self.vehicle_id = str(vehicle["vehicle_id"])
        self.name = str(vehicle.get("display_name") or "Tesla")
        self.stop_event = threading.Event()
        self.thread = threading.Thread(
            target=self.run,
            name=f"trakfog-stream-{self.vehicle_db_id}",
            daemon=True,
        )
        self.tesla = TeslaClient(config, db)
        self.force_refresh = False

    def start(self) -> None:
        self.thread.start()

    def stop(self) -> None:
        self.stop_event.set()

    def join(self, timeout: float = 8.0) -> None:
        self.thread.join(timeout=timeout)

    def _wait(self, seconds: float) -> bool:
        return self.stop_event.wait(seconds)

    def _url(self) -> str:
        return self.config.tesla_wss_host.rstrip("/") + "/streaming/"

    def _access_token(self) -> str:
        integration = self.tesla.integration()
        if not integration or integration.get("status") == "disconnected":
            raise TeslaError(401, "Tesla integration is disconnected")

        token = self.tesla.access_token(
            integration,
            force_refresh=self.force_refresh,
        )
        self.force_refresh = False
        return token

    def _subscribe(self, websocket, token: str) -> None:
        websocket.send(
            json.dumps(
                {
                    "msg_type": "data:subscribe_oauth",
                    "token": token,
                    "value": ",".join(STREAM_COLUMNS),
                    "tag": self.vehicle_id,
                },
                separators=(",", ":"),
            )
        )

    def _handle_error(self, message: dict[str, Any]) -> str:
        error_type = str(message.get("error_type") or "")
        value = str(message.get("value") or "")
        short_error = (value or error_type or "Tesla stream error")[:500]

        if error_type == "vehicle_disconnected":
            self.db.set_stream_status(self.vehicle_db_id, "waiting_vehicle", last_error=None)
            return "resubscribe"

        if error_type == "vehicle_error":
            status = "offline" if "offline" in value.lower() else "vehicle_error"
            self.db.set_stream_status(
                self.vehicle_db_id,
                status,
                last_error=None if status == "offline" else short_error,
            )
            return "reconnect"

        if error_type == "client_error":
            if "validate token" in value.lower() or "unauthorized" in value.lower():
                self.force_refresh = True
                self.db.set_stream_status(
                    self.vehicle_db_id,
                    "auth_refresh",
                    last_error="Tesla Streaming Token wird erneuert.",
                )
            else:
                self.db.set_stream_status(
                    self.vehicle_db_id,
                    "client_error",
                    last_error=short_error,
                )
            return "reconnect"

        self.db.set_stream_status(self.vehicle_db_id, "error", last_error=short_error)
        return "reconnect"

    def _connect_once(self) -> None:
        token = self._access_token()
        self.db.set_stream_status(self.vehicle_db_id, "connecting", last_error=None)

        with connect(
            self._url(),
            open_timeout=15,
            close_timeout=5,
            ping_interval=20,
            ping_timeout=20,
            max_size=1024 * 1024,
        ) as websocket:
            connected_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            self.db.set_stream_status(
                self.vehicle_db_id,
                "connected",
                connected_at=connected_at,
                last_error=None,
            )
            self._subscribe(websocket, token)
            self.db.set_stream_status(
                self.vehicle_db_id,
                "waiting_data",
                connected_at=connected_at,
                last_error=None,
            )

            last_message = time.monotonic()
            disconnects = 0

            while not self.stop_event.is_set():
                try:
                    raw = websocket.recv(timeout=5)
                except TimeoutError:
                    if time.monotonic() - last_message >= 30:
                        LOG.info(
                            "No Tesla stream frame for 30s on %s; reconnecting",
                            self.name,
                        )
                        self.db.set_stream_status(
                            self.vehicle_db_id,
                            "reconnecting",
                            last_error=None,
                        )
                        return
                    continue

                if isinstance(raw, bytes):
                    raw = raw.decode("utf-8", errors="replace")

                last_message = time.monotonic()
                try:
                    message = json.loads(raw)
                except (TypeError, json.JSONDecodeError):
                    LOG.warning("Ignoring invalid Tesla stream frame for %s", self.name)
                    continue

                msg_type = str(message.get("msg_type") or "")

                if msg_type == "control:hello":
                    continue

                if msg_type == "data:update":
                    if str(message.get("tag") or "") != self.vehicle_id:
                        continue
                    value = message.get("value")
                    if not isinstance(value, str):
                        continue

                    sample = parse_stream_value(value)
                    self.db.store_stream_sample(self.vehicle_db_id, sample)
                    disconnects = 0
                    continue

                if msg_type == "data:error":
                    if str(message.get("tag") or "") not in {"", self.vehicle_id}:
                        continue

                    action = self._handle_error(message)
                    if action == "resubscribe":
                        disconnects += 1
                        delay = min(30.0, max(5.0, 5.0 * (1.35 ** min(disconnects, 8))))
                        if self._wait(delay):
                            return
                        token = self._access_token()
                        self._subscribe(websocket, token)
                        last_message = time.monotonic()
                        continue

                    return

    def run(self) -> None:
        LOG.info("Tesla stream starting for %s", self.name)
        reconnect_delay = 3.0

        while not self.stop_event.is_set():
            try:
                self._connect_once()
                reconnect_delay = 3.0
            except TeslaError as exc:
                LOG.warning("Tesla stream auth/API error for %s: %s", self.name, exc)
                self.db.set_stream_status(
                    self.vehicle_db_id,
                    "waiting_for_tesla",
                    last_error=str(exc)[:500],
                )
                reconnect_delay = 15.0
            except ConnectionClosed as exc:
                LOG.info("Tesla stream disconnected for %s: %s", self.name, exc)
                self.db.set_stream_status(self.vehicle_db_id, "reconnecting", last_error=None)
            except Exception as exc:
                LOG.exception("Tesla stream failed for %s", self.name)
                self.db.set_stream_status(
                    self.vehicle_db_id,
                    "error",
                    last_error=str(exc)[:500],
                )
                reconnect_delay = min(30.0, max(5.0, reconnect_delay * 1.5))

            if self._wait(reconnect_delay):
                break

        self.db.set_stream_status(self.vehicle_db_id, "stopped", last_error=None)
        LOG.info("Tesla stream stopped for %s", self.name)


class TeslaStreamSupervisor:
    def __init__(self, config: Config, db: Database) -> None:
        self.config = config
        self.db = db
        self.stop_event = threading.Event()
        self.thread = threading.Thread(
            target=self._run,
            name="trakfog-stream-supervisor",
            daemon=True,
        )
        self.streams: dict[int, VehicleStream] = {}

    def start(self) -> None:
        self.thread.start()

    def stop(self) -> None:
        self.stop_event.set()
        for stream in list(self.streams.values()):
            stream.stop()
        self.thread.join(timeout=10)
        for stream in list(self.streams.values()):
            stream.join(timeout=5)

    def _stop_all(self) -> None:
        for stream in list(self.streams.values()):
            stream.stop()
        for stream in list(self.streams.values()):
            stream.join(timeout=5)
        self.streams.clear()

    def _sync_streams(self) -> None:
        vehicles = self.db.stream_vehicles()
        wanted = {int(vehicle["id"]): vehicle for vehicle in vehicles}

        for vehicle_id in list(self.streams):
            if vehicle_id not in wanted:
                stream = self.streams.pop(vehicle_id)
                stream.stop()
                stream.join(timeout=5)

        for vehicle_id, vehicle in wanted.items():
            existing = self.streams.get(vehicle_id)
            if existing and existing.thread.is_alive():
                continue

            if existing:
                existing.stop()
                existing.join(timeout=2)

            stream = VehicleStream(self.config, self.db, vehicle)
            self.streams[vehicle_id] = stream
            stream.start()

    def _run(self) -> None:
        LOG.info("Tesla stream supervisor starting")

        while not self.stop_event.is_set():
            try:
                if self.db.get_setting("worker_enabled", "1") == "0":
                    self._stop_all()
                else:
                    self._sync_streams()
            except Exception:
                LOG.exception("Tesla stream supervisor cycle failed")

            self.stop_event.wait(10)

        self._stop_all()
        LOG.info("Tesla stream supervisor stopped")
