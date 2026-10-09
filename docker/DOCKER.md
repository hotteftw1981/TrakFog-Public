# 🐳 TrakFog mit Docker Compose installieren

Dies ist der **Standard-Installationsweg** für TrakFog. Portainer ist optional.

## Voraussetzungen

- Linux-Server oder VM mit Docker Engine
- Docker Compose Plugin (`docker compose version`)
- Git
- für produktiven Betrieb idealerweise ein Reverse Proxy mit HTTPS

## 1. Repository holen

```bash
git clone https://github.com/hotteftw1981/TrakFog-Public.git
cd TrakFog
git switch stable
```

Solange das Entwicklungsrepository privat ist, benötigt Git entsprechend Zugriff auf GitHub. Für öffentliche Releases entfällt das.

## 2. Konfiguration anlegen

```bash
cp .env.example .env
chmod 600 .env
```

Danach `.env` bearbeiten.

Mindestens:

```env
TRAKFOG_HTTP_PORT=8780
TRAKFOG_TIMEZONE=Europe/Berlin
TRAKFOG_BASE_URL=https://DEINE-TRAKFOG-DOMAIN

TRAKFOG_DB_NAME=trakfog
TRAKFOG_DB_USER=trakfog
TRAKFOG_DB_PASSWORD=EIN_LANGES_ZUFAELLIGES_PASSWORT
TRAKFOG_DB_ROOT_PASSWORD=NOCH_EIN_ANDERES_LANGES_ZUFAELLIGES_PASSWORT

TRAKFOG_OWNER_USER=admin
TRAKFOG_OWNER_EMAIL=
TRAKFOG_OWNER_PASSWORD=

TRAKFOG_APP_KEY=
```

Für eine frische Installation bleibt `TRAKFOG_APP_KEY` leer. TrakFog erzeugt automatisch einen persistenten Schlüssel.

### Erster Owner: Browser-Wizard oder Environment

Standardmäßig bleiben `TRAKFOG_OWNER_EMAIL` und `TRAKFOG_OWNER_PASSWORD` leer. Der Stack startet trotzdem vollständig und zeigt beim ersten Browseraufruf den **TrakFog-Ersteinrichtungsassistenten**. Um die Übernahme durch einen fremden Erstbesucher zu verhindern, ist zum Owner-Anlegen ein zufälliger, nur lokal verfügbarer Schlüssel notwendig:

```bash
docker exec trakfog-web cat /var/lib/trakfog/setup_token
```

Diesen Schlüssel in das Feld **Installationsschlüssel** des Assistenten eingeben. Er wird beim ersten Containerstart erzeugt, bleibt in `trakfog_runtime` erhalten und darf weder geteilt noch in Logs/Issues eingefügt werden. **Nicht** als Tesla Access Token verwenden. Installation idealerweise erst lokal fertigstellen, bevor der Reverse Proxy öffentlich freigeschaltet wird.

Der Wizard führt dann durch:

1. Systemcheck
2. ersten Owner anlegen
3. Tesla optional verbinden
4. vorhandene TeslaMate-/TrakFog-Daten optional übernehmen

Für automatisierte/headless Installationen können Benutzername, E-Mail und Passwort weiterhin vollständig als Environment-Variablen gesetzt werden. In diesem Fall wird der Owner beim Bootstrap angelegt und der Web-Wizard als abgeschlossen markiert.

## 3. Stack starten

```bash
docker compose up -d --build
```

Status prüfen:

```bash
docker compose ps
```

Erwartete Container:

- `trakfog-db`
- `trakfog-web`
- `trakfog-worker`
- `trakfog-updater`

Logs:

```bash
docker compose logs -f --tail=100
```

## 4. Health prüfen

```bash
curl -fsS http://127.0.0.1:8780/health.php
```

Erwartet:

```json
{"ok":true}
```

Danach im Browser:

`http://SERVER-IP:8780`

Bei einer frischen Installation ohne vorgegebenen Owner startet automatisch die **Ersteinrichtung**.

## 5. Reverse Proxy

Die öffentliche HTTPS-Domain auf den TrakFog-Port weiterleiten, zum Beispiel:

`https://trakfog.example.org` → `http://DOCKER-SERVER:8780`

`TRAKFOG_BASE_URL` muss exakt zur externen HTTPS-Adresse passen.

## 6. Tesla verbinden

Nach dem ersten Login unter **System → Tesla** die Tesla-Verbindung einrichten.

## 7. Updates

Unter **System → Updates** kann ein veröffentlichtes Stable Release direkt installiert werden.

Der interne `trakfog-updater`:

- besitzt keinen veröffentlichten Host-Port,
- akzeptiert nur intern authentifizierte Aufträge,
- akzeptiert ausschließlich das fest hinterlegte Repository `hotteftw1981/TrakFog-Public`,
- prüft selbst nochmals das aktuelle GitHub Release,
- lädt exakt dessen Release-Tag,
- verifiziert die `VERSION` im geladenen Archiv,
- baut nur `trakfog-web` und `trakfog-worker` neu,
- lässt Datenbank und persistente Volumes unverändert.

**Nur der Updater erhält Zugriff auf den Docker-Socket.** Web und Datendienst besitzen diesen Zugriff nicht.

Falls der Docker-Socket des Hosts nicht unter `/var/run/docker.sock` liegt:

```env
TRAKFOG_DOCKER_SOCKET=/DEIN/PFAD/docker.sock
```

Für bestehende Installationen vor Einführung von `trakfog-updater` ist einmalig ein normaler Stack-Redeploy erforderlich.

Der manuelle Weg bleibt weiterhin möglich:

```bash
cd TrakFog
git fetch origin stable
git switch stable
git pull --ff-only origin stable
docker compose up -d --build
```

Datenbank-Migrationen laufen beim Containerstart automatisch.


## Datenmigration und große Uploads

Unter **System → Datenmigration** können unter anderem TeslaMate-Backups hochgeladen werden. Der TrakFog-Webcontainer akzeptiert dafür Dateien bis **8 GiB**.

Bei Nutzung eines Reverse Proxys muss dessen Upload-Limit ebenfalls groß genug sein. Für Nginx / Nginx Proxy Manager kann bei Bedarf im erweiterten Proxy-Host-Setup zum Beispiel gesetzt werden:

```nginx
client_max_body_size 8G;
```

Alternativ kann ein sehr großes Backup im lokalen Netz direkt über den veröffentlichten TrakFog-Port hochgeladen werden. Das Upload-Limit sollte nur so hoch gewählt werden, wie es für die eigene Installation tatsächlich benötigt wird.


## 8. Backup

Wichtig sind insbesondere:

- `trakfog_db` — MariaDB
- `trakfog_runtime` — App-Key, internes Updater-Token und Laufzeitdaten
- `trakfog_logs` — Logs

Vor größeren Änderungen empfiehlt sich ein Backup von Datenbank und Runtime-Volume.
