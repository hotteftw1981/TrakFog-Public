# Security Policy · TrakFog Public Beta

TrakFog ist eine selbst gehostete Beta-Software zur Verarbeitung sensibler Fahrzeug- und Standortdaten. **Dies ist keine Garantie für einen sicherheitsgeprüften Produktivdienst.**

## Unterstützte Versionen

Sicherheitsfixes werden für den jeweils neuesten Release auf `stable` bereitgestellt. Ältere `main`-Entwicklungsstände sind keine freigegebenen Releases.

## Verantwortungsvolle Meldung

Bitte **keine** Tokens, VINs, Koordinaten, privaten Logs oder ausführbaren Angriffsnachweise in öffentlichen GitHub Issues posten. Vertrauliche Sicherheitsfunde sollten über GitHubs Funktion **Report a vulnerability** gemeldet werden, sofern diese in `Security` aktiviert ist. Vor der öffentlichen Freigabe muss der Maintainer Private Vulnerability Reporting einschalten und die Meldemöglichkeit prüfen. Ein öffentliches Issue ist für normale, bereits bereinigte Fehlerberichte geeignet.

## Ersteinrichtung und Authentifizierung

- Bei neuem Docker-Setup wird ein zufälliger 256-Bit-Installationsschlüssel einmalig im persistenten `trakfog_runtime`-Volume erzeugt. Nur der Besitzer des Servers liest ihn lokal: `docker exec trakfog-web cat /var/lib/trakfog/setup_token`.
- **Ohne Schlüssel kann niemand den ersten Owner im Web-Wizard anlegen.** Das ist besonders wichtig, wenn der Host bereits über eine öffentliche Domain erreichbar ist.
- Setup-Schlüssel, Datenbank-Passwörter, `TRAKFOG_APP_KEY`, Session-Cookies und Tesla-Tokens niemals ins Repository, Screenshots oder Supportmeldungen kopieren.
- Wiederholte fehlerhafte normale Logins und falsche LiveView-PINs werden zeitweise begrenzt.
- Session-Cookies sind HttpOnly und SameSite=Lax; für externen Betrieb muss ein HTTPS-Reverse-Proxy genutzt werden.
- Die öffentliche Registrierung ist deaktiviert; die LiveView ist standardmäßig deaktiviert und kann optional per PIN oder bewusst offen betrieben werden.

## Tesla, Logs und Privatsphäre

- Zugangstokens werden mit AES-256-GCM unter einem installationsspezifischen App-Key verschlüsselt gespeichert; Tesla-Passwörter werden nicht gespeichert.
- Nicht anonymisierte Live-Positionen, Reisen, Fahrtverläufe und Energie-/Ladedaten gehören grundsätzlich dem jeweiligen Betreiber der Installation.
- Interne Fehlerprotokolle sollen keine Zugangstokens oder Authorization-Header enthalten. Vor dem Teilen von Logs stets zusätzlich selbst prüfen.
- Reverse Geocoding über Nominatim ist optional und sendet Koordinaten an den konfigurierten Dienst. Hinweise: [Datenschutz & Datenflüsse](docs/PRIVACY.md).
- Heimliches Tracking fremder Fahrzeuge und Personen ist kein vorgesehener Einsatz.

## Docker und Update-Mechanik

Der getrennte `trakfog-updater` benötigt **weitreichenden Zugriff auf den Docker-Socket** des Hosts. Ein kompromittierter Updater hätte potenziell Host-Rechte. Nur auf einem eigenen, vertrauenswürdigen Docker-Host betreiben; Netzwerk des Updaters nicht extern veröffentlichen. Der Webcontainer und der Datendienst besitzen selbst keinen Docker-Socket-Zugriff. Administratoren sollen die Risiken vor einer Installation beurteilen.

## Deployment

Nur `public/` als Webroot bereitstellen, HTTPS erzwingen, `.env` lokal halten, Images/Host aktualisieren, die Datenbank und das Runtime-Volume gesichert verwahren und regelmäßige Backups **mit Wiederherstellungstest** anfertigen.

Nicht mit Tesla, Inc. verbunden. Tesla-Schnittstellen und deren Nutzungsbedingungen können sich ändern.
