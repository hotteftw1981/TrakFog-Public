from __future__ import annotations

from datetime import datetime, timezone

from trakfog_worker.config import Config
from trakfog_worker.db import Database


def main() -> int:
    try:
        config = Config.from_env()
        db = Database(config)
        value = db.get_setting("worker_last_heartbeat")
        if not value:
            return 1

        stamp = datetime.strptime(value, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
        age = (datetime.now(timezone.utc) - stamp).total_seconds()
        limit = max(180, config.poll_sleep_seconds * 3)
        return 0 if age <= limit else 1
    except Exception:
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
