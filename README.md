<div align="center">

<img src="docs/assets/trakfog-logo.svg" width="560" alt="TrakFog">

**Your Tesla. Your data. Your history.**

Selbst gehostete Tesla-Datenplattform für **Live-Telemetrie, Fahrten, Laden, Karten, Reisen und Langzeitauswertungen**.

**V0.1.1.91** · Public Beta · Docker / Portainer · **AGPL-3.0-only**

[🚘 Modellbilder & verbundene Fahrten](#modellbilder--verbundene-fahrten) · [🚀 Installation](#installation) · [🐳 Docker](docker/DOCKER.md) · [🧰 Portainer](docker/PORTAINER.md) · [↔ Datenmigration](#data-migration) · [🔗 Integration API](#integration-api) · [📺 LiveView](#liveview-access) · [⬆️ Releases & Updates](#releases) · [🧭 Roadmap](#roadmap) · [📜 Changelog](CHANGELOG.md) · [⚖️ Lizenz](#license)

</div>

> [!IMPORTANT]
> **Erste öffentliche Beta:** Der Code ist aus einem frischen, bereinigten Snapshot aufgebaut. Die sechs Fahrzeugbilder sind für TrakFog eigens generierte, realistische Studioillustrationen im AVIF-Format – **keine von Tesla übernommenen Fotos, Renderings oder Marketingmaterialien**. Das private Entwicklungsrepository und seine historische Bildsammlung sind nicht enthalten. Details: [Public-Beta-Freigabe](docs/PUBLIC-BETA-CHECKLIST.md) · [Datenschutz & Datenflüsse](docs/PRIVACY.md) · [Security](SECURITY.md) · [Grafik-Herkunft](docs/ASSET-ORIGINS.md).
>
> Ein neuer Owner darf **nur** mit dem lokal gespeicherten Installationsschlüssel angelegt werden. Der Schlüssel bleibt privat; er ist weder Tesla-Token noch Benutzerpasswort.

## Auf einen Blick

| 🚘 Live & Fahrten | ⚡ Laden & Kosten | 🗺️ Karte & Reisen |
|---|---|---|
| Live-Telemetrie und automatische Fahrterkennung | Ladekurven, Energie und Supercharger-Kostensync | Live-Position, Fahrtrouten, Geofences und Reisen |
| 😴 **Sleep & Phantom Drain** | 📺 **LiveView & NerdView** | ⬆️ **Stable Self-Updates** |
| Schlafsessions und qualifizierte Drain-Auswertung | Tesla-Browser, Smartphone, Tablet und TV | GitHub Releases + integrierter Docker-Updater |

### Release-Kanäle

| Kanal | Zweck |
|---|---|
| `stable` | geprüfte Community-/Release-Stände |
| `main` | aktive Entwicklung und Teststände |

---


### Dashboard-Kacheln, Messqualität und LiveMap

- Die KPI-Karten auf dem Dashboard und auf den Unterseiten sind als **echte Links** umgesetzt. Sie führen zur zugehörigen Liste bzw. Auswertung; die Pfeile und Tastatur-Fokusmarkierung zeigen dies an.
- **Geschwindigkeit:** TrakFog zeigt in der Statistik die **höchste erfasste Stream-Geschwindigkeit pro Zeitfenster**. Früher wurde das 2-Minuten-Mittel geplottet; damit wurden Peaks unterschätzt. Eine stundenlange Lücke im Tesla-Stream wird nicht mehr als durchgängige Strecke im Diagramm dargestellt, sondern die Linie wird unterbrochen. Angezeigt werden die tatsächlich vorliegenden Messpunkte und deren Anzahl. **Ein höheres Tempo zwischen den übermittelten Messpunkten lässt sich nicht zuverlässig rekonstruieren**; der angezeigte Wert ist kein garantiertes Fahrtmaximum.
- Die bestehenden Tesla-Streaming-Verbindungen werden weiterverwendet, **ohne zusätzlichem Polling**. Mehr REST-Anfragen garantieren keine höheren Messraten und könnten den Wagen aus dem Schlaf holen.
- Die LiveView-Karte lässt sich nun wieder mit Maus oder Touch verschieben. Manuelles Verschieben pausiert den automatischen Follow-Modus; über **„◎ Tesla folgen“** springt die Karte zurück zum Wagen und zentriert ihn künftig wieder. Die gewählte Zoomstufe bleibt erhalten.

## ✨ Was TrakFog heute kann

### 🚘 Fahrzeug & Live
- Fahrzeugzustand: online, schläft, fährt, lädt, geparkt
- Akku, nutzbarer SoC und Reichweite
- Geschwindigkeit (aufgezeichnete Stream-Werte), Leistung, Gang und Kilometerstand
- Innen-/Außentemperatur und Klima
- Verriegelung, Sentry, Ladeport, Türen/Hauben
- TPMS/Reifendruck je Rad
- Tesla-Softwarestand und Update-Radar
- Tesla Deep Data für alle bereits empfangenen Rohfelder

### 🛣️ Fahrten
- automatische Fahrterkennung
- Strecke, Dauer, höchstes **aufgezeichnetes** Tempo und SoC-Verlauf
- Verbrauchsschätzung
- Start-/Zielposition
- gespeicherte Routen aus Stream-Daten
- Kompakte Fahrtenliste: Fahrzeug, verkürzte Route und Kernwerte auf einer Zeile; vollständige Adressen, Stream-Pakete, SoC und Aktionen erscheinen erst per Klick auf die Fahrt
- Einzelfahrten weiterhin direkt öffnen; aufeinanderfolgende Fahrten weiterhin sicher zusammenführen (Auswahl per Checkbox)
- Detailansicht je Fahrt
- Fahrten mit einer Ladesession im gespeicherten Zeitraum kennzeichnen wir als Ladepause; deren SoC-Gewinn wird nicht irrefuehrend als Fahr-Akkugewinn ausgewiesen. Der Worker stoppt neue Fahrten bei erkanntem Ladebeginn am letzten Fahrmesspunkt. Bereits gespeicherte Rohdaten bleiben unangetastet.

### ⚡ Laden & Kosten
- automatische Ladeerkennung
- Energie, Dauer, SoC Start/Ende und Maximalleistung
- Ladeleistung und Ladekurve
- Standort und geocodierte Adresse
- bestätigter Gesamtpreis und effektiver Preis/kWh
- automatische Tesla-Supercharger-Kosten aus der Tesla Charging History, sofern für den Account verfügbar
- manuell bestätigte Kosten werden nicht überschrieben
- Optionaler VoltCore-Messvergleich pro Ladesession: nach manueller Zuordnung von VoltCore-Zählerenergie und optionaler VoltCore-Sessionnummer erscheinen **Energiedifferenz in kWh und %**, Balkenvergleich und eindeutig gekennzeichnete Datenquellen. TrakFog importiert aktuell **noch keine VoltCore-kWh automatisch**; die SoC-Integration Richtung VoltCore ist davon unabhängig.
- Unbestätigte Kosten werden als **offen** angezeigt, nicht als vermeintlich kostenlose 0,00-€-Ladung

### 🗺️ Karte & Geo
- Fahrzeugpositionen
- Fahrtrouten
- Ladeorte
- Reverse Geocoding mit lokalem Cache
- eigene Kreis- und Polygon-Geofences
- Heatmaps für Fahrdichte und Lade-Hotspots

### 🧳 Reisen
- geplante, laufende und abgeschlossene Reisen
- automatische oder manuelle Zuordnung von Fahrten und Ladungen
- Gesamtstrecke, Fahr-/Ladezeit, Energie, Kosten und Budget
- Reise-Timeline und Gesamtkarte

### 😴 Sleep & Phantom Drain
- Fahrzeug-Zustandswechsel
- Schlafsessions
- Phantom-Drain-Auswertung aus qualifizierten Ruhephasen
- keine zusätzlichen Fahrzeugabfragen nur für die Schlafanalyse

### 🧭 Oberfläche & Navigation
- feste Hauptbereiche: Übersicht, Fahrzeug, Historie, Auswertung, Orte und System
- technische Funktionen sind im zentralen System-Center gebündelt
- Dashboard enthält nur fahrzeugbezogene Übersicht statt Engine-/Diagnose-Duplikaten

<a id="integration-api"></a>
### 🔗 Integration API v1

TrakFog kann Fahrzeugdaten über eine eigene, tokenbasierte API an optionale Systeme wie **VoltCore** bereitstellen, ohne Tesla-Zugangsdaten weiterzugeben.

- API-Zugänge unter **System → Integration API**
- Tokens werden nur einmal vollständig angezeigt und anschließend nur gehasht gespeichert
- Scope **`vehicle:read`**
- Standard-Limit **120 Requests/Minute**
- `GET /api/v1/vehicles`
- `GET /api/v1/vehicles/{id}/status`
- SoC, Ziel-SoC, Ladezustand, Ladeleistung, Reichweite, Kilometerstand und Datenfrische
- Zustände **fresh / stale / offline / unknown**, damit externe Systeme alte Daten nicht als Live-Wert behandeln
- Request-Audit ohne API-Payloads, Passwörter oder Tesla-Tokens

Die technische Schnittstellenbeschreibung liegt in [`docs/INTEGRATION-API.md`](docs/INTEGRATION-API.md).

<a id="data-migration"></a>
### ↔ Datenmigration & Portabilität

Unter **System → Datenmigration** besitzt TrakFog ein eigenes Import-/Export-Center.

**Aktiv:**
- **TeslaMate → TrakFog** direkt aus dem offiziellen PostgreSQL-`pg_dump`/`.bck`
- offizielles Plain-SQL-Backup wird versionsunabhängig gestreamt und nicht erst in eine temporäre PostgreSQL-Datenbank zurückgespielt
- ältere PostgreSQL-Custom-Dumps werden zusätzlich unterstützt, sofern die enthaltene Dump-Version mit `pg_restore` lesbar ist
- **TrakFog → TrakFog** über ein dokumentierbares, portables ZIP-Format
- kompletter TrakFog-Datenexport ohne Passwörter, Tesla-Tokens oder andere Zugangsdaten
- VIN-basierte Fahrzeugzuordnung und Dubletten-Schutz für bereits vorhandene Historie
- Hintergrundverarbeitung mit Fortschrittsanzeige für große Datenbestände

Der TeslaMate-Importer berücksichtigt Fahrzeuge, Fahrten, Positionspunkte, Ladehistorie, Geofences sowie Status-/Schlafdaten, soweit sie im Backup vorhanden sind.

TeslaMate-Backup gemäß aktuellem Standardweg:

```bash
docker compose exec -T database pg_dump -U teslamate teslamate > ./teslamate.bck
```

**Bereits im gemeinsamen Migrationsmodell berücksichtigt, aber noch nicht für echte Importe freigeschaltet:**
TeslaLogger, TeslaFi, Tessie, Teslascope, TRONITY, TezLab sowie ein allgemeiner CSV/JSON-Mapper für Eigenbau-Logger und TeslaPy-basierte Projekte.

> TrakFog soll keine Daten-Einbahnstraße sein: Wer zu TrakFog kommt, soll Historie mitbringen können – und wer eine Installation umzieht, soll seine Daten vollständig wieder mitnehmen können.

<a id="liveview-access"></a>
### 📺 LiveView

LiveView ist bewusst **vom normalen Backend-Login getrennt**. Für einen Tesla-Browser, ein Tablet, einen Fernseher oder ein Wanddisplay muss kein regulärer TrakFog-Benutzer angemeldet werden.

**Direktzugang:**

```text
https://deine-trakfog-domain/live.php
```

Je nach gewähltem LiveView-Modus passiert danach Folgendes:

- **Deaktiviert** — LiveView ist nicht erreichbar, bis es im Backend aktiviert wird.
- **PIN** — es genügt die konfigurierte **sechsstellige LiveView-PIN**; normale TrakFog-Zugangsdaten werden nicht benötigt.
- **Offen** — LiveView öffnet sich direkt ohne PIN und ohne Backend-Login.

Im PIN-Modus kann der Browser optional als **vertrauenswürdiges Gerät** gespeichert werden. Danach öffnet dieses Gerät LiveView innerhalb der konfigurierten Vertrauensdauer ohne erneute PIN-Eingabe. Vertrauenswürdige Geräte können im Backend einzeln oder gesammelt wieder abgemeldet werden.

> **Typischer Tesla-Browser-Ablauf:** `/live.php` öffnen → PIN eingeben → „Gerät merken“ aktivieren → fertig.

LiveView zeigt ausschließlich bereits von TrakFog gespeicherte Daten. **Das Offenlassen von LiveView weckt den Tesla nicht zusätzlich auf.**

**V0.1.1.82 – Reifenkontakt korrigiert:** Der freigestellte Tesla hat transparenten Bildrand unter den Reifen; bisher lagen die Schatten fälschlich im Koordinatensystem der ganzen Bühne. Vorder- und Hinterreifenschatten sind jetzt exakt an dasselbe skalierte 1448×1086-Fahrzeugbild gebunden wie die sichtbaren Reifen und folgen jeder Bildschirmgröße. Der dunkle abgesetzte Schattenbalken ist entfernt; Dashboard und LiveView verwenden denselben geometrischen Ansatz. Keine Änderungen an Fahrzeugbildern, Layout, Tesla-Abfragen oder Telemetrie.

**V0.1.1.81 – Showroom-Korrektur:** Das Fahrzeug wird in der LiveView nun je nach Desktop- oder Tesla-Format in der richtigen Größe dargestellt. Bei den schräg freigestellten Fahrzeugen liegen Vorder- und Hinterreifen im Bild unterschiedlich hoch: TrakFog zeichnet deshalb **separate Reifenkontaktschatten** statt eines falsch platzierten Balkenschattens. Das Bodenraster, die Lesbarkeit der Session-Werte sowie Überschrift, Abstände und Datenfluss im Dashboard wurden anhand echter Browser-Vorschauen nachjustiert (1880×820, 1260×780, 773×601). Alle Tesla-Daten, Modellgrafiken und Fahrzeugzustände bleiben erhalten.

**V0.1.1.80 – Cockpit-Showroom:** Die beiden final abgestimmten Designentwürfe für Fahrzeug-LiveView und Dashboard sind im echten HTML/CSS umgesetzt: Fahrzeug und Bodenschatten bilden eine zusammengehörige Bühne, die alte separate Schattenellipse entfällt. In der LiveView sind Schlafanzeige, Batterie-/Leistungskarten und vier Session-Kennzahlen typografisch sowie mit einheitlichen SVG-Icons ausgerichtet. Beim Wechsel zwischen Fahrt und Ladevorgang ändern sich die Kennzahlen-Bezeichnungen passend zu den vorhandenen Daten. Tesla-Standard/Wide und Desktop haben eigene kompakte Regeln. [V0.1.1.74 bleibt als Rücksprungpunkt erhalten](docs/checkpoints/LIVEVIEW_PRE_VEHICLE_VISUALIZATION_V0.1.1.74.md).

**Ergänzung V0.1.1.77:** Model 3 und Model Y können jetzt zwischen klassischer Generation und Highland/Juniper unterscheiden. TrakFog versucht eine lokale Vorauswahl anhand von gespeicherter Modellkennung und VIN-Modelljahr. Unklare Übergangsjahre werden bewusst **nicht** geraten. Unter **Fahrzeugdetailseite → Fahrzeugdarstellung** lässt sich die passende Variante pro Fahrzeug dauerhaft auswählen. Dashboard und LiveView verwenden dieselbe Auswahl. Die neue Bühne ist rund 20 % kleiner, erhält Kontakt-/Reifenschatten und eine flachere Bodenbeleuchtung. [Details und VIN-Grenzen](docs/VEHICLE-VARIANTS.md). 

**Neu ab V0.1.1.75, Modell-Erkennung verbessert in V0.1.1.76:** Die Fahrzeugseite zeigt eine lokale Tesla-Visualisierung für **Model 3, Model Y, Model S und Model X**. Das tatsächlich gespeicherte Fahrzeugmodell bestimmt die Illustration; bei unbekanntem Modell erscheint nur der neutrale Tesla-Schriftzug, kein geratenes Model Y. Die Bühne reagiert auf Schlafen, Parken, Laden, Fahren und veraltete Daten. Unter **••• → Display & Zugang → Fahrzeug-Effekte** können die dezenten Effekte auf jedem Browser deaktiviert werden. Die Bilder werden lokal ausgeliefert, es gibt **keinen neuen Tesla-API-Abruf**. Die bestehenden Live-Daten, Karte, Fahrten und NerdView werden weiter verwendet.

**Rollback vor Fahrzeugvisualisierung:** [V0.1.1.74, geprüfter Checkpoint](docs/checkpoints/LIVEVIEW_PRE_VEHICLE_VISUALIZATION_V0.1.1.74.md).

**Ansichten:**
- **Fahren:** Tacho, Gang, Akku, Leistung und aktuelle Session als große Blickwerte
- **Karte:** dauerhaft zentrierter Tesla, manueller Zoom, roter Fahrfaden und fingerfreundliche Kartensteuerung
- **Reise:** Gesamtzeit, Gesamtstrecke, geladene Energie und Ladekosten
- **NerdView / Stand:** technische Fahrzeug-, Lade-, Klima- und Positionsdaten
- zukünftiger Standort-Schalter als großer grüner/roter Touch-Button vorbereitet
- Hell-/Dunkel-/Nachtkarte
- Swipe-Navigation für Tesla-Browser, Tablet, Smartphone, Desktop und TV

---

## 🧠 Grundprinzip

TrakFog soll **Daten sammeln, ohne den Tesla unnötig wach zu halten**.

- der permanente Datendienst pollt abhängig vom Fahrzeugzustand
- der Driving Stream läuft parallel für Live-Signale
- gespeicherte Werte versorgen Dashboard, Charts und LiveView
- Reverse Geocoding läuft getrennt vom Tesla-Polling
- Zusatzdaten werden nur schonend und gecacht abgefragt
- ein eingeschränkter Tesla-API-Bereich darf nicht die gesamte Verbindung abschalten

### Live-Map-Verhalten

Der eigene Tesla bleibt während der Fahrt im Kartenmittelpunkt.  
TrakFog verändert den gewählten Zoom **nicht automatisch**. Der rote Fahrfaden wächst hinter dem Fahrzeug weiter. Wer die gesamte Route sehen möchte, zoomt selbst heraus.

---

## 🐳 Architektur

```text
Internet
   │
   ▼
Nginx Proxy Manager
   │
   ▼
trakfog-web
PHP 8.4 / Apache
   │
   ├──────────────► trakfog-db
   │                MariaDB
   │
   ├──────────────► trakfog-worker
   │                Python / permanent
   │                     │
   │                     ├─ Tesla API
   │                     └─ Tesla Driving Stream
   │
   └──────────────► trakfog-updater
                    interner Docker-Updater
                    (kein öffentlicher Port)
```

### Container

| Container | Aufgabe |
|---|---|
| `trakfog-web` | Weboberfläche, Login, LiveView, Einstellungen und APIs |
| `trakfog-worker` | permanenter Datendienst, Polling, Stream, Fahrten/Laden, Geo-Queue |
| `trakfog-db` | persistente MariaDB |
| `trakfog-updater` | interner, authentifizierter Stable-Updater; einziger TrakFog-Dienst mit Docker-Socket-Zugriff |

### Repository

```text
public/       Webroot
src/          PHP-Services und Applogik
worker/       permanenter Python-Datendienst
database/     Fresh-Install-Schema und Migrationen
docker/       Docker-/Apache-Konfiguration
bin/          Bootstrap und Wartung
docs/         statische Projekt-/Pages-Seite
storage/      Laufzeit-Logs
```

Nur `public/` wird vom Webserver ausgeliefert.

---

<a id="installation"></a>
## 🚀 Installation

> **Neuinstallation:** Wenn kein Owner per Environment vorgegeben wird, startet TrakFog vollständig und führt beim ersten Browseraufruf durch **Systemcheck → Owner → optionale Tesla-Verbindung → optionale Datenübernahme**. Für automatisierte Installationen bleibt die Owner-Erstellung über Environment-Variablen verfügbar.

### Docker Compose — Standardweg

Die vollständige Anleitung liegt unter:

**[docker/DOCKER.md](docker/DOCKER.md)**

Kurzfassung:

```bash
git clone https://github.com/hotteftw1981/TrakFog-Public.git
cd TrakFog
git switch stable
cp .env.example .env
# .env mit sicheren Datenbank-Passwörtern und Domain ausfüllen
docker compose up -d --build
# Installationsschlüssel lokal abrufen, dann im Browser-Wizard eingeben:
docker exec trakfog-web cat /var/lib/trakfog/setup_token
```

Danach ist TrakFog standardmäßig auf dem in `.env` gesetzten Port erreichbar, z. B. `http://SERVER-IP:8780`. Der erste Owner benötigt den **nur lokal lesbaren Installationsschlüssel** aus dem Webcontainer, damit kein fremder Besucher den Account übernimmt. Für den externen Betrieb gehört ein Reverse Proxy mit HTTPS davor.

### Portainer — optionale Oberfläche

Wer Portainer nutzt, kann denselben Compose-Stack als Repository-Stack deployen:

**[docker/PORTAINER.md](docker/PORTAINER.md)**

Portainer ist **keine Voraussetzung** für TrakFog.

### Updates

TrakFog besitzt ein integriertes Stable-Updatesystem unter **System → Updates**.

- Update-Prüfung gegen veröffentlichte GitHub Releases
- Zielrelease wird direkt vor der Installation erneut verifiziert
- `trakfog-updater` lädt exakt den veröffentlichten Release-Tag
- der Updater prüft selbst nochmals, dass dieser Tag weiterhin das aktuelle GitHub Release ist
- Web und Datendienst werden per Docker Compose neu gebaut und ersetzt
- Datenbank- und Runtime-Volumes bleiben erhalten
- Datenbank-Migrationen laufen beim Containerstart automatisch
- nach dem Neustart bestätigt TrakFog die tatsächlich installierte Zielversion
- **keine Portainer-Pflicht und kein Portainer-Webhook**
- nur `trakfog-updater` besitzt Docker-Socket-Zugriff; Web und Datendienst nicht
- der Updater besitzt keinen veröffentlichten Host-Port und akzeptiert nur intern authentifizierte Aufträge

Manueller Fallback:

```bash
git fetch origin stable
git switch stable
git pull --ff-only origin stable
docker compose up -d --build
```

> **Bestehende V0.1.1.46-Installation:** Für die Einführung des neuen Updater-Containers ist genau einmal ein normaler Stack-Redeploy nötig. Danach können weitere Stable-Updates direkt in TrakFog ausgelöst werden.

---

## 🔑 Tesla-Verbindung

TrakFog speichert **niemals das Tesla-Passwort**.

Access- und Refresh-Token werden mit dem lokalen `TRAKFOG_APP_KEY` verschlüsselt gespeichert.

Aktuell verwendet die private Entwicklung noch den bestehenden Owner-API-/Token-Weg. Der Connector ist bewusst isoliert, weil Tesla diesen Zugang jederzeit verändern kann.

### Transport-Kompatibilität

Tesla verlangt für den bisherigen Owner-API-/Auth-Weg inzwischen einen modernen Transport. TrakFog verwendet deshalb für Tesla Auth und Owner API **HTTP/2 mit TLS 1.3**. Ein HTTP/1.1-Client kann von Tesla irreführend mit HTTP 403 abgewiesen werden, obwohl der Token grundsätzlich gültig ist.

### Verbindungsstatus

TrakFog unterscheidet zwischen:

- **verbunden** — Token/Fahrzeugzugang funktioniert
- **eingeschränkt** — einzelner Tesla-Datenbereich liefert z. B. HTTP 403
- **nicht erreichbar / Rate Limit** — temporärer Zustand
- **Auth-Fehler** — Token wirklich ungültig oder abgelaufen

Ein einzelner Detail- oder Zusatzdatenfehler schaltet die komplette Tesla-Verbindung nicht mehr ab.

### Fahrzeugerkennung & Datenfrische

TrakFog behandelt Teslas `/products`-Endpoint nur noch als **Fahrzeugerkennung**, nicht als einzige Lebensader:

- fällt `/products` mit HTTP 403 aus, werden bereits bekannte Fahrzeuge weiterverwendet
- der Driving Stream läuft unabhängig von der Fahrzeugerkennung weiter
- nach einem 403 versucht TrakFog kontrolliert einen neuen Access Token über HTTP/2 + TLS 1.3 und testet den Endpoint erneut
- ein alter gespeicherter Zustand `online` gilt nicht unbegrenzt als aktuell
- System und LiveView unterscheiden Prozess-Heartbeat, Tesla-Zugang, Streamzustand und echte Datenfrische

Damit kann ein schlafender Tesla nicht nur deshalb dauerhaft als „online“ erscheinen, weil der letzte erfolgreiche Poll Stunden zurückliegt.

### Tesla-API-Diagnose

TrakFog speichert bei Tesla-API-Problemen ausschließlich technische Diagnosefelder – **keine Tokens oder Authorization-Header**. Im System-Center können unter anderem Endpoint, HTTP-Status/-Version, Teslas eigener Fehlertext, optionale `txid`, Token-Ablaufzeit sowie Refresh-, Retry- und Fallback-Status eingesehen werden.

---

## ⚡ Supercharger-Kosten

Die optionale Charging-History-Synchronisierung:

- verwendet die VIN des gespeicherten Fahrzeugs
- benötigt aktuell keine Fleet-ID
- fragt die Ladehistorie getrennt von den Live-Fahrzeugdaten ab
- weckt das Fahrzeug nicht eigens
- ordnet Tesla-Sessions anhand von Zeit und Energie lokalen Ladevorgängen zu
- übernimmt den tatsächlich abgerechneten Betrag
- schützt manuell bestätigte Kosten

Ob Tesla die Charging History für einen Account freigibt, hängt vom jeweiligen Tesla-Zugang ab.

---

## 🛰️ Tesla-Zusatzdaten

TrakFog kann optional gecacht abfragen:

- nahe Ladeorte
- letzte Fahrzeug-Alerts
- Release Notes
- Service-Daten

Diese Bereiche sind **nicht kritisch für die Hauptfunktion**. Fehlende Berechtigungen oder nicht unterstützte Endpoints werden separat behandelt.

---

## 🌍 Geo & Datenschutz

Reverse Geocoding kann Koordinaten an den konfigurierten Geocoder senden.

Standardmäßig ist Nominatim vorgesehen. Die Funktion kann:

- deaktiviert werden
- auf eine eigene Nominatim-Instanz zeigen
- durch lokale Geofences wie Zuhause, Arbeit oder Hotel übersteuert werden

Aufgelöste Adressen werden lokal gecacht.

---

## 🩺 Health & Diagnose

### Web-Health

`/health.php`

### In TrakFog

**System → Diagnose**

Dort werden unter anderem geprüft:

- PHP und Extensions
- MariaDB / Schema
- HTTPS
- Sessions
- Tesla Connector
- Datendienst / Heartbeat
- Tesla-Polling und Stream
- Charging-History-Sync

---

<a id="roadmap"></a>
## 🧭 Roadmap

### ✅ Bereits da
- Docker-/Portainer-Stack mit browserbasierter Ersteinrichtung
- permanenter Datendienst
- Tesla Driving Stream
- automatische Fahrten und Ladungen
- Statistiken und Ladekurven
- Sleep-/Phantom-Drain
- Karte, Geo, Geofences und Heatmaps
- Reisen
- LiveView inkl. Live Map und NerdView
- adaptives Multi-Fahrzeug-Dashboard mit statusabhängiger Hero-Bühne
- Tesla Update-Radar und Deep Data
- Supercharger-Kostensync
- gecachte Tesla-Zusatzdaten
- Datenmigration: TeslaMate sowie portabler TrakFog Export/Re-Import
- Integration API v1 für sichere externe Fahrzeugdaten-Verbraucher

### 🔜 Als Nächstes
- LiveView und NerdView weiter ausbauen
- Karten-/POI-System für eigene Orte und spätere Freigaben erweitern
- freigegebene andere Teslas / Community-Layer vorbereiten
- Benachrichtigungen aus bereits lokal vorhandenen Daten entwickeln
- Fahrten-, Lade- und Reiseauswertungen weiter vertiefen
- den aktuellen kostenfreien Tesla-Zugang möglichst robust gegen API-Änderungen absichern
- gecachte Tesla-Zusatzdaten sichtbar in Karte, NerdView und Hinweise integrieren
- TeslaLogger-, TeslaFi-, Tessie-, Teslascope-, TRONITY- und TezLab-Adapter nach echten Testexporten aktivieren
- universellen CSV/JSON-Feld-Mapper für TeslaPy und Eigenbau-Logger ergänzen
- External Live Data API mit stabilen Rohfeldern für OBS Studio, Home Assistant und weitere externe Verbraucher

### 🚫 Bewusst nicht Teil der Roadmap
- eine kostenpflichtige Tesla Fleet API als Voraussetzung
- Fleet Telemetry mit laufenden Pay-per-Use-Kosten
- Funktionen, die zwingend ein Tesla-Billingkonto oder eine hinterlegte Zahlungsart benötigen
- Remote Commands, solange sie nur über einen kostenpflichtigen API-Weg sinnvoll nutzbar sind

> **Kosten-Grundsatz:** TrakFog soll im normalen Eigenbetrieb ohne laufende Tesla-API-Gebühren nutzbar bleiben.

---

<a id="releases"></a>
## 📦 Releases & QA

Stable Builds bleiben vorerst in der **V0.1.1.x**-Reihe.

Bei einem Release erzeugt GitHub automatisch:

- Syntax-/statische Checks
- Git Tag
- GitHub Release
- bereinigtes ZIP

Zusätzlich startet **Docker QA** den echten Stack mit Web, MariaDB und Datendienst und führt Runtime-Smoke-Tests aus.

Die vollständige Versionshistorie gehört bewusst **nicht mehr in diese README**:

➡️ **[CHANGELOG.md](CHANGELOG.md)**

---

<a id="license"></a>
## ⚖️ Lizenz

TrakFog wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.

Die vollständigen Lizenzbedingungen stehen in **[LICENSE](LICENSE)**.

---

## 👨‍💻 Projekt

**Entwicklung & Konzeption:** Patrick Garbe  
TrakFog ist ein unabhängig entwickeltes Open-Source-Projekt und befindet sich noch deutlich vor einer fertigen 1.0.

---

**Dein Tesla vergisst nichts. TrakFog auch nicht.** 😈

## Modellbilder & verbundene Fahrten

**Living Garage:** Das Dashboard zeigt für erkannte Tesla Model 3, Model S, Model X und Model Y jeweils ein lokal gespeichertes Modellbild. Ein Klick auf das Mini-Fahrzeugbild öffnet die Fahrzeugdetails; ein Klick auf den restlichen Kartenbereich wählt das Fahrzeug für die große Bühne aus. Unbekannte Modelle verwenden das bisherige neutrale Model-Y-Bild. Alle Assets liegen unter `public/assets/vehicles/` und werden zusammen mit TrakFog ausgeliefert.

**LiveMap:** Zeigt die zuletzt gespeicherten Positionen aller Teslas aus derselben TrakFog-Instanz. Der ausgewählte Tesla bleibt zentriert; andere Fahrzeuge werden dezent dargestellt und sind über ihren Marker auswählbar. Es erfolgt **keine zusätzliche Tesla-API-Abfrage**. Achtung: Bei bewusst freigegebener PIN-LiveView sind auch die gespeicherten Standorte anderer Instanzfahrzeuge sichtbar.

**Fahrten zusammenführen:** Unter **Fahrten** mindestens zwei direkt aufeinanderfolgende, abgeschlossene Fahrten desselben Teslas markieren. Dann auf **Fahrten zusammenführen** klicken, Start und Ziel kurz prüfen und **Jetzt zusammenführen** bestätigen. Aus zwei Fahrten wird **ein einziger Eintrag**, keine zusätzliche Tour. Distanz und Energie werden summiert, die reine Fahrzeit schließt die Pause aus. Vor dem Speichern prüft TrakFog Fahrzeug, Reihenfolge, maximale Stopplänge (2 Stunden) und die Positionsdifferenz (maximal 2 km, falls GPS vorhanden). Die Originalwerte werden nur intern als technischer Sicherungseintrag behalten; GPS-Telemetrie wird nicht gelöscht. Bestehende virtuelle Gruppierungen aus älteren Versionen werden nicht automatisch umgeschrieben, lassen sich aber durch erneutes Auswählen ihrer Teilfahrten dauerhaft zusammenführen.

**LiveView:** Nach kurzem Halt kann die rote Spur über **Letzte Fahrt fortsetzen?** weiter angezeigt werden. Dieser Schritt ist unabhängig vom Anmeldemodus nur eine lokale Kartenvorschau; während eine Fahrt noch läuft, wird kein Datensatz verändert. Nach Fahrtende kannst du beide Fahrten im Backend zu einer machen. Die LiveMap bleibt wie gewohnt auf dem gewählten Tesla zentriert.

### Fahrten-Qualitaet und manuelles Loeschen (V0.1.1.61)

Das Einlegen von D oder R startet zunaechst eine **vorlaeufige** Live-Fahrt. Beim Wechsel auf P oder beim kontrollierten Abschluss einer Stream-Fahrt bleibt sie nur dann in der Historie, wenn die Telemetrie mindestens 100 Meter bestaetigt. Eindeutige Stand- oder Rangier-Phantomfahrten unter diesem Grenzwert werden verworfen. Bei unvollstaendigen Kilometer-/GPS-Daten wird vorsichtshalber nichts automatisch geloescht. Bestehende Null-Meter-Fahrten bleiben bis zur manuellen Bereinigung erhalten.

Der Grenzwert laesst sich fuer Docker/Portainer unter `TRAKFOG_MIN_TRIP_DISTANCE_METERS=100` konfigurieren (0 = Filter aus, max. 1000). Die Regel betrifft nur neue `tesla_stream`-Fahrten und erzeugt **keine zusaetzlichen Tesla-API-Abfragen**.

Unter **Fahrten** und in den Fahrtdetails koennen Owner/Admins abgeschlossene Fahrten mit dem Button **Loeschen** entfernen. Die Bestaetigung erfolgt im Modal mit aktiv zu bestaetigender Checkbox. Die Fahrt verschwindet sofort aus der Historie und Statistik, Tour- und Reisezuordnungen werden automatisch bereinigt. Es werden **nicht** alle Rohtelemetriedaten geloescht; die Aktion ist ohne Backup nicht umkehrbar.


### 🖼️ Hinweis zur öffentlichen Fahrzeugoptik

Die Public Beta liefert sechs eigens erstellte **schematische, generische Elektrofahrzeug-SVGs** anstelle der nicht zur Weiterverbreitung freigegebenen Tesla-Renderings. Die VIN-/Modellgenerationsauswahl, Datenanzeige, LiveView und Fahrzeugstatus bleiben funktionsgleich. Die private Entwicklungsinstallation ist davon unabhängig.
