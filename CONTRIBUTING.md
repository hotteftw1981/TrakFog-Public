# Mithelfen · TrakFog Public Beta

Danke für dein Interesse an TrakFog! Bitte zuerst das aktuelle Release auf `stable` verwenden, bevor du einen Fehler meldest. `main` ist die laufende Entwicklung.

## Fehler melden

Bitte in GitHub **Issues** einen reproduzierbaren Fehler mit folgenden Angaben erstellen:

- installierte TrakFog-Version und GitHub-Release-Tag
- Installationsart (Docker Compose / Portainer), Host und Browser/Tesla-Browser
- erwartetes und tatsächliches Verhalten; Schritte zur Reproduktion
- anonymisierte Log-Ausschnitte / Screenshots, falls nötig

**Niemals posten:** Tesla Access-/Refresh-Tokens, API-Schlüssel, Setup-Token, Cookies, private URLs, vollständige VIN, Adressen und GPS-Punkte. Bei Sicherheitslücken bitte nicht öffentlich Details veröffentlichen, sondern `SECURITY.md` beachten.

## Entwicklung

- Änderungen auf einer eigenen Branch vorbereiten; Pull Request gegen `main`.
- Keine Änderung an freigegebenen Designpositionen ohne vorherige Abstimmung.
- Bei neuen Funktionen Regressionstests ergänzen, Dokumentation und `CHANGELOG.md` aktualisieren.
- Keine Drittanbieter-Assets oder Tesla-Grafiken ohne dokumentierte Verbreitungsrechte beisteuern.
- GitHub Actions baut vor einem Release den Docker-Stack und führt statische sowie Runtime-Checks durch.

## Datenschutz

Nur Testdaten oder ausdrücklich genehmigte anonymisierte Daten beisteuern; keine realen Fahrten, VINs oder Standortprofile in Issues/Commits. Hinweise: [Datenflüsse](docs/PRIVACY.md) · [Sicherheit](SECURITY.md).

## Lizenz

Code-Beiträge stehen unter [AGPL-3.0-only](LICENSE), soweit im jeweiligen Beitrag nichts Abweichendes zulässig geklärt wurde. Eigenständige Bild-/Markenrechte sind davon zu unterscheiden.

**Maintainer & Konzeption:** Patrick Garbe.
