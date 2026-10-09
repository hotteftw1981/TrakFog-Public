# Public Beta · Freigabegate

**Freigabestand (TrakFog-Public):** Der Repository-Switch auf `public` ist eine **bewusste Entscheidung des Maintainers** und wird nicht durch den Release-Workflow vorgenommen. Dieses Dokument trennt automatisierbare technische Prüfungen von Punkten, die keine CI belegen kann.

## Technik (automatisch)

- [x] Docker Compose mit Web, DB, Datendienst und getrenntem Updater.
- [x] Versionierter Release-Prozess mit Docker-QA, Tag, ZIP und `stable`-Fortschaltung nach erfolgreichem Test.
- [x] Browser-Wizard: erster Owner nur mit lokalem 256-Bit-Setup-Schlüssel, ungültige Schlüssel werden abgelehnt.
- [x] Rate-Limits für normale Logins und LiveView-PIN.
- [x] Webroot nur `public/`, Secret-Dateien außerhalb des ausgelieferten Baums.
- [x] Docker- und Portainer-Dokumentation, Security- und Datenfluss-Beschreibung.
- [x] Hinweise für Supportmeldungen ohne personenbezogene Daten.
- [ ] Ein fremder Tester installiert **ohne Hilfe** auf einem frisch aufgesetzten Docker-Host, verbindet den eigenen Tesla und prüft einen realen Lade-/Fahrtdatensatz. Die CI kann keine echte Tesla-Verbindung simulieren.

## Veröffentlichung (manuell, zwingend vor Umschalten auf Public)

- [x] Nicht freigegebene Tesla-Fahrzeugbilder komplett aus Public-Snapshot **und Git-Historie** ausgeschlossen; seit V0.1.1.91 sechs eigens generierte, transparente AVIF-Studioillustrationen enthalten. Siehe `docs/ASSET-ORIGINS.md`. TrakFog-Projektlogo verbleibt beim Berechtigten.
- [x] Frisches, separates Repository **TrakFog-Public** ohne private Entwicklungs-Git-Historie aufgebaut. In der Public-Git-Historie befinden sich keine alten Tesla-Fahrzeugbilder. Keine persönlichen Tokens aus der privaten Entwicklungsinstanz übernommen.
- [ ] Tesla-Datenzugang/Nutzungsbedingungen und eventuelle rechtliche Einschränkungen für das konkrete Projekt bewerten.
- [ ] GitHub Private Vulnerability Reporting aktivieren und testen; Issues/Discussions für Support aktivieren (manuelle GitHub-Einstellung).
- [ ] Standardbranch auf **`stable`** setzen (nicht `main`), damit neue Nutzer zuerst den freigegebenen Stand sehen. `main` bleibt Entwicklung.
- [ ] GitHub Pages auf **`stable` → `/docs`** stellen und anschließend Links, Dokumente, Impressums-/Datenschutzhinweise passend zum Website-Betrieb prüfen.
- [ ] Als Maintainer selbst **Settings → General → Danger Zone → Change repository visibility → Public** ausführen, erst wenn alle Gates erfüllt sind.
- [ ] Anschließend öffentlichen Clone **ohne Authentifizierung**, frischen Docker-Start, LiveView-Standardeinstellung und Release-ZIP-Link testen.

## Beta-Einordnung

Das erste öffentliche Release wird als **Public Beta** kommuniziert, nicht als vollumfänglich getestete 1.0. Ein Release mit GitHub-`prerelease`-Flag wird derzeit nicht verwendet, weil der integrierte Updater über `/releases/latest` bewusst nur reguläre Releases liest. Beta-Status steht sichtbar im Titel und in der Dokumentation.

Keine Garantie für dauerhafte Verfügbarkeit inoffizieller beziehungsweise sich ändernder Tesla-Endpunkte.
