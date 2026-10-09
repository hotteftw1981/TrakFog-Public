# 🐳 TrakFog mit Portainer installieren

Diese Anleitung gilt für die aktuellen **TrakFog Stable Releases**.

TrakFog besteht aus vier Containern:

- `trakfog-web` — PHP 8.4 + Apache
- `trakfog-worker` — dauerhafter Python-Worker
- `trakfog-db` — MariaDB 11.8
- `trakfog-updater` — interner Stable-Updater

---

## 0. Empfehlung vor dem Umzug

Die bestehende IONOS-Version **noch nicht löschen**.

Am einfachsten ist aktuell:

1. Docker-Version parallel frisch aufsetzen.
2. TrakFog testen.
3. Tesla per Tesla Auth erneut verbinden.
4. Erst wenn alles funktioniert, die alte Installation abschalten.

So vermeidest du unnötige DB-/Token-Migrationen in dieser frühen Projektphase.

Wenn du die bestehende Datenbank übernehmen willst, siehe Abschnitt **Bestehende Installation übernehmen** weiter unten.

---

## 1. Portainer öffnen

In Portainer:

**Stacks → Add stack**

Name:

`trakfog`

---

## 2. Git-Quelle anlegen

Build method:

**Repository**

In aktuellen Portainer-Versionen ist zuerst eine **Source** nötig.

1. Bei **Source** auf **Create new source** klicken.
2. Als Git-Quelle das öffentliche Repository `https://github.com/hotteftw1981/TrakFog-Public.git` hinterlegen.
3. Keine Zugangsdaten nötig.
4. Quelle speichern und anschließend im Feld **Source** auswählen.

Danach:

Repository reference:

`refs/heads/stable`

Compose path:

`docker-compose.yml`

**Additional paths** leer lassen.

---

## 3. Environment-Variablen eintragen

Unter **Environment variables** folgende Werte anlegen.

### Basis

```env
TRAKFOG_HTTP_PORT=8780
TRAKFOG_TIMEZONE=Europe/Berlin
TRAKFOG_BASE_URL=https://DEINE-TRAKFOG-DOMAIN
```

### Datenbank

```env
TRAKFOG_DB_NAME=trakfog
TRAKFOG_DB_USER=trakfog
TRAKFOG_DB_PASSWORD=EIN_LANGES_ZUFAELLIGES_PASSWORT
TRAKFOG_DB_ROOT_PASSWORD=NOCH_EIN_ANDERES_LANGES_ZUFAELLIGES_PASSWORT
```

Die beiden Passwörter müssen unterschiedlich und stark sein.

### Erster TrakFog-Owner

Für die normale Installation:

```env
TRAKFOG_OWNER_USER=admin
TRAKFOG_OWNER_EMAIL=
TRAKFOG_OWNER_PASSWORD=
```

E-Mail und Passwort bleiben leer. Der Stack startet trotzdem und öffnet beim ersten Browseraufruf den **Ersteinrichtungsassistenten**. **Sicherheitsstufe vor dem Owner-Anlegen:** In Portainer **Containers → trakfog-web → Console** öffnen und den lokal erzeugten Schlüssel auslesen:

```sh
cat /var/lib/trakfog/setup_token
```

Diesen 64-stelligen Schlüssel unter **Installationsschlüssel** im Wizard eingeben. Er bleibt persistent im Runtime-Volume und muss geheim bleiben. Alternativ im Terminal des Docker-Hosts: `docker exec trakfog-web cat /var/lib/trakfog/setup_token`. Anschließend werden Systemcheck, erster Owner, optionale Tesla-Verbindung und Datenübernahme geführt erledigt.

Für automatisierte/headless Installationen können alle drei Owner-Werte vollständig gesetzt werden. Dann wird der Owner beim Bootstrap erstellt und der Browser-Wizard übersprungen.

### App-Key

Für eine **frische Installation**:

```env
TRAKFOG_APP_KEY=
```

leer lassen. TrakFog erzeugt automatisch einen persistenten App-Key.

Für die Übernahme einer bestehenden Installation muss hier der alte `app.app_key` aus `config/local.php` hinein.

### Worker

```env
TRAKFOG_WORKER_POLL_ONLINE_SECONDS=30
TRAKFOG_WORKER_POLL_SLEEP_SECONDS=60
TRAKFOG_WORKER_HEARTBEAT_SECONDS=15
```

### Map / Reverse Geocoding

```env
TRAKFOG_GEOCODER_ENABLED=1
TRAKFOG_GEOCODER_URL=https://nominatim.openstreetmap.org
TRAKFOG_GEOCODER_USER_AGENT=TrakFog self-hosted Tesla data platform
```

Damit werden nur bereits in TrakFog gespeicherte Koordinaten im Hintergrund in Adressen aufgelöst. Die Geo-Auflösung löst **keine Tesla-Abfrage und keinen Wake-up** aus.

Wer keine Koordinaten an einen externen Reverse-Geocoder senden möchte, setzt:

```env
TRAKFOG_GEOCODER_ENABLED=0
```

Alternativ kann über `TRAKFOG_GEOCODER_URL` eine eigene kompatible Nominatim-Instanz verwendet werden.

---

## 4. Stack deployen

Unten:

**Deploy the stack**

Beim ersten Start passiert automatisch:

1. MariaDB startet.
2. Web wartet auf eine gesunde DB.
3. Datenbankschema wird angelegt.
4. fehlende Migrationen werden ausgeführt.
5. Ohne vorgegebenen Owner wird die Installation für den Browser-Wizard freigegeben; mit vollständigen Owner-Variablen wird der Account automatisch angelegt.
6. Worker startet.
7. Worker schreibt seinen Heartbeat in die DB.

Der Start kann beim ersten Build ein paar Minuten dauern.

### Warum `stable` statt `main`?

`main` ist der Entwicklungszweig. Der Branch `stable` wird ausschließlich nach einem erfolgreichen GitHub Release auf den freigegebenen Commit gesetzt. Das integrierte Updatesystem kann dadurch keine unfertigen Entwicklungscommits installieren.

### Updates

Portainer ist **keine Voraussetzung** für TrakFog-Updates.

Nach dem ersten Deploy läuft der interne Container `trakfog-updater`. Unter **System → Updates** kann TrakFog damit Stable Releases selbst installieren – unabhängig davon, ob der Stack ursprünglich per Portainer oder per normalem Docker Compose gestartet wurde.

Dafür ist **kein Stack-Webhook** erforderlich. Repository-Polling und Portainer-GitOps sind ebenfalls nicht nötig.

Der Updater besitzt als einziger TrakFog-Dienst Zugriff auf den Docker-Socket. Web und Datendienst haben keinen Docker-Socket-Zugriff. Der Updater ist außerdem nicht über einen Host-Port erreichbar und akzeptiert nur intern authentifizierte Update-Aufträge.

Bei einer bestehenden Installation aus V0.1.1.46 oder älter muss der Stack für die Einführung von `trakfog-updater` einmal normal neu deployt werden. Danach laufen weitere Stable-Updates direkt in TrakFog.

Der manuelle Fallback bleibt:

```bash
git fetch origin stable
git switch stable
git pull --ff-only origin stable
docker compose up -d --build
```

## 5. Container prüfen

Unter:

**Containers**

sollten erscheinen:

- `trakfog-db`
- `trakfog-web`
- `trakfog-worker`
- `trakfog-updater`

Alle vier sollten nach kurzer Zeit **running / healthy** sein.

Wenn nicht:

Container öffnen → **Logs**

und zuerst den betroffenen Container prüfen.

---

## 6. Erster Test ohne Domain

Im Browser:

`http://SERVER-IP:8780/health.php`

Erwartet wird eine JSON-Antwort mit:

```json
{"ok":true}
```

Danach:

`http://SERVER-IP:8780`

Bei einer frischen Installation ohne vorgegebenen Owner öffnet TrakFog den Ersteinrichtungsassistenten. Bei bereits eingerichteten Installationen erscheint der Login.

---

## 7. Nginx Proxy Manager

In NPM:

**Hosts → Proxy Hosts → Add Proxy Host**

### Details

Domain Names:

deine gewünschte TrakFog-Domain

Scheme:

`http`

Forward Hostname / IP:

IP-Adresse deines Docker-/Portainer-Servers

Forward Port:

`8780`

Aktivieren:

- Cache Assets: optional
- Block Common Exploits: ja
- **Websockets Support: ja**

WebSocket Support brauchen wir für den kommenden Tesla-Streaming-/LiveView-Ausbau.

### SSL

Unter **SSL**:

- neues Let's-Encrypt-Zertifikat anfordern
- Force SSL aktivieren
- HTTP/2 aktivieren
- HSTS erst aktivieren, wenn die Domain sicher funktioniert

Speichern.

---

## 8. TRAKFOG_BASE_URL prüfen

Die Environment-Variable muss zur finalen Domain passen:

`https://DEINE-TRAKFOG-DOMAIN`

Wenn du sie geändert hast:

Portainer → Stack → Editor / Environment → Wert ändern → **Update the stack**

---

## 9. TrakFog testen

In TrakFog:

### Einstellungen → Systemdiagnose

Alles sollte möglichst grün sein.

Besonders:

- Datenbank
- HTTPS
- Session Secure
- Tesla Connector
- Docker Worker

### Einstellungen → Docker Worker

Erwartet:

- Runtime: Docker / Portainer
- Worker lebt
- Heartbeat aktuell
- Worker-State z. B. `waiting_for_tesla`, `sleeping` oder `online`

---

## 10. Tesla verbinden

Unter:

**Connect**

mit Tesla Auth Access-/Refresh-Token erzeugen und eintragen.

Tesla Auth:

https://github.com/adriankumpf/tesla_auth/releases/latest

Danach sollte dein Fahrzeug erkannt werden.

---

# Bestehende Installation übernehmen

Nur nötig, wenn die alte IONOS-Datenbank wirklich erhalten werden soll.

## App-Key sichern

Aus der alten:

`config/local.php`

den exakten Wert:

`app.app_key`

sichern.

Er beginnt normalerweise mit:

`base64:`

Diesen Wert in Portainer als:

`TRAKFOG_APP_KEY`

eintragen.

**Nicht hier im Chat posten und nicht in Git speichern.**

## Datenbank

Vorher vollständigen SQL-Dump der alten TrakFog-Datenbank erstellen.

Für eine echte DB-Übernahme empfiehlt es sich, den neuen Stack zunächst nicht produktiv zu verwenden und den Dump kontrolliert in `trakfog-db` zu importieren.

Da TrakFog aktuell noch am Anfang steht, ist eine frische Docker-Installation plus erneute Tesla-Verbindung momentan deutlich einfacher und risikoärmer.

---

# GitOps / automatische Updates

Portainer-GitOps oder Repository-Polling sind **optional** und für TrakFog nicht notwendig.

Für veröffentlichte Installationen gilt:

- `stable` = geprüfter Release-Stand
- `main` = aktive Entwicklung/Test
- GitHub Release = veröffentlichter Build
- Docker QA muss grün sein

---

# Backup

Für den produktiven Betrieb später sichern:

- Docker Volume `trakfog_db`
- Docker Volume `trakfog_runtime`
- optional `trakfog_logs`

Besonders `trakfog_runtime` enthält den persistenten App-Key sowie das interne Updater-Token einer frischen Docker-Installation.

---


## Datenmigration und große Uploads

Unter **System → Datenmigration** können unter anderem TeslaMate-Backups hochgeladen werden. Der TrakFog-Webcontainer akzeptiert dafür Dateien bis **8 GiB**.

Bei Nutzung eines Reverse Proxys muss dessen Upload-Limit ebenfalls groß genug sein. Für Nginx / Nginx Proxy Manager kann bei Bedarf im erweiterten Proxy-Host-Setup zum Beispiel gesetzt werden:

```nginx
client_max_body_size 8G;
```

Alternativ kann ein sehr großes Backup im lokalen Netz direkt über den veröffentlichten TrakFog-Port hochgeladen werden. Das Upload-Limit sollte nur so hoch gewählt werden, wie es für die eigene Installation tatsächlich benötigt wird.


## Zielzustand

```text
Internet
   │
   ▼
Nginx Proxy Manager
   │ HTTPS / WebSocket
   ▼
trakfog-web :80
   │
   ├──── MariaDB ──── trakfog-db
   │
   └──── Worker ───── trakfog-worker
                          │
                          ▼
                       Tesla
```

Danach ist die Plattform bereit für den nächsten Block:

**Tesla WebSocket / Driving Stream.** 🚗📡
