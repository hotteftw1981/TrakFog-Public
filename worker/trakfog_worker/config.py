from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Config:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    app_key: str
    poll_online_seconds: int
    poll_sleep_seconds: int
    heartbeat_seconds: int
    tesla_api_host: str
    tesla_auth_host: str
    tesla_wss_host: str
    geocoder_enabled: bool
    geocoder_url: str
    geocoder_user_agent: str
    min_trip_distance_meters: int = 100

    @classmethod
    def from_env(cls) -> "Config":
        required = [
            "TRAKFOG_DB_HOST",
            "TRAKFOG_DB_NAME",
            "TRAKFOG_DB_USER",
            "TRAKFOG_DB_PASSWORD",
        ]
        missing = [name for name in required if not os.getenv(name, "").strip()]
        if missing:
            raise RuntimeError("Missing environment variables: " + ", ".join(missing))

        app_key = os.getenv("TRAKFOG_APP_KEY", "").strip()
        if not app_key:
            key_file = os.getenv("TRAKFOG_APP_KEY_FILE", "/var/lib/trakfog/app_key")
            try:
                with open(key_file, "r", encoding="utf-8") as handle:
                    app_key = handle.read().strip()
            except OSError as exc:
                raise RuntimeError(f"Cannot read TrakFog app key from {key_file}") from exc

        if not app_key:
            raise RuntimeError("TRAKFOG_APP_KEY is empty")

        return cls(
            db_host=os.environ["TRAKFOG_DB_HOST"].strip(),
            db_port=int(os.getenv("TRAKFOG_DB_PORT", "3306")),
            db_name=os.environ["TRAKFOG_DB_NAME"].strip(),
            db_user=os.environ["TRAKFOG_DB_USER"].strip(),
            db_password=os.environ["TRAKFOG_DB_PASSWORD"],
            app_key=app_key,
            poll_online_seconds=max(10, int(os.getenv("TRAKFOG_WORKER_POLL_ONLINE_SECONDS", "30"))),
            poll_sleep_seconds=max(30, int(os.getenv("TRAKFOG_WORKER_POLL_SLEEP_SECONDS", "60"))),
            heartbeat_seconds=max(5, int(os.getenv("TRAKFOG_WORKER_HEARTBEAT_SECONDS", "15"))),
            tesla_api_host=os.getenv("TRAKFOG_TESLA_API_HOST", "https://owner-api.teslamotors.com").rstrip("/"),
            tesla_auth_host=os.getenv("TRAKFOG_TESLA_AUTH_HOST", "https://auth.tesla.com").rstrip("/"),
            tesla_wss_host=os.getenv("TRAKFOG_TESLA_WSS_HOST", "wss://streaming.vn.teslamotors.com").rstrip("/"),
            geocoder_enabled=os.getenv("TRAKFOG_GEOCODER_ENABLED", "1").strip().lower() not in {"0", "false", "no", "off"},
            geocoder_url=os.getenv("TRAKFOG_GEOCODER_URL", "https://nominatim.openstreetmap.org").rstrip("/"),
            geocoder_user_agent=os.getenv(
                "TRAKFOG_GEOCODER_USER_AGENT",
                "TrakFog self-hosted Tesla data platform",
            ).strip(),
            min_trip_distance_meters=max(0, min(1000, int(os.getenv("TRAKFOG_MIN_TRIP_DISTANCE_METERS", "100")))),
        )
