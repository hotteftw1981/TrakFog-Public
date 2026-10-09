# TrakFog – Datenmigration

## Ziel

TrakFog soll historische Fahrzeugdaten aus anderen Diensten übernehmen und eigene Daten wieder in einem dokumentierten Format exportieren können. Die Migration ist **optional**, **nicht destruktiv** und zunächst nur als Vorschau zu entwickeln.

## Adapter-Roadmap

| Quelle | Eingabe | Priorität |
|---|---|---|
| TeslaMate | PostgreSQL-Backup / strukturierter Export | P1 |
| TeslaLogger | MySQL-Backup / Geofence-Export | P1 |
| TeslaFi | CSV-Export | P2 |
| Tessie | Datenexport | P2 |
| Teslascope | verfügbarer Export | P3 |
| TRONITY | CSV/XLS(X) | P3 |
| TezLab | CSV | P3 |
| Eigene Skripte / TeslaPy | generisches CSV/JSON | P3 |

Die Quellformate müssen anhand realer, versionierter Testdateien geprüft werden. Keine Behauptung einer vollständigen Kompatibilität vor einem erfolgreichen Test.

## Architektur

1. Upload in ein isoliertes temporäres Verzeichnis mit Größen- und Typprüfung.
2. Quellformat erkennen; niemals hochgeladene SQL-Dateien oder Archive direkt gegen die Produktivdatenbank ausführen.
3. Adapter erzeugt ein gemeinsames, validiertes Zwischenformat.
4. Vorschau mit Fahrzeugzuordnung, Zeitraum, Datensätzen, Warnungen und Dubletten.
5. Bestätigung und Import in kontrollierten Transaktionen.
6. Abschlussbericht mit übernommenen, übersprungenen und fehlerhaften Datensätzen.

## Gemeinsames Datenmodell

- vehicles: VIN, Quelle, Name, Modell
- trips: Start/Ende, Distanz, Energie, Fahrzeug
- positions: Zeitstempel, Breite/Länge, Geschwindigkeit, Kilometerstand, Ladezustand
- charges: Start/Ende, Energie, Ladeort, Kosten, Währung
- places: Name, Koordinaten, optionale Geofence-Geometrie
- states: Schlaf-/Wach-/Parkzustände
- provenance: Quelldatei, Quell-ID, Importlauf, Quellversion

Alle Zeitangaben werden mit expliziter Zeitzonenbehandlung normalisiert; Einheiten werden dokumentiert. Fehlende Werte bleiben null und werden nicht erfunden.

## Sicherheits- und Qualitätsregeln

- Vor dem ersten schreibenden Import ein Datenbankbackup empfehlen und Integrität prüfen.
- VIN-Zuordnung nie stillschweigend erzwingen.
- Dubletten anhand von Quelle/Original-ID und plausiblen Zeit-/Distanz-/Positionsmerkmalen erkennen.
- Wiederholte Importe müssen idempotent sein.
- Rohdaten und sensible Standortdaten nicht in Logs schreiben.
- Große Importe in Batches mit Fortschritt, Wiederaufnahme und Abbruch bearbeiten.
- Importierte Historie darf Live-Polling und aktuelle Fahrzeugzustände nicht überschreiben.
- Export als versioniertes, dokumentiertes TrakFog-Archiv mit CSV/JSON und Manifest.

## Umsetzungsetappen

1. Universelles Austauschformat und Validierungsregeln.
2. TeslaMate-Adapter mit Vorschau und Testfixtures.
3. TeslaLogger-Adapter.
4. CSV-Adapter (TeslaFi/Tessie).
5. Import-UI unter System → Datenmigration und Einstieg im Installationsassistenten.
6. Export und Wiederherstellung in anderer TrakFog-Installation.

**Status:** Architektur und Roadmap angelegt. Es gibt noch keinen produktiven Import oder Export.
