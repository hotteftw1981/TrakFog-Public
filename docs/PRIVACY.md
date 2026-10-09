# Datenschutz & Datenflüsse · TrakFog Public Beta

Diese technische Transparenzbeschreibung erläutert, **welche Daten TrakFog verarbeitet**. Sie ersetzt nicht die Datenschutzerklärung, Rechtsgrundlage oder sonstigen Pflichten des jeweiligen Betreibers einer öffentlich oder gemeinsam genutzten TrakFog-Instanz.

## Wer betreibt die Instanz?

TrakFog ist selbst gehostet. In der normalen Installation laufen Web, MariaDB, Datendienst und Updater auf **deinem** Docker-Host. Der TrakFog-Entwickler erhält dadurch **keinen automatischen Zugriff** auf deine lokale Datenbank oder deine Fahrzeugpositionen. Die Verantwortung für Hosting, Zugriffsberechtigungen, Löschfristen, Backups und die gegebenenfalls notwendige DSGVO-Information liegt beim Instanzbetreiber.

## Verarbeitete Daten

- Konto: lokaler Benutzername, E-Mail-Adresse, Passwort-Hash, Rolle, Loginzeit und Sessions; Schutz vor wiederholten Loginversuchen.
- Tesla-Anbindung: verschlüsselte OAuth Access-/Refresh-Tokens, Ablauf- und Verbindungstatus, Fahrzeug-ID und VIN, wenn vom verbundenen Konto geliefert.
- Fahrzeug-/Routendaten: GPS-Koordinaten und Zeitstempel, Fahrten, Ladeorte, SoC, Reichweite, Geschwindigkeit, Kilometerstand und weitere verfügbare Telemetriewerte.
- Auswertungen: Fahrten- und Ladehistorie, Kosten, Orte/Geofences, Schlafdaten und eigene Einstellungen.
- Betriebsdaten: Diagnosen, technische Fehler-IDs, Status des Datendienstes, Updates, migrationsbezogene Protokolle und Backups.

Die Verarbeitung hängt von aktivierten Funktionen, Tesla-Verfügbarkeit und den jeweiligen Einstellungen ab.

## Externe Übertragungen

| Empfänger/Zweck | Wann | Steuerung |
| --- | --- | --- |
| Tesla-Dienste | Für Authentifizierung, Token-Erneuerung, Tesla-Datenzugriff und Stream | Tesla-Verbindung selbst verwalten und entfernen |
| Nominatim bzw. eigener Geocoder | Beim optionalen Reverse Geocoding gespeicherter Koordinaten | `TRAKFOG_GEOCODER_ENABLED=0` oder eigene Instanz |
| OpenStreetMap-Kartenkacheln bzw. konfigurierte Kartenquelle | Beim Betrachten von Karten; IP und Kartenausschnitt können beim Kachelanbieter anfallen | Zugriff/Provider und dessen Datenschutzhinweise beachten |
| GitHub | Release- und Update-Prüfung sowie Herunterladen einer freigegebenen Version | Optional manuell aktualisieren |
| Tesla Auth als separates externes Werkzeug | Nur wenn du selbst das verlinkte Werkzeug zur Token-Erzeugung nutzt | Dessen Projekt/Verfahren unabhängig prüfen |

TrakFog enthält kein verpflichtendes zentrales TrakFog-Cloud-Konto. **Eine vollständige Aussage zu Drittanbieter-Übermittlungen ist dennoch nur für die konkret konfigurierte Installation möglich.**

## LiveView und Freigaben

LiveView ist unabhängig vom regulären Login. Standardmäßig deaktiviert; der Betreiber kann PIN-geschützten oder bewusst offenen Zugriff wählen. Offener Modus macht die jeweils angezeigten Daten für Personen zugänglich, die den LiveView-Link erreichen. Öffentliche Links und Screenshots können Wohn- oder Arbeitsorte offenlegen.

## Auskunft, Export, Löschung, Backups

TrakFog besitzt Export- und Import-Funktionen für Fahrzeughistorien. Bei Löschung der Daten muss der Betreiber auch externe Backups, manuelle Exporte und eventuell beim Dritten gespeicherte Daten berücksichtigen. Es gibt **keine automatische Zusicherung** einer bestimmten Löschfrist; Retention und Sicherungsrhythmus sind vom Betreiber festzulegen.

Bei Weitergabe von Logs, GitHub-Issues oder Screenshots: VIN, genaue Ortsdaten, Nutzer/E-Mail, Tokens, persönliche Geofences und Session-Cookies unbedingt entfernen.

## Hinweis zum Tesla-Zugang

TrakFog ist ein unabhängiges Open-Source-Projekt und **nicht mit Tesla, Inc. verbunden**. Die derzeitige Tesla-Anbindung kann sich bei API- und Richtlinienänderungen ändern oder vorübergehend ausfallen. Der Betreiber muss die für seinen Zugriff geltenden Nutzungsbedingungen beachten.
