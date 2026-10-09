# TrakFog database migrations

Database changes live here as versioned `.sql` files.

- Existing Docker installations apply missing migrations automatically during container startup.
- Each migration is executed exactly once and recorded in `schema_migrations`.
- Fresh installations use the current Tesla-only schema in `database/install.sql`.

Current base schema: **V0.1.1.12**. Newer features are layered by startup migrations up to **V0.1.1.65**.

- `0.1.1.15.sql` — automatische Tesla-Fahrterkennung / Trip-Telemetrie

- `0.1.1.16.sql` — automatische Tesla-Ladeerkennung / Charge-Session-Metadaten

- `0.1.1.21.sql` — Ladetarife, Preis-Snapshots und Kostenquelle je Ladesession

- `0.1.1.23.sql` — Fahrzeug-Zustandswechsel, Schlafsessions und Phantom-Drain-Auswertung

- `0.1.1.25.sql` — Reverse-Geocoding-Cache und eigene Geobereiche / Geofences

- `0.1.1.28.sql` — Geofence-Formen: kleine Radiusbereiche und frei gezeichnete Polygonflächen

- `0.1.1.30.sql` — Reisen/Touren und Zuordnung von Fahrten/Ladevorgängen

- `0.1.1.31.sql` — LiveView-PIN, vertrauenswürdige Geräte und Zugriffsschutz

- `0.1.1.36.sql` — Tesla Charging History / automatische Supercharger-Kosten und Tesla-Session-Metadaten

- `0.1.1.65.sql` — optionaler manueller VoltCore-Zählerwert und Session-Referenz je TrakFog-Ladesession (keine automatische Rücksynchronisation).
