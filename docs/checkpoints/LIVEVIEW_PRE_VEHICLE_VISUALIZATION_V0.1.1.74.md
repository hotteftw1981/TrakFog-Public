# TrakFog LiveView – gesicherter Zustand V0.1.1.74

**Zweck:** VERBINDLICHER Rollback-Punkt vor Punkt 5 / neuer Tesla-Fahrzeugvisualisierung (Model 3, Y, S, X und dynamische Garage).

## Exakte Git-Referenzen

- Gepruefter Release: **v0.1.1.74**
- Release-Commit SHA (exakt): `0ed2b720e769b90292b9d0299e43dc0cabd4a255`
- Backup-Branch: `checkpoints/liveview-pre-vehicle-visualization-v0.1.1.74`
- `stable` befand sich nach erfolgreicher Release-QA auf V0.1.1.74.
- Original-ZIP: https://github.com/hotteftw1981/TrakFog-Public/releases/download/v0.1.1.74/TrakFog-V0.1.1.74.zip
- Release: https://github.com/hotteftw1981/TrakFog-Public/releases/tag/v0.1.1.74
- Erfolgreicher Workflow: https://github.com/hotteftw1981/TrakFog-Public/actions/runs/37899133836

Die Branch-Referenz wurde nach Erstellung gegen die **exakte SHA** verifiziert.
**Wichtig: `stable` wird durch spaetere Releases weiterbewegt und ist deshalb NICHT selbst der Ruecksprungpunkt.**

## Funktional eingefrorener Zustand vor Punkt 5

- Vier LiveViews: Fahrzeug, LiveMap, Fahrt & Reise, NerdView, weiterhin im dunklen Cockpit-Design.
- Einheitliche, schlankere Telemetriezahlen nach Vorbild Kilometerstand/Temperaturen.
- Kilometerstand kaufmaennisch gerundet ohne Nachkommastellen.
- Tesla-Browser-Layouts Standard 773x601, Wide 1256x707 und Desktop 1920x911 weiterhin beruecksichtigt.
- Fahrzeugseite: genau ein zentrales Schlafsymbol (`Zz`) bzw. ein Park-`P`; die doppelte Gang-Anzeige ist ausserhalb der Fahrt ausgeblendet.
- Leistungsstatus: `Schlafmodus`, `Parkmodus`, `Standby`, `Verbrauch`, `Rekuperation`, `Ladevorgang` oder `Daten veraltet`; keine gespeicherten Powerwerte als angebliche Live-Werte.
- NerdView-Geschwindigkeitsinstrument: bei Fahrt echte km/h, im Stand/Schlaf/Laden/alt zustandsabhaengiges Symbol ohne km/h-Einheit; keine doppelte Park-Gang-Kachel.
- Laden: aktive Ladesession von zuletzt gespeicherten Messwerten getrennt; inaktiver Zustand zeigt `Keine aktive Ladung`, vorherige positive Energie nur `Zuletzt gemeldet` und keine erfundene Restladedauer.
- LiveMap / Route / Tesla-Follow / Tour-Fortsetzung / Rekuperations-Champion funktionieren nach bisheriger API und wurden nicht durch neue Auto-Grafiken ersetzt.
- Punkt 5 (neue 3D-/Fahrzeug-Garagenbilder und dynamische Modelle) wurde in **diesem Release NICHT** implementiert.
- GitHub-Docker-QA inkl. neuer `tests/test_liveview_modes.cjs` und vorheriger Regressionstests erfolgreich abgeschlossen.

## Wiederherstellung bei misslungener Fahrzeugvisualisierung

1. **Exakten Zustand finden:** `v0.1.1.74`, SHA oben oder Backup-Branch nutzen; nicht blind den dann aktuellen `main` oder `stable` nehmen.
2. Am Code-Vergleich erkennen, welche Aenderungen nach V0.1.1.74 ausschliesslich zu Punkt 5 gehoeren.
3. Fuer den gewohnten Auto-Updater bevorzugt **einen neuen vorwaerts versionierten Restore-Release** aus dieser funktionalen Basis erstellen und durch die volle GitHub-Docker-QA laufen lassen. So vermeiden wir Annahmen ueber Downgrades.
4. Alternativ die gesicherte ZIP mit der bestehenden Installations-/Restore-Dokumentation verwenden, wenn dies zur konkreten Installation passt; vorhandene Daten/DB und Versionskompatibilitaet zuerst pruefen. `stable` nicht ungeprueft hart zuruecksetzen.
5. Nach Restore alle vier LiveViews in PC und Tesla Standard/Wide vergleichen. Backend-Daten und DB bei reinem CSS/JS-Experiment unveraendert lassen.

**Goldene Regel:** Vor jeder Fahrzeugvisualisierungs-Aenderung muss dieser Snapshot als Referenz erhalten bleiben. Bei Nichtgefallen nur Punkt 5 rueckgaengig machen, die hier geprueften Korrekturen 1–4 behalten.
