# 🧾 TrakFog Changelog

## V0.1.1.91 – Neue TrakFog-Public-Fahrzeugbilder

- Alle sechs vereinfachten SVG-Platzhalter durch eigens generierte realistische, transparente Elektrofahrzeug-Studioillustrationen im AVIF-Format ersetzt (Model 3 Classic/Highland, Model Y Classic/Juniper, Model S/X).
- Die neuen Bilder sind lokal optimiert und enthalten bereits weiche Bodenschatten; die zusätzlichen veralteten Schattenebenen werden in der Public-Version ausgeblendet.
- Dashboard, LiveView, VIN-Auswahl, automatische Modellzuordnung, Qualitätsprüfungen und Installationsdokumentation angepasst.
- Keine fremden Tesla-Grafiken übernommen. Das private TrakFog-Repository und dessen Updatekanal bleiben unangetastet.

## V0.1.1.90 – Public Beta: generische SVGs auch in QA absichern

- QA-Vertrag für die vier Fahrzeugfamilien auf die eigens erstellten SVG-Vektoren umgestellt. Keine fehlenden AVIF-Dateien mehr erwartet.
- Der Test prüft SVG-Koordinaten, lokale Originalgrafiken ohne verlinkte Fremdbilder oder Scripts.


## V0.1.1.90 – Erste TrakFog Public Beta (sauberer Snapshot)

- Neuer, von Anfang an eigenständiger Public-Distributionszweig im Repository TrakFog-Public, ohne die private Entwicklungs-Git-Historie.
- Sechs extern nicht freigegebene Tesla-Renderings durch eigens erstellte, bewusst generische SVG-Elektrofahrzeugillustrationen ersetzt; alle sechs Varianten und LiveView-Pfade bleiben vorhanden.
- Proprietäre Bilddateien und ursprüngliche TrakFog-PNG-Logodateien nicht in die Public-Distribution übernommen; die eigens gestalteten TrakFog-SVG-Logos werden verwendet.
- README, Website, GitHub-Releases, Docker/Portainer-Installation und integrierten Update-Mechanismus auf TrakFog-Public umgestellt.
- Vorhandene Security-Fixes, Datenmigrationen, Stream und funktionale Tests aus V0.1.1.88 übernommen.
- Öffentliche Beta: ohne Garantie für langfristige Kompatibilität mit sich ändernden Tesla-Schnittstellen. Keine Verbindung zu Tesla, Inc.


## V0.1.1.88 – Public-Beta-Sicherheit: Login-Drosselung gegen Tabellenflut härten

- Normale Logins limitieren nun anhand der bestehenden Account-ID beziehungsweise eines einzigen Unknown-Account-Buckets pro Netzwerk-Peer, nicht anhand beliebig vieler eingegebener unbekannter Benutzernamen.
- Die Prüfung schützt die Login-Attempts-Datenbank vor einer Flut ständig wechselnder Fantasiebenutzernamen. Benutzername und E-Mail desselben Accounts landen in derselben Schranke.
- Gleichzeitige erste Fehlversuche erzeugen dank INSERT IGNORE keinen unerwarteten Duplicate-Key-Fehler.
- CI-Regression für den Unknown-Account-Bucket nachgeführt; Public-Beta-README ohne temporäre Privat-Statusbehauptung.
- V0.1.1.87-Sicherheitsfunktionen, alle Fahrzeugansichten und Tesla-Daten unverändert.


## V0.1.1.87 – Public Beta Candidate / Sicherheits- und Veröffentlichungs-Vorbereitung

- Frischer Browser-Wizard verlangt vor dem Erstellen des ersten Owners einen lokal erzeugten, 256 Bit starken Installationsschlüssel aus dem Webcontainer. Damit kann nicht der erste beliebige Website-Besucher die Instanz übernehmen; vorhandene Installationen behalten ihre Accounts.
- Neue, persistente Begrenzung wiederholter fehlgeschlagener normaler Logins (fünf Fehlversuche je Account-/Socket-Peer-Fingerprint in 15 Minuten; zehn Minuten temporäre Sperre). LiveView-PIN-Schutz bleibt vorhanden.
- Security-Policy für Public Beta, Transparenz der Datenflüsse, Beitragshinweise und ein GitHub-Bugreport-Template ergänzt.
- Docker- und Portainer-Anleitungen führen den lokalen Setup-Schlüssel, ohne ihn in Logs oder Release-Dateien zu veröffentlichen.
- Ausdrückliches Public-Freigabegate für Bild-/Markenrechte, Prüfung **auch der Git-Historie**, reale unabhängige Fremdinstallation, Tesla-Nutzungsbedingungen, Vulnerability Reporting, GitHub Pages und die finale manuelle Umstellung auf Public dokumentiert.
- Sicherheits-Regression der Owner-Übernahme (falscher/richtiger Schlüssel) und normale Login-Drosselung in die Docker-QA aufgenommen.
- Fahrzeugposition, Darstellung, Schatten, LiveView, Fahrten und Tesla-Daten-Polling bleiben unverändert.


## V0.1.1.86 – Sichtbarer, weicher Unterbodenschatten

- Der zusätzliche Verlauf aus V0.1.1.85 war optisch zu schwach. Eine eigene, deutlichere, weich auslaufende Schattenebene verbindet jetzt den Fahrzeugunterboden zwischen Vorder- und Hinterrad mit dem Boden.
- Diese Schattenebene ist im Fahrzeug-Container verankert und folgt der perspektivischen Linie zwischen den Rädern. Keine unabhängige Schattenfläche auf der Bühne.
- Die freigegebene Fahrzeugposition aus V0.1.1.83, Fahrzeuggröße und die beiden Reifenschatten aus V0.1.1.84 bleiben exakt erhalten.
- LiveView, mobile Ansichten, Fahrzeugbilder und Tesla-Datenverarbeitung unverändert.
- Regressionstest prüft die separate Ebene hinter dem Fahrzeugbild und die unveränderte Position.


## V0.1.1.85 – Weicher Schatten zwischen den Rädern

- Ausschließlich auf dem Dashboard eine zusätzliche, subtile und weich auslaufende Schattenebene direkt unter dem mittleren Fahrzeugunterboden ergänzt (fahrzeuggebundene Bildkoordinaten).
- Die exakt freigegebene Position aus V0.1.1.83 (`bottom: calc(9% - 60px)`), Fahrzeuggröße und Perspektive bleiben unverändert.
- Vorder-/Hinterrad-Kontaktschatten sowie die bisherigen Fahrzeugzustandsfilter bleiben vollständig unverändert.
- Keine Änderungen an LiveView, Lade-/Fahrtdaten, Bildern, mobilen Breakpoints oder Animationslogik.
- Regressionstest schützt den zusätzlichen weichen Mittelschatten und die unveränderten Positionen.


## V0.1.1.84 – Verfeinerter Bodenschatten, Fahrzeugposition fixiert

- Fahrzeugposition und Abmessungen von V0.1.1.83 bleiben unverändert (Desktop-Anker `bottom: calc(9% - 60px)`).
- Fahrzeuggebundener Unterbodenschatten minimal breiter und weicher; Vorder- und Hinterreifenschatten etwas kräftiger, weiterhin an den originalen Bildkoordinaten verankert.
- Dezent verstärkter Alpha-/Kontaktschatten in Normal-, Schlaf- und Lademodus; kein zusätzlicher Bühnenschatten oder schwarzer Balken.
- LiveView, Tesla-Daten, Bilder, Bedienung und responsive Fahrzeugpositionen unverändert.
- Ergänzte Regression für alle Kontaktpunkte und für die unveränderte V0.1.1.83-Fahrzeugposition.


## V0.1.1.83 – Dashboard: Tesla mittig zwischen den letzten Layoutvarianten

- Großes Dashboard-Fahrzeug auf Desktop-Bildschirmen 60 px tiefer als V0.1.1.82 positioniert (CSS-Bodenanker `calc(9% - 60px)`).
- Reifen- und Kontaktschatten bleiben direkt an die Fahrzeug-Grafik gebunden; Fahrzeuggröße, Perspektive, Farben und Fahrzeugbilder unverändert.
- Nur Desktop ab 1181 px betroffen; Tesla-/Tablet-/Mobil-Breakpoints und LiveView unverändert.
- Regressionstest für Fahrzeugposition und die bestehenden getrennten Reifenschatten erweitert.


## V0.1.1.82 – Bugfix: Fahrzeugschatten direkt an den Reifen

- Root Cause: transparenter Bildrand unter dem Auto und Schatten unabhängig von der skalierten Fahrzeug-Grafik positioniert.
- Vorder- und Hinterreifenschatten jetzt mit dem Fahrzeug im gleichen 1448x1086-Bild-Koordinatensystem verankert; die unterschiedliche perspektivische Höhe wird berücksichtigt.
- Kein schwarzer Balkenschatten mehr, kleiner echter Kontakt-Schatten direkt an den Reifen und kompakter Alpha-Schatten am Fahrzeug.
- Dashboard nutzt die vorhandene fahrzeuggebundene Bühne, LiveView erhält einen gemeinsamen Bild-/Schatten-Wrapper inklusive neutralem Fallback bei fehlendem Artwork.
- Browsergeprüft bei 1880x820, 1260x780, 773x601 und Dashboard 1650x800, keine Session-Überlappung und kein Seiten-Scrollen.
- Kein Redesign: Fahrzeugbilder, Schriftgrößen, Karten, VIN-Varianten, Tesla-API und Datenverarbeitung unverändert.
- Neuer Regressionstest für beide Kontaktpunkte und korrektes Verstecken der Schatten bei fehlendem Bild.
- Sicherungsstand V0.1.1.74 bleibt unangetastet.



## V0.1.1.81 – Showroom mit echtem Bodenkontakt / Desktop & Tesla QA

- LiveView-Showroom aus dem V0.1.1.80 Design anhand echter Browser-Screenshots bei 1880x820, 1260x780 und 773x601 nachkalibriert.
- Getrennter Kontaktschatten für Vorder- und Hinterreifen: Die Schatten liegen in der jeweiligen Perspektivhöhe, nicht als ein gemeinsamer Balken unter dem Auto.
- Responsives Fahrzeugformat: auf großem Desktop gut sichtbar, auf mittelgroßen Tesla-/Laptop-Browsern nicht zu groß, auf kleinen Tesla-Browser-Viewports vollständig sichtbar.
- Nachjustiertes Bodenraster/Glow und ruhige Fahrzeugdarstellung im Schlafmodus.
- Passender Zz-Schlafmodus-Gauge gemäß freigegebener Vorlage, ohne Geschwindigkeits-Einheit im Schlafzustand.
- Klarere Beschriftung, Kontraste und Einheitengrößen in den LiveView-Session-Karten.
- Dashboard-Titel bleibt auf breiten Bildschirmen einzeilig, Schriftgrößen und Info-Karten nutzen den vorhandenen Raum besser.
- Layoutregression: kein überlappender Session-Hinweis, keine unerwartete Seiten-Scrollfläche, korrekte dynamische Telemetrie-Knoten.
- Model 3/Y Varianten, Tesla API/Polling und alle Fahrzeugbilder unverändert.
- Rollback-Checkpoint V0.1.1.74 weiterhin vorhanden.



## V0.1.1.80 – LiveView & Dashboard Showroom-Finale

- Die zwei freigegebenen Designvorlagen als echte Code-Layouts in LiveView und Dashboard umgesetzt; keine statische Bildersetzung.
- Fahrzeug-LiveView: Wagen an transparente Originalbild-Proportionen angepasst, stärker zur Bühne und sichtbaren Reifenposition ausgerichtet.
- Der bisher isoliert darunterliegende schwarze Balkenschatten entfällt. Neues dezentes Bodenraster, flacher Ambient-Schatten und Kontaktzonen unmittelbar an der Wagenunterseite.
- Dashboard-Showroom: Kontaktschatten reist mit der Fahrzeugdarstellung mit; größerer und besser lesbarer Showroom, ohne schwebenden Bühnenring.
- Alle neuen Dashboard-Kennzahlen mit einer einheitlichen SVG-Iconfamilie, klareren Schriftgrößen und ruhigeren Einheiten.
- LiveView: Schlafmodus über zurückhaltendes Mond-Symbol statt dominantem Zz; bei fehlender Live-Leistung Symbol statt scheinbar aktuellem kW-Messwert.
- Die vier Session-Karten verwenden klare Linien-Symbole, echte Telemetriewerte und situativ richtige Bezeichnungen: Fahrt (Strecke/Verbrauch) vs. Ladung (Geladen/Ladeleistung).
- Für Tesla Standard/Wide und kleinere Viewports sind kompakte Layoutregeln enthalten; Animation-Reduzierung bleibt erhalten.
- Bestehende Routen-, Fahrt-, Akku-, VIN-/Varianten- und Telemetrielogik bleibt unangetastet.
- Neuer Regressionstest fuer dynamische Labels, SVG-Symbole, Schatten-Bindung, Tesla-Breakpoints und Datenfelder.
- Unveraenderter Ruecksprungpunkt: V0.1.1.74 mit Backup-Branch checkpoints/liveview-pre-vehicle-visualization-v0.1.1.74.



## V0.1.1.79 – LiveView Bildqualität & Session-Layout

- Alle sechs Tesla-Fahrzeugvarianten (Model Y Classic/Juniper, Model 3 Classic/Highland, Model S/X) verwenden jetzt saubere, einheitliche, freigestellte Fahrzeugbilder ohne alten Quellbild-Boden oder helle Fenster-Hintergrundreste.
- Vorhandene WebP-/AVIF-Dateinamen und Fahrzeugeinstellungen bleiben kompatibel; Bilder werden unverändert lokal geladen.
- LiveView-Cache-Busting am Fahrzeugbild ergänzt, damit Tesla- und PC-Browser nach dem Update neue Bilddaten zeigen.
- Die Leerlaufmeldung der aktuellen Session liegt nun in einer eigenen Grid-Zeile **oberhalb** der vier Kennzahlen, nicht mehr als absolute Ebene auf den Kacheln.
- Für kleine Tesla-Displays ist der Hinweis kompakt und die KPI-Fläche bleibt lesbar und anklickbar.
- Aktive Ladesession/Fahrt, VIN-Generationen, manuelle Fahrzeugdarstellung und der Zustand des Fahrzeugs bleiben unverändert.
- Neuer Regressionstest für alle sechs Bildpfade, echtes AVIF/WebP, keine Leerlauf-Überlagerung und Image-Cache-Versionierung.
- Der gesicherte Pre-Garage-Checkpoint V0.1.1.74 bleibt unangetastet.



## V0.1.1.78 – Tesla VIN-Jahreskennungen komplettieren

- Ergänzung der VIN-Jahreskennung für Model 3 aus den Jahren 2017-2019 (H/J/K).
- Unterstützung für Teslas in deutschen Service-Unterlagen genannte alternative 2024-Jahreskennung E zusätzlich zu R.
- Zusätzliche Regressionstests: Model 3 Klassisch aus 2019 und Model Y Klassisch mit E-Kennung 2024.
- Sämtliche V0.1.1.77-Funktionen bleiben erhalten: sechs lokale Modellvarianten, VIN-Generationserkennung, manuelle Fahrzeugauswahl, 20% kleineres Auto, Boden-/Reifenschatten und schönerer Leermodus der LiveView.
- Pre-Garage-Sicherungsstand V0.1.1.74 bleibt unberührt.



## V0.1.1.77 – Tesla Classic/Facelift: VIN-Automatik und geerdete Bühne

- Neu: alte Model-Y- und Model-3-Varianten als lokal mitgelieferte freigestellte WebP-Bilder. Dazu weiter Model 3 Highland, Model Y Juniper, Model S und Model X.
- VIN-Erkennung ohne externe Datenübertragung: Tesla-Herstellerkennung, Modellzeichen und 10. Stelle des VIN-Modelljahrs. Nur konservative Generationseinordnung; kein Facelift aus unsicheren Übergangsjahren geraten (3: 2023, Y: 2025).
- Pro Fahrzeug persistente, manuelle Auswahl unter Fahrzeugdetailseite → Fahrzeugdarstellung. Wahl wird pro Fahrzeug in vorhandenen Settings gespeichert und serverseitig validiert.
- Dashboard und LiveView nutzen dieselbe ermittelte Generation und Auswahl. Bei nicht bestimmbarer Generation neutrale Darstellung statt falscher Karosserie.
- Beide Fahrzeugbühnen: Wagen etwa 20 % kleiner, stärkere Reifenschatten, flacherer Bodenring und bodenständige Lichtführung.
- Fahrzeug-LiveView: informativerer Leermodus für keine aktive Fahrt oder Ladung.
- Regressionstest für VIN-Jahresgrenzen, unsichere Übergangsjahre, Modellvalidierung, manuelle Auswahl und sechs ausgelieferte Grafikdateien.
- Keine neue Datenbankmigration und keine zusätzliche Tesla-Weckabfrage.
- Rollbackpunkt V0.1.1.74 bleibt unverändert erhalten.
- Nutzungsrechte der bereitgestellten Fahrzeugbilder vor Weiterverbreitung prüfen (siehe docs/VEHICLE-VARIANTS.md).



## V0.1.1.76 – LiveView Tesla-Visualisierung: verlaessliche Modellkennung

- LiveView nutzt zuerst die aus gespeicherten Fahrzeug-/Snapshot-Daten bekannte Tesla-Fahrzeugkennung car_type.
- Dies ist mit der bereits vorhandenen TrakFog-Dashboard-Logik abgestimmt: auch wenn die Textangabe model_name fehlt, erscheint beim Model Y nun das Model-Y-Bild, sofern die gespeicherte Konfiguration den Modellcode kennt.
- Fall zurueck auf lesbaren Modellnamen nur bei fehlendem oder nicht erkanntem car_type; keinerlei Ableitung aus Spitznamen wie 'Hotte Y'.
- Keine zusaetzliche Tesla-Anfrage; keine Aenderung an Livemap, Reisen oder NerdView.
- Test fuer beide Datenquellen (gespeicherte Fahrzeugkonfiguration und Snapshot) ergaenzt.
- Baseline-Rollback vor der Visualisierung bleibt der gepruefte Release V0.1.1.74.



## V0.1.1.75 – LiveView 2.5: Tesla-Fahrzeugvisualisierung (Model 3/Y/S/X)

- Fahrzeug-LiveView zeigt das passende Model 3/Y/S/X aus den bereits enthaltenen lokalen AVIF-Modellbildern.
- Keine Modellzuweisung aus privaten Nicknames, sondern ausschliesslich aus gespeicherten Fahrzeugdaten. Unbekannte Modelle bekommen eine neutrale Anzeige statt falschem Bild.
- Detaillierte, aber ruhige Fahrzeugbuehne: dunkles Schlaf-Design, Park-Aura, Energiefluss beim Laden, statische Fahr-/Strassen-Akzente und dezenter Offline-/Alt-Daten-Modus.
- Originale Geschwindigkeits-, Akku-, Reichweiten- und Leistungsinstrumente bleiben in kompakter Cockpitform erhalten.
- Eigene Anpassungen fuer Tesla Standard, Tesla Wide und PC; keine neuen Tesla-API-Anfragen.
- Effektanimationen pro Browser unter Display & Zugang deaktivierbar; prefers-reduced-motion wird beachtet.
- Asset-Fallback bei Ladefehlern, bei Fahrzeugwechsel Modellbild und Modus sofort neu gesetzt.
- Extra Regressionstests zu vier Tesla-Modellen, neutralem Fallback, State-Wechseln, Bildpfaden, Motion-Settings und Responsivitaet.
- Unveraenderte Sicherheitsbasis vor diesem Experiment: v0.1.1.74, Commit 0ed2b720e769b90292b9d0299e43dc0cabd4a255, Backup-Branch checkpoints/liveview-pre-vehicle-visualization-v0.1.1.74.



## V0.1.1.74 - Cockpit-Zustandslogik und gesicherte Basis fuer Fahrzeugvisualisierung

- Fahrzeugansicht: Schlafmodus zeigt ein einzelnes ruhiges Zz-Symbol, Parken ein einzelnes P; doppelter Gang-/Symbol-Hinweis entfernt.
- Leistungsanzeige: Status statt widerspruechlichem "LIVE neutral"; Schlafmodus, Parkmodus, Standby, Rekuperation und echter Verbrauch werden passend zum aktuellen Messzustand angezeigt.
- Alte oder nicht frisch gestreamte Leistung wird nicht laenger als Live-Wert ausgegeben.
- NerdView: Hauptinstrument zeigt je nach Zustand Geschwindigkeit, Parken, Schlaf, Laden oder veraltete Daten an; km/h nur bei Fahrt, keine redundante Gang-Kachel im Park-/Schlafmodus.
- Laden: nur laufende Ladesessions zeigen aktuelle Ladeleistung und elektrische Werte; gespeicherte Energiemessungen heissen "Zuletzt gemeldet", wenn keine Ladung aktiv ist. Null Stunden bis voll aus alten Daten wird nicht als aktuelle Prognose ausgegeben.
- Bestehende Tesla-Standard-/Wide-/PC-Layouts und neue Zahlentypografie bleiben erhalten.
- Zusaetzlicher LiveView-Regressionscheck prueft Schlafen, Parken, Fahrt, Laden, Stale-Daten und historische Ladewerte.
- Dieser Release dient als freigegebene Ruecksprungbasis VOR der geplanten Fahrzeugbilder-/Garagen-Visualisierung.



## V0.1.1.73 - LiveView: einheitliche Zahlen & Einheiten

- Einheitliches, ruhigeres Zahlensystem fuer alle vier LiveViews nach Vorbild der Kilometerstand- und Temperaturanzeige.
- Drei abgestufte Schriftstaerken fuer Hauptinstrumente, grosse Messwerte und KPI-Werte; tabellarische, gleichbreite Ziffern.
- Geschwindigkeit, Akku-Ringe, Reichweite, Leistung, Fahrt- und Reisekennzahlen sowie LiveMap-Telemetrie auf dieselbe Zahlentypografie abgestimmt.
- Dezente Einheiten statt ueberbetonter km/h-, km-, kW- und Prozentangaben; Fahrzeugnamen, Standorttexte und Zustandsmeldungen behalten ihre eigene Hierarchie.
- Tesla Standard 773x601, kleinere und flache Displays mit eigenen Schriftgroessen gegen abgeschnittene Werte; Wide/PC bleiben separat ausgewogen.
- Vorhandene Messwertpraezision und echte negative Rekuperationswerte bleiben unveraendert.
- Neuer Regressionstest fuer Typografie, Anzeigeelemente und Tesla-Layout in Docker-QA eingebunden.



## V0.1.1.72 - Tesla Browser Praxistest (Standard und Wide)

- Zwei echte Tesla-Browser-Layouts als Referenz: Standard 773 x 601 und Wide 1256 x 707 CSS-Pixel.
- NerdView Standard: die expliziten zwei Spalten bekommen feste Kartenpositionen; die zuvor automatisch entstehende dritte Grid-Spalte entfällt. Instrument, Akku, Fahrzeug, Klima und Position bleiben auch auf engem Bildschirm geordnet.
- Fahrzeug Standard: Reichweite und Leistungsstatus werden nicht mehr durch zu grosse Schrift auf '314...' oder 'Ver...' abgeschnitten; kompaktere Werte mit umbruchfaehigem Status.
- Fahrt und Reise: bei fehlender aktiver Reise werden die Spalten auf mittleren Landscape-Viewports ausgeglichen und der Leermodus platzsparender dargestellt.
- Untere Seitenanzeige auf Tesla Standard besser lesbar und einfacher zu erkennen.
- Wide/XL-Desktop und bestehende LiveMap-Funktionen bleiben unveraendert; die Tesla-Breakpoints sind durch neue QA-Vertraege abgesichert.



## V0.1.1.71 - LiveView Cockpit Layout: vier Ansichten im Praxischeck

- Fahrzeugseite: auf breiten Bildschirmen Geschwindigkeitsinstrument und Akku-/Leistungsanzeige nebeneinander; Instrument zentriert, weniger leere Flächen. Schmale und flache Bildschirme behalten eine kompakte Anordnung.
- Fahrt/Reise: bei fehlender aktiver Reise ausgeglichene Spalten statt übergrosser Leerkachel. Kennzahlen fuer aktuelle Fahrt und Ladesession mit groesseren, besser lesbaren Werten.
- NerdView: Temperaturkacheln nutzen die verfügbare Höhe, Instrument besser ausbalanciert; die dezente Zahlen-Typografie und ganze Kilometer bleiben erhalten.
- LiveMap bleibt absichtlich unveraendert, da die Steuerung und das Telemetrie-HUD im Praxistest bereits stimmig waren.
- Regressionstest fuer vorhandene Datenfelder, Leerzustand und responsive Layoutregeln zur bestehenden GitHub-Docker-QA hinzugefuegt.



## V0.1.1.70 - NerdView Typografie und Messwertformatierung

- Kilometerstand in der NerdView ab jetzt kaufmaennisch auf volle Kilometer gerundet (keine Nachkommastelle).
- Dezimalregeln fuer zentrale LiveView-Messwerte vereinheitlicht: Temperatur/Leistung 1, Akku/Reichweite/Tempo 0, Netto-Stream-Energie 2 Nachkommastellen.
- Fehlende oder ungueltige Messwerte bleiben als Strich sichtbar; echte negative Rekuperationswerte bleiben erhalten.
- Weniger klobige, einheitlich ausgerichtete Temperatur-, Kilometerstands-, Akku-, Leistungs- und Geschwindigkeitszahlen mit feineren Einheiten.
- Neue Formatierungs-Regressionstests in der GitHub-Docker-QA.



## V0.1.1.69 - LiveView 2.0 Cockpit Design

- Neues einheitliches Dark-Cockpit-Design auf allen vier LiveView-Seiten: Fahrzeug, LiveMap, Fahrt/Reise und NerdView.
- Grosszuegigeres Geschwindigkeitsinstrument, besser lesbare Leistungs-/Verbrauchsdaten und hervorgehobene Live-Informationen.
- Abgestimmte Karten, Kontraste und Akzentlinien auf Fahrzeug-, Reise- und Technikansicht.
- LiveMap mit konsistenter kompakter Steuerung und deutlicherem Telemetrie-HUD.
- Responsive Anpassungen fuer grosse und kleine Browserfenster sowie flache Displays; Reduced Motion beruecksichtigt.
- Vorhandene Fahrzeugbilder, Fahrdaten, Kartensteuerung und PIN-Zugang bleiben erhalten.



## V0.1.1.68 - LiveMap Cockpit Redesign

- Kartensteuerung mit kompakten einheitlichen Buttons statt uebergrossen Kreisen.
- Zukuenftige, nicht nutzbare Kartenaktionen in der Fahrtansicht verborgen.
- Deutlich besser lesbare LiveMap-Kennzahlen und responsiver Karten-HUD.
- Gemeinsame dunkle Cockpit-Oberflaeche fuer Fahrzeug, Reisen und NerdView.
- Vorhandene Kartennavigation und Tesla-Fahrzeugbilder beibehalten.



## V0.1.1.67 - LiveView Preview: Rekuperation und Kartenlesbarkeit

- Negative Netto-Stream-Energie als valide Energiebilanz behandeln und im LiveView als Rekuperations-Champion ausweisen.
- LiveMap zeigt vorzeichenbehaftete Netto-Stream-Energie einer aktuellen Fahrt.
- Lesbarere Kartenbuttons und kompaktere responsive Werkzeugleiste.
- Verbesserte Fokusanzeige und einheitlichere NerdView-Kartenkonturen.
- Unveraenderte Tesla-Modellbilder und bestehende Kartennavigation.



## V0.1.1.66 - Fahrt-Akku-Bilanz gegen Ladepausen absichern

- Fehleranalyse aus dem Praxistest: Eine Fahrt von 05:11 bis 05:30 mit nur 5 Minuten Fahrtzeit enthielt eine Ladung 05:17-05:29. Die +3 SoC-Punkte gehoeren zur Ladung, nicht zum Fahren.
- Bei aktiver Tesla-Ladung wird eine noch offene Stream-Fahrt nun an der letzten dokumentierten Fahrprobe VOR Ladebeginn geschlossen; SOC, Route und Reichweite der Fahrt stammen nicht vom spaeteren Ladewert.
- Park-/Stale-Messungen schreiben nicht mehr nachtraeglich den Lade-SoC als Fahr-End-SoC; die Stream-Energie wird nur ueber Fahrmesspunkte integriert.
- In historisch gespeicherten Fahrten mit zeitlicher Ladeueberlappung wird der Netto-SoC NICHT als Fahrverbrauch angezeigt. Ein dezenter Hinweis ersetzt die irrefuehrende positive Fahr-Bilanz in Liste, Einzelfahrt und Karte.
- Schutz ohne Datenverlust: Historische Fahrtzeitraeme und Roh-Messpunkte werden nicht automatisch umgeschrieben. Ladehistorie und Fahrtenergie bleiben getrennte Quellen. Keine Schemaaenderung.
- Regressionstests fuer Ladeueberlappung, fehlende SoC-Werte, Ladebeginn-Grenze, spaete P-Meldungen und Stream-Energiemessung.

## V0.1.1.65 – Kompakte Fahrten & VoltCore-Messvergleich

- Fahrtenhistorie als kompakte, responsive Zeilen mit kurzen Adressen, Strecke, Fahrzeit, Wh/km, Höchsttempo und SoC; vollständige Start-/Zieladressen, Zeitstempel, Energie, Stream-Messpunkte, Routen- und Löschaktionen sind per Klick aufklappbar.
- Bestehende Auswahl und echte Zusammenführung direkt aufeinanderfolgender Fahrten bleibt erhalten; die Auswahlleiste wurde verschlankt.
- Neuer Energievergleich in Lade-Details und in der Ladehistorie für Ladevorgänge mit explizit zugeordnetem VoltCore-Zählerwert: gemessene Differenz in kWh und Prozent, Quellenklarheit und Vergleichsbalken. Keine erfundenen VoltCore-Daten, keine automatische Rückübertragung behauptet.
- Admins können pro TrakFog-Ladesession eine VoltCore-Energiemenge und optionale VoltCore-Session-ID mit CSRF-Schutz hinterlegen oder entfernen. Neue idempotente Migration `0.1.1.65.sql`.
- Nicht bestätigte Kosten werden als „offen“ statt als 0,00 € angezeigt; nur ausdrücklich gespeicherte kostenlose Ladungen haben 0,00 €.
- Regressionstests für Vergleichsformel, fehlende Werte, umgekehrtes Verhältnis, kompakte Fahrtenansicht, Klick-Interaktion und vorhandenen Merge-Prozess.


## V0.1.1.64 – Klickbare Kennzahlen, ehrliche Stream-Diagramme & frei verschiebbare LiveMap

- Dashboard-Kennzahlen und KPI-Karten sämtlicher relevanter Unterseiten als echte, tastaturbedienbare Links mit Hover-/Fokus-Feedback und nachvollziehbaren Zielen.
- Geschwindigkeitskurve nutzt das maximale **gemessene** Tempo pro Zeitfenster statt des 2-Minuten-Mittels; der angezeigte Maximalwert wird nicht mehr als garantiertes Fahrtmaximum ausgegeben.
- Zeitlücken im Tesla Driving Stream werden als unterbrochene Graphen dargestellt. Rohmesspunktanzahl, Kurvenpunkte und Datenlücken werden nachvollziehbar ausgewiesen; keine künstliche Beschleunigungs-Linie über Stunden ohne Messung.
- LiveMap-Dragging und Follow-Button wiederhergestellt: Drag mit Maus/Touch pausiert die Auto-Zentrierung, ein Klick auf „Tesla folgen“ schaltet sie erneut ein. Kein automatisches Herauszoomen.
- Keine zusätzlichen Tesla-API-Abfragen und keine Datenbankmigration.
- Neue Regressionstests für KPI-Navigation, manuelle Kartensteuerung und Lückendarstellung in Charts.

## V0.1.1.63 - Echte Fahrt statt extra Tour

- In der Fahrtenliste werden zwei (oder mehrere direkt folgende) abgeschlossene Fahrten nach Bestätigung tatsächlich in einen gespeicherten Fahrt-Datensatz zusammengeführt.
- Die Rubrik fuer virtuelle, doppelte Tour-Eintraege wurde entfernt: aus 2 Fahrten wird 1 Fahrt.
- Strecke, Energie, Maximum, Ladezustand und GPS-Endpunkte werden kombiniert; die Fahrzeit addiert nur die einzelnen Abschnitte ohne den Supermarktstopp.
- Plausibilitaet: gleiches Fahrzeug, keine fehlende Zwischenfahrt, keine laufende Fahrt, maximal 2 Stunden Pause und bei vorhandenen GPS-Daten maximal 2 km Differenz.
- MariaDB-Transaktion mit Audit-Snapshot der Originalwerte, Reiseverknuepfungen bleiben erhalten und alte virtuelle Gruppeneintraege werden bei der Umwandlung entfernt.
- LiveView setzt die rote Linie weiterhin im Browser fort; das dauerhafte Zusammenfuehren erfolgt nach Fahrtende im Backend.
- Neue DB-Migration 0.1.1.63 (fahrzeit + interne Sicherungsinformationen).

## V0.1.1.62 - Fahrtenliste lesbar und Tour-Speichern sichtbar

- Start und Ziel jeder Fahrt mit 14-16px Schrift, klaren Start-/Zielbezeichnungen und besserem Kontrast; mobile Ansicht untereinander.
- Die Auswahlleiste erscheint beim Markieren von Fahrten dauerhaft am unteren Bildschirmrand und zeigt den naechsten Schritt an.
- Bei mindestens zwei Fahrten desselben Fahrzeugs: "Tour pruefen & speichern" mit echter Vorschau von Start, Ziel und Zwischenstopps.
- Eindeutige Speicherbestaetigung auf der anschliessenden gemeinsamen Tour-Karte; die Originalfahrten bleiben erhalten.
- Kartenzugang einer Einzelfahrt jetzt eindeutiger benannt, Verwechslungsgefahr mit Zusammenfuehren reduziert.
- Kein Eingriff in gespeicherte Roh-GPS-Punkte oder Kilometerzaehlung.

## V0.1.1.61 - Short trip detection & manual trip deletion

- Completed Tesla-stream trips under 100 meters are discarded only if odometer / GPS evidence safely confirms the short distance. Merely selecting D or R is no longer a saved trip.
- Missing or contradictory telemetry is handled conservatively: real drives are not automatically deleted by uncertain 0-km readings. Stream samples remain stored for diagnosis.
- The minimum-distance threshold can optionally be adjusted with `TRAKFOG_MIN_TRIP_DISTANCE_METERS` (default 100, `0` disables automatic filtering, maximum 1000).
- Owners and administrators can permanently delete completed trips from the trip list or detail page, with a two-step confirmation dialog and CSRF protection. Active trips cannot be deleted.
- Deleting a trip updates trip statistics, cascades journey/merged-tour memberships and removes any merged-tour shell left with fewer than two drives. Raw stream telemetry remains intact.
- Added short-trip regression tests and database deletion integration tests.

## 🚗 V0.1.1.60 — Multi-Tesla Garage & Stopover Merge

### Fahrzeugdarstellung
- Separate freigestellte, lokal gehostete Modelle 3, S und X; Model Y beibehalten.
- Modell anhand der Tesla-Konfiguration / Modellinformation automatisch erkennen, mit Model-Y-Fallback.
- Klick auf das Fahrzeugbild in der Dashboard-Karte führt zu den Fahrzeugdetails; Klick auf die übrige Karte wählt das Fahrzeug.
- Hero-Bild und gestaffelte Fahrzeuge wechseln automatisch zum jeweiligen Modell.

### LiveMap
- Alle Fahrzeuge aus derselben Instanz mit gespeicherter Position standardmäßig als Marker anzeigen; ausgewählten Tesla hervorheben und zentrieren.
- Klick auf anderen Fahrzeugmarker wechselt Auswahl; kein Auto-Zoom-out, keine zusätzlichen Tesla-API-Aufrufe.
- In der PIN-LiveView sind die Standorte aller Instanzfahrzeuge sichtbar.

### Fahrten verbinden
- Neu: mehrere aufeinanderfolgende Teilfahrten in der Fahrtenliste markieren und verlustfrei zusammenführen.
- Gemeinsame Tour mit Gesamtstrecke, mehreren Zwischenstopps, redlicher GPS-Routenzeichnung und Einzelverweisen; Zusammenführung ist umkehrbar.
- Originalfahrten/Telemetrie/Journey-Zuordnungen werden nicht verändert; Summen bleiben korrekt.
- Kurzen Halt bis 60 Min/1 km erkennen und sowohl in Fahrten als auch LiveView vorschlagen.
- LiveView-Topbar kann im angemeldeten Backend die vorherige und laufende Fahrt dauerhaft verbinden; reine PIN-Nutzer sehen eine lokale Fortsetzungsvorschau ohne Schreibrechte.
- Neue idempotente Datenbankmigration für trip_merges / trip_merge_members.

### QA
- Syntaxprüfungen für PHP/JS, Bild-Alpha und Modellzuordnung, Regeln für Merge-Vorschläge; keine neuen Tesla-Polling-Aufrufe.

## 🚘 V0.1.1.59 — Living Garage 2.0

- Das weiße Tesla Model Y aus der Nutzervorlage erneut sauber freigestellt; transparentes AVIF ohne grauen Bodenfleck.
- Hauptfahrzeug kleiner und passend zur Bühne; Mini-Fahrzeugkarten ohne abgeschnittenes Bild, Datenfluss bleibt lesbar.
- Animierter Bodenring, dezenter Nebel, Horizontglow und Lichtwanderungen auf der Dashboard-Bühne.
- Unterschiedliche browserseitige Effekte für **schläft, wach, lädt, fährt, offline** – ohne zusätzliche Tesla-API-Abfragen.
- Datenfluss-Impuls und sanfte Hervorhebung der Kennzahlen **nur bei tatsächlichen Änderungen**.
- Barrierearme Animationseinstellung **Aus / Dezent / Vollgas**, im Browser gespeichert, OS-reduzierte Bewegung berücksichtigt.
- Dashboard-spezifische CSS/JS-Dateien, über App-Version cachegebustet.

## 🚘 V0.1.1.58 — Real Model Y Dashboard

### Dashboard
- Holografische, selbst gezeichnete SVG endgültig entfernt und durch die vom Nutzer bereitgestellte, freigestellte Tesla Model Y-Vorlage ersetzt.
- Transparente AVIF-Datei liegt direkt im Repository unter `public/assets/vehicles/model-y-white.avif`; keine externen Bildserver oder getrennten Downloads erforderlich.
- Fahrzeuggrafik in Mini-Karten und auf der zentralen Dashboard-Bühne vereinheitlicht; Schatten, ruhiger Glow und dynamische Statuszustände beibehalten.
- Bis zu sieben Fahrzeuge können gestaffelt auf der Bühne stehen. Aktuell ladende Fahrzeuge werden bei der Staffelung bevorzugt.
- Farbvarianten und Responsive-Regeln überarbeitet; die Anzeige funktioniert auch bei nur einem Fahrzeug.

### QA
- Docker-QA überprüft nun das echte Bild-Asset und den Verzicht auf die alte SVG; die bisher auf SVG-Pfade zugeschnittenen Checks wurden entfernt.

## 🌫️ V0.1.1.57 — Holographic Vehicle Silhouette

### Dashboard-Fahrzeug
- die zu helle, cartoonartige Vollflächen-Grafik bewusst verworfen
- neue **dunkle holografische Fahrzeug-Silhouette** mit sauberer Seitenlinie statt grauem Blob
- Dachlinie, Schulter, Fensterband, Radläufe, Türlinien, Schweller und Felgen als feine technische Konturen
- Fahrzeugfarbe dient nur noch als dezenter Akzent; der Hero bleibt in allen Zuständen dunkel und hochwertig
- Hauptfahrzeug kleiner und ruhiger auf der Bühne positioniert, damit Text und Szene wieder ausbalanciert wirken

### Zustände
- **schläft** → fast schwarze Silhouette mit sehr zurückhaltender blauer Kontur
- **online** → dezenter Glow ohne helle Karosseriefläche
- **lädt** → animierte Scanline + Energiefluss + Ladeimpuls
- **fährt** → schnellere Scanline und dynamische Datenlinien
- Mini-Fahrzeugkarten nutzen dieselbe dunkle Silhouettenlogik

### QA
- Dashboard-Regressionsprüfung um Roofline, Scanline, Felgen-/Speichendetails und Sleeping-Silhouette erweitert
- vollständige Docker-QA inklusive authentifiziertem Dashboard-Smoke-Test bleibt grün

## 🚘 V0.1.1.56 — Dashboard Vehicle Art Hotfix

### Fahrzeugdarstellung
- die provisorische, zu grobe Fahrzeug-SVG aus V0.1.1.55 vollständig überarbeitet
- deutlich flachere, sauberere Tesla-inspirierte Seitenlinie mit realistischeren Proportionen
- neue Schulterlinie, Radläufe, Tür-/Gürtellinien und Felgendetails
- Fahrzeugfarben bleiben datengetrieben, wirken aber weniger plakativ und mehr wie eine Silhouette
- Sleeping-Zustand zusätzlich abgedunkelt, damit das Fahrzeug ruhiger in die Szene eingebettet ist

### Dashboard-Polish
- rein numerische interne Tesla-Trimcodes wie **"50"** werden im sichtbaren Modellnamen nicht mehr angezeigt
- Status-Icon beim vierten Hero-Messwert reagiert jetzt passend auf **Laden / Fahren / Schlafen / Online / Offline**
- Hauptfahrzeug etwas besser proportioniert und auf der Hero-Bühne positioniert

### QA
- Regressionstest für verfeinerte Fahrzeugpfade, Felgendetails, Trimcode-Filter und zustandsabhängiges Hero-Icon ergänzt
- vollständige Docker-QA inklusive authentifiziertem Dashboard-Smoke-Test bleibt grün

## 🌌 V0.1.1.55 — Living Vehicle Dashboard

### Dashboard
- Startseite vollständig als **lebendiges Fahrzeug-Dashboard** neu aufgebaut
- Fahrzeuge stehen jetzt kompakt **nebeneinander** statt untereinander
- Fahrzeugkarten zeigen Name, Modell, SoC, Mini-Fahrzeug, Status und Statusdetail
- ausgewähltes Fahrzeug bekommt den TrakFog-blauen Fokusrahmen
- Lieblingsfahrzeug kann direkt über den Stern markiert werden und bleibt browserlokal gespeichert
- Auswahl und Favorit bleiben auch während des 5-Sekunden-Live-Refresh stabil

### Cinematic Hero
- große Hero-Bühne füllt die bisher leere Dashboard-Fläche
- eigene leichte SVG-Fahrzeugsilhouette ohne zusätzliches schweres Bild-Asset
- Fahrzeugfarbe wird aus bekannten Tesla-Konfigurationsdaten abgeleitet, soweit vorhanden
- Hero zeigt SoC, Reichweite, Ladelimit/Fahrstatus, Leistung/Statusdetail, Außentemperatur und Datenzeitpunkt
- direkter Wechsel zwischen Fahrzeugen aktualisiert Hero und Detail-Link ohne Seitenreload

### Zustandsabhängige Animation
- **schläft** → gedimmte, ruhige Szene mit langsamer Fog-Bewegung
- **online / wach** → dezenter aktiver Glow
- **lädt** → Ladeport, Kabel, pulsierendes Fahrzeug und fließende Energielinien
- **fährt** → schnellere Datenlinien, bewegtes Grid und leichte Fahrzeugdynamik
- **offline** → entsättigte, gedimmte Darstellung und klarer Wartezustand
- `prefers-reduced-motion` deaktiviert die Bewegungsanimationen auf Wunsch des Browsers

### Mehrere Teslas
- 3–7 Fahrzeuge bleiben horizontal nebeneinander; bei kleineren Viewports ist die Leiste sauber horizontal scrollbar
- wenn das ausgewählte Lieblings-/Fokusfahrzeug lädt und weitere Fahrzeuge ebenfalls laden, erscheinen bis zu zwei weitere Fahrzeuge **schräg dahinter**
- das Fokusfahrzeug bleibt dabei immer visuell dominant
- Infokarte weist auf mehrere gleichzeitig ladende Fahrzeuge hin

### QA
- eigene statische Dashboard-Prüfung für Hero, Fahrzeugauswahl, Favorit, Multi-Car-Bühne und Animationen
- JavaScript-Syntaxprüfung für das Dashboard
- authentifizierter Docker-Smoke-Test prüft Fahrzeugleiste, Hero und dynamische Zustandsklasse
- vollständige Docker-QA inklusive TeslaMate-Import, Integration API, Updater und First-Run-Wizard bleibt grün

## 🧭 V0.1.1.54 — First-Run Setup & Release Readiness

### Ersteinrichtung
- neue browserbasierte **TrakFog-Ersteinrichtung** für frische Docker-/Portainer-Installationen
- Systemcheck vor dem ersten Owner mit Datenbank-, Runtime-, App-Key- und HTTPS-Hinweisen
- erster Owner wird race-sicher nur auf einer noch unbeanspruchten Installation angelegt
- Owner-Passwort wird ausschließlich als Passwort-Hash gespeichert
- nach der Owner-Erstellung kann Tesla direkt verbunden oder bewusst übersprungen werden
- letzter Schritt bietet wahlweise frischen Start oder direkten Einstieg ins Migrationscenter

### Docker / Portainer
- frische Installationen benötigen keine Owner-Zugangsdaten mehr zwingend als Environment-Variablen
- bleiben Owner-E-Mail und Owner-Passwort leer, startet der komplette Stack und wartet auf den Web-Wizard
- automatisierte/headless Installationen können den Owner weiterhin vollständig per Environment anlegen
- vollständig per Environment angelegte Installationen werden automatisch als eingerichtet markiert
- Portainer-Dokumentation korrigiert: TrakFog besteht aus **vier** Containern inklusive `trakfog-updater`

### Bestehende Installationen
- vorhandene TrakFog-Installationen werden beim Update automatisch als bereits eingerichtet erkannt
- V0.1.1.54 zwingt bestehende Nutzer daher **nicht** erneut durch den Setup-Wizard
- unvollständige Ersteinrichtungen führen nach dem Login wieder zurück in den Assistenten

### Roadmap & Dokumentation
- Datenmigration und Integration API korrekt nach **Bereits da** verschoben
- Docker-, Portainer-, README- und Projektseiten-Dokumentation auf den neuen First-Run-Ablauf aktualisiert
- `stable` bleibt die dokumentierte Installations- und Release-Quelle; `main` bleibt Entwicklung/Test

### QA
- eigener Docker-QA-Pfad erzeugt eine komplett frische zweite Testdatenbank ohne Owner-Environment
- geprüft werden Web-Setup-Status, Owner-Claim und persistenter Abschluss der Ersteinrichtung
- bestehender Env-Owner-Bootstrap, TeslaMate-Migration, Integration API, Update-System und übrige Runtime-Smokes bleiben grün

## 🔗 V0.1.1.53 — Integration API v1

### VoltCore-Vorbereitung
- neue **Integration API v1** als sauberer Unterbau für TrakFog ↔ VoltCore
- TrakFog bleibt Fahrzeugdaten-Provider; externe Systeme müssen keine Tesla-Tokens oder Tesla-API-Logik kennen
- Fahrzeugzuordnung über TrakFog-ID und VIN vorbereitet

### API
- `GET /api/v1/vehicles`
- `GET /api/v1/vehicles/{id}/status`
- liefert SoC, nutzbaren SoC, Ziel-SoC, Reichweite, Kilometerstand, Ladezustand und Ladeleistung
- liefert Datenfrische als **fresh / stale / offline / unknown** inklusive Alter und Zeitstempel
- lokale TrakFog-Ladesession wird bei aktivem Laden mit ausgegeben

### Sicherheit
- eigene API-Tokens mit Scope **vehicle:read**
- zufällige 256-Bit-Tokens mit `tfk_`-Präfix
- Token wird nur einmal vollständig angezeigt; gespeichert wird nur der SHA-256-Hash
- Token-Zugänge können einzeln gesperrt werden
- konfigurierbares Rate-Limit, Standard 120 Requests pro Minute
- Request-Audit ohne Payloads oder Tesla-Zugangsdaten
- Tesla Access-/Refresh-Tokens werden über die Integration API niemals ausgegeben

### System
- neuer Bereich **System → Integration API**
- API-Zugang direkt im Backend erstellen und sperren
- aktive Zugänge, Requests und Fehler der letzten 24 Stunden sichtbar
- Endpunkte und Scope direkt im Systembereich dokumentiert

### QA
- Docker-QA prüft API-Dateien, Datenbankmigration und Systemintegration
- echter End-to-End-Test für Bearer-Auth, Fahrzeugliste und Fahrzeugstatus
- Tests für Token-Hashing, Sperrung und Rate-Limit
- bestehende TeslaMate-Migration und übrige Runtime-Tests weiterhin grün

## ↔ V0.1.1.52 — Data Migration Center

### Daten mitnehmen
- neues **System → Datenmigration** als eigenes Import-/Export-Center
- TeslaMate-Backups werden automatisch erkannt und vor dem Import analysiert
- Vorschau zeigt erkannte Tabellen und – bei Plain-SQL-Backups – Datensatzmengen
- Import läuft im Hintergrund weiter und zeigt Fortschritt, Status und Fehler
- große Importdateien bis 8 GiB werden vom Webcontainer unterstützt

### TeslaMate → TrakFog
- offizieller PostgreSQL-`pg_dump` / `.bck`-Weg wird unterstützt
- Plain-SQL-Dumps werden direkt gestreamt; kein fremder Datenbankserver wird benötigt
- PostgreSQL Custom Dumps werden kontrolliert über `pg_restore` tabellenweise gelesen
- Fahrzeuge werden primär über VIN vorhandenen TrakFog-Fahrzeugen zugeordnet
- Fahrten, Positionspunkte, Ladevorgänge, Geofences sowie Status-/Schlafdaten werden übernommen, soweit vorhanden
- Positionsimporte werden für große Historien gebündelt verarbeitet
- Dubletten-Schutz verhindert blindes Mehrfachimportieren

### Portabilität
- vollständiger portabler **TrakFog-Datenexport** als ZIP
- Export enthält Fahrzeug-, Fahrten-, Positions-, Lade-, Orts-, Status-, Tarif- und Reisedaten
- keine Passwörter oder Tesla-Zugangstokens im Datenexport
- TrakFog-Exporte können wieder in eine andere TrakFog-Instanz importiert werden
- QA testet Export und Re-Import inklusive Dubletten-Schutz

### Weitere Quellen
- TeslaLogger, TeslaFi, Tessie, Teslascope, TRONITY, TezLab und allgemeines CSV/JSON sind im gemeinsamen Adaptermodell vorbereitet
- diese Adapter werden bewusst erst nach echten, versionierten Testexporten freigeschaltet

### Sicherheit & QA
- Datenmigration nur für Owner und Administratoren
- CSRF-Schutz für alle schreibenden Aktionen
- fremde SQL-Backups werden niemals direkt gegen die TrakFog-Datenbank ausgeführt
- Dateien liegen isoliert im persistenten TrakFog-Datenbereich und werden nach erfolgreichem Import entfernt
- Docker-QA prüft Migrationstabellen, Runtime-Werkzeuge, TeslaMate-Testimport, portablen Export und Re-Import

## 🧭 V0.1.1.51 — Independent Project Credits

### Projektzuordnung
- TrakFog wird in Oberfläche, Dokumentation und Projektseite eindeutig als eigenständiges Projekt geführt
- fremde Organisationszuordnungen aus den Credits entfernt
- **Konzeption & Entwicklung:** Patrick Garbe
- **Technische Unterstützung:** Buddy ⚡
- Unterzeile bei Patrick auf **Projektinitiator & Entwickler** geändert

### Bereinigung
- Credits im globalen Info-Modal korrigiert
- **System → Info** korrigiert
- README bereinigt
- GitHub Pages / Projektseite bereinigt
- bestehender Changelog von der falschen Projektzuordnung bereinigt

### QA
- Docker-QA verhindert künftig, dass die entfernte Organisationszuordnung oder projektfremde Info-Bezeichnungen wieder in TrakFog auftauchen

## ⓘ V0.1.1.50 — Info & Credits

### Sidebar
- der bisherige breite **Darstellung**-Button ist jetzt zweigeteilt
- **Darstellung** und **Info** sitzen gleichberechtigt nebeneinander
- **Info** öffnet eine kompakte Systeminfo-Infobox
- der Modal-Hintergrund schließt die Infobox bewusst nicht per Klick

### Systeminformationen
- Status, Version, Plattform und aktueller Bereich auf einen Blick
- Kurzbeschreibung der TrakFog Community Edition
- **Konzeption & Entwicklung:** Patrick Garbe
- **Technische Unterstützung:** Buddy ⚡
- **Digitale Entwicklungsassistenz**
- Lizenzhinweis **AGPL-3.0-only**
- Hinweis auf die Unabhängigkeit von Tesla, Inc.

### System → Info
- neuer eigener **Info**-Anker im Systemmenü
- dieselben Informationen und Credits zusätzlich direkt im Systembereich
- zentrale gemeinsame Darstellung, damit Credits und Versionsdaten nicht auseinanderlaufen

### UX
- Info-Modal funktioniert auf Desktop und Mobile
- beim Öffnen auf Mobile wird der Navigationsdrawer zuerst geschlossen
- Schließen über X oder Escape
- Fokus kehrt nach dem Schließen zum Info-Button zurück

### QA
- Docker-QA prüft Info-Button, Modal, System-Info und Credits
- `ChatGPT` / `OpenAI` werden im sichtbaren Community-Code weiterhin ausgeschlossen

## 🔵 V0.1.1.49 — TrakFog Blue

### Neue Grundfarbe
- die bisherige rote Primärfarbe wurde auf die Blauwelt des neuen TrakFog-Logos umgestellt
- Primärblau: `#159cff`
- kräftiger Sekundärton: `#2169ff`
- Buttons, Fokusrahmen, aktive Navigation, Auswahlzustände und Brand-Glows verwenden jetzt die neue TrakFog-Farbwelt
- LiveView übernimmt dieselbe blaue Primärfarbe

### Login & Branding
- TrakFog-Logo im Login-Fenster horizontal zentriert
- Tagline unter dem Logo ebenfalls zentriert
- LiveView-PIN-Seite richtet die Wortmarke ebenfalls mittig aus

### Rot bleibt semantisch
- Rot bleibt bewusst für Fehler, Löschen, kritische Zustände und deaktivierte/off-Zustände erhalten
- die rote Fahrtroute auf der Karte bleibt als eigene Kartensemantik bestehen
- Heatmap-Endstufe bleibt weiterhin rot

### Karten- und Editor-Akzente
- Fahrzeugmarker nutzt jetzt TrakFog-Blau
- Geofence-Flächen, Editor-Griffe und Auswahlzustände wurden auf Blau umgestellt
- dekorative Fahrzeug- und UI-Glows folgen der neuen Brandfarbe

### QA
- Docker-QA prüft jetzt explizit die blaue Brandpalette
- rote Legacy-Primärfarben in App und LiveView werden als Regression erkannt
- die Zentrierung des Login-Logos wird statisch geprüft

## 🎨 V0.1.1.48 — Community Branding

### Neues TrakFog Branding
- neues TrakFog-Logo konsequent in der Community-Version eingesetzt
- Login verwendet jetzt die echte Wortmarke statt des alten `TF`-Platzhalters
- Desktop-Sidebar nutzt die TrakFog-Wortmarke
- mobile Topbar verwendet das TrakFog-Icon
- LiveView-PIN-Seite und LiveView-Topbar wurden ebenfalls gebrandet
- Fehlerseiten verwenden das neue Logo statt eines Text-Platzhalters

### Browser & Web-App
- TrakFog-Icon als Favicon eingebunden
- neues Web-App-Manifest `site.webmanifest`
- App-/Browsername und Theme-Farbe vereinheitlicht
- Manifest wird mit passendem MIME-Type ausgeliefert

### Projektauftritt
- README verwendet das neue Logo
- GitHub Pages / Projektseite verwendet das neue Logo in Header, Hero und Footer
- Logo-Asset liegt zusätzlich direkt unter `public/assets/brand/`
- bestehendes TrakFog-Icon bleibt für kompakte UI-, Favicon- und App-Stellen erhalten

### QA
- Branding-Assets und ihre Einbindung werden jetzt automatisch in Docker-QA geprüft
- alte sichtbare `TF`-Platzhalter in Login, App-Shell, LiveView und Fehlerseite werden statisch ausgeschlossen

## 🐳 V0.1.1.47 — Docker-Native Updates

### Docker Compose als Standard
- neue vollständige Anleitung `docker/DOCKER.md`
- README und GitHub Pages stellen Docker Compose jetzt als primären Installationsweg dar
- Portainer bleibt als optionale Verwaltungsoberfläche dokumentiert
- Installationen folgen weiterhin dem geprüften `stable`-Branch

### Integrierter Docker-Updater
- neuer interner Container `trakfog-updater`
- Ein-Klick-Updates benötigen keinen Portainer-Webhook mehr
- Web und Datendienst besitzen weiterhin keinen Docker-Socket-Zugriff
- ausschließlich der Updater erhält den Docker-Socket
- Updater besitzt keinen veröffentlichten Host-Port
- interne Update-Schnittstelle wird zusätzlich mit einem persistenten Zufallstoken geschützt

### Release-Verifikation
- Update-Center prüft das neueste GitHub Release unmittelbar vor der Installation
- Updater prüft unabhängig davon nochmals das aktuell veröffentlichte Release
- akzeptiert wird ausschließlich das fest hinterlegte Repository `hotteftw1981/TrakFog-Public`
- geladen wird exakt der veröffentlichte Release-Tag
- `VERSION` im geladenen Archiv muss mit der Zielversion übereinstimmen
- Web und Datendienst werden aus diesem verifizierten Stand neu gebaut

### Fehlerbehandlung
- asynchroner Updater-Fehler wird zurück ins Update-Center gespiegelt
- fehlgeschlagene Updates bleiben nicht mehr dauerhaft als `update_pending` hängen
- nach einem Fehler kann erneut aktualisiert werden
- erfolgreicher Neustart wird weiterhin durch den Docker-Bootstrap bestätigt

### Release-Sicherheit
- Release-Workflow wartet jetzt auf den vollständigen Docker-QA-Lauf
- `stable` wird erst nach erfolgreicher Docker-QA und erfolgreichem Release weitergeschoben
- fehlerhafte Docker-Builds können dadurch nicht mehr vorzeitig zum Stable-Stand werden

### Karte
- Heatmap-Legende reagiert jetzt auf die tatsächlich aktive Analyse
- `Lade-Hotspots` zeigt eine passende Lade-Legende
- `Fahrdichte` zeigt eine passende Fahrdichte-Legende
- ohne vorhandene Heatmap-Daten erscheint keine sinnlose Legende

### Upgrade von V0.1.1.46
- für die einmalige Einführung von `trakfog-updater` ist ein normaler Stack-Redeploy erforderlich
- danach können weitere Stable-Updates direkt aus **System → Updates** installiert werden

### QA
- echter Vier-Container-Stack inklusive `trakfog-updater`
- Docker-Compose-Konfiguration und Updater-Image werden gebaut
- Updater-Health, Docker-Socket und Compose-Plugin werden geprüft
- Update-Status und Startup-Reconciliation laufen im echten Stack
- alte Portainer-Webhook-Abhängigkeiten werden statisch ausgeschlossen

## ⬆ V0.1.1.46 — Stable Self-Update System

### Update Center
- neuer Bereich **System → Updates**
- zeigt installierte und verfügbare Stable-Version
- prüft das neueste veröffentlichte GitHub Release statt Entwicklungscommits
- Release Notes und Veröffentlichungsstatus direkt im System-Center
- manuelle Prüfung jederzeit möglich
- GitHub-Abfragen werden gecacht, damit normale Seitenaufrufe GitHub nicht unnötig belasten

### Stable-Kanal
- neuer Git-Branch `stable`
- `stable` zeigt ausschließlich auf den zuletzt erfolgreich veröffentlichten TrakFog-Release
- Release-Workflow schiebt `stable` nach erfolgreichem GitHub Release automatisch auf den Release-Commit
- Portainer-Installationen verwenden künftig `refs/heads/stable` statt `main`
- dadurch kann der integrierte Updater keine unfertigen Entwicklungscommits installieren

### Portainer Ein-Klick-Update
- Portainer Stack-Webhook kann im Update-Center hinterlegt werden
- vollständiger Webhook wird verschlüsselt mit dem lokalen TrakFog App-Key gespeichert
- im UI wird nur der Portainer-Host angezeigt, niemals der geheime Webhook-Pfad
- akzeptiert werden ausschließlich URLs im Portainer-Stack-Webhook-Format
- vor der Installation wird das aktuelle Stable Release nochmals frisch bei GitHub verifiziert
- angeforderte Zielversion muss exakt dem neuesten Stable Release entsprechen
- Update-Modal verlangt die bewusste Bestätigung, dass Portainer `refs/heads/stable` verwendet
- Modal schließt nicht durch Klick auf den Hintergrund

### Private und öffentliche Repositories
- öffentliche TrakFog-Releases benötigen keinen GitHub-Token
- für die aktuelle private Entwicklungsphase kann optional ein GitHub-Token hinterlegt werden
- GitHub-Token wird verschlüsselt gespeichert
- Token und Portainer-Webhook werden nie im Update-UI wieder ausgegeben

### Neustart & Migrationen
- Update wird vor dem Portainer-Redeploy als `update_pending` gespeichert
- beim neuen Containerstart laufen wie bisher alle fehlenden Datenbank-Migrationen
- anschließend vergleicht TrakFog die tatsächlich gestartete Version mit der angeforderten Zielversion
- erfolgreicher Start wird automatisch als Update-Erfolg gespeichert
- `update_pending` wird erst nach erfolgreichem Start der Zielversion gelöscht
- Update-Modal beobachtet `health.php` und erkennt, wenn die neue Version wieder online ist

### Fallback
- manueller Docker-Compose-Updateweg bleibt sichtbar:
  - `git fetch origin stable`
  - `git switch stable`
  - `git pull --ff-only origin stable`
  - `docker compose up -d --build`

### QA
- Webhook-Verschlüsselung im echten Docker-Stack getestet
- bewusst falsche Webhook-URLs werden abgelehnt
- Webhook-Secret darf nicht im HTML erscheinen
- Update-Verfügbarkeit aus gecachtem Release getestet
- Stable-Referenz wird im UI und Release-Workflow geprüft
- Bootstrap-Reconciliation bestätigt Zielversion und leert `update_pending`
- kompletter Docker-QA-Lauf erfolgreich

## 🔬 V0.1.1.45 — Tesla 403 Deep Diagnostics

### Strukturierte Tesla-Fehler
- Python- und PHP-Connector lesen Teslas tatsächliche Fehlerantwort statt nur den HTTP-Status zu behalten
- gespeichert werden Endpoint, HTTP-Status, HTTP-Version, Tesla-Fehlertext, Fehlerbeschreibung und optionale `txid`/Request-ID
- Klartext-Antworten wie `forbidden, see Fleet API` bleiben für die Diagnose sichtbar
- JWT-/Bearer-artige Werte werden defensiv redigiert

### Refresh, Retry & Fallback
- Diagnose hält fest, ob ein Token-Refresh versucht wurde
- Ergebnis des Wiederholungsversuchs wird gespeichert
- erfolgreicher Recovery-Versuch wird als behoben markiert
- aktiver Known-Vehicle-Fallback wird ausdrücklich ausgewiesen
- Token-Ablaufzeit wird als Metadatum gespeichert, niemals der Token selbst

### System-Center
- neuer aufklappbarer Bereich „Letzte Tesla-API-Diagnose“
- zeigt Bereich, Endpoint, HTTP/Transport, Tesla-Meldung, Beschreibung, txid, Token-Ablauf, Refresh, Retry, Fallback und Zeitpunkt
- Diagnose bleibt von normalen Verbindungshinweisen getrennt

### QA & Datenschutz
- echte HTTPX-403-Antwort wird mit Tesla-Text, txid und HTTP/2 simuliert
- System-Smoke-Test prüft alle Diagnosefelder
- absichtlich eingeschleuste Fake-Secrets dürfen nicht im HTML erscheinen
- kompletter Docker-QA-Lauf erfolgreich

## 🛡️ V0.1.1.44 — Tesla Pipeline Recovery & Freshness

### Kritischer Worker-Fix
- Regression aus der HTTPX-Umstellung gefunden und behoben
- zwei verbliebene `response.ok`-Prüfungen durch korrektes HTTPX-`response.is_success` ersetzt
- dadurch kann der Tesla-Poll nach V0.1.1.42 wieder tatsächlich Antworten auswerten statt nur einen frischen Worker-Heartbeat zu schreiben
- neue QA prüft diesen Fehlerpfad mit simulierten HTTPX-Antworten im echten Worker-Container

### HTTP 403 / Fahrzeugliste
- `/products` wird nicht mehr als einzige Lebensader des Datendienstes behandelt
- bei HTTP 403 versucht TrakFog höchstens kontrolliert einen neuen Access Token über den korrigierten HTTP/2-/TLS-1.3-Auth-Weg
- der automatische 403-Refresh besitzt einen Cooldown und läuft nicht in eine Refresh-Schleife
- bleibt `/products` eingeschränkt, verwendet TrakFog bereits bekannte Fahrzeuge als lokalen Fallback
- Driving Stream und lokale Historie bleiben davon unabhängig
- Fahrzeugerkennungsfehler werden separat gespeichert statt den gesamten Tesla-Zugang als fehlerhaft darzustellen

### Datenfrische statt falschem Online
- gespeichertes `online` ist nicht mehr unbegrenzt gültig
- System-Center berücksichtigt letzten Stream-Event, letzten Snapshot und Streamstatus
- schlafende/wartende Fahrzeuge werden als solche dargestellt
- alte, nicht bestätigte Zustände werden ausdrücklich als „Daten veraltet“ markiert
- LiveView erhält den neuen Zustand `stale` und zeigt keine falsche Park-/Online-Sicherheit mehr

### System-Health
- ein frischer Heartbeat bedeutet nicht mehr automatisch „Datendienst gesund“
- `worker_state=error` oder `stopped` wird jetzt als Fehler gewertet
- `degraded` und `stale` erzeugen einen sichtbaren Hinweis
- eigener Diagnosecheck für Tesla-Fahrzeugerkennung
- eigener Diagnosecheck für Datenfrische
- letzter Poll zeigt, ob Daten aus Tesla `/products` oder dem lokalen Fallback stammen

### Weitere gefundene Fehler
- System-Center fragte den nicht existierenden Stream-Zeitstempel `last_message_at` ab
- korrekt ist `last_event_at`; der bisher verschluckte DB-Fehler ist behoben
- doppelter Fahrzeuglisten-403-Hinweis im System-Center entfernt
- alte `Fahrzeugliste`-Fehler in `integrations.last_error` werden per Migration bereinigt

### Rechercheabgleich
- TeslaMate V4.0.1 behebt den Juni-2026-403 über HTTP/2 + TLS 1.3 am Tesla-Auth-Weg
- TeslaMate nutzt bei nicht verfügbarer Fahrzeugliste ebenfalls bereits bekannte Fahrzeuge als Fallback
- aktuelle TeslaPy-Versionen setzen für Auth und Owner API ebenfalls HTTP/2/TLS 1.3 ein
- Fleet-API-403 „missing scopes“ wird bewusst von dem Owner-API-Fehler „forbidden, see Fleet API“ unterschieden

## 🚗 V0.1.1.43 — Tesla LiveView Usage Modes

### Seite 1 · Fahren
- LiveView konsequent als 1-Sekunden-Blick während der Fahrt aufgebaut
- Tacho weiter nach oben gezogen
- Gang direkt im Tachobereich unter km/h
- Akku-Kreis direkt unter dem Tacho
- neuer Leistungs-Kreis daneben, kW ohne Nachkommastelle
- Rekuperation wird am Leistungs-Kreis grün dargestellt
- Reichweite und Leistungsrichtung stehen direkt an den Kreisen
- aktuelle Session rechts als 2x2-Raster mit deutlich größeren Werten
- Standort deutlich größer lesbar
- separate Akku-Karte entfernt
- Tesla-Browser-Werte auf etwa Innen-/Außentemperatur-Größe angehoben

### Seite 2 · Reise
- große Reise-KPIs auf den eigentlichen Zweck reduziert:
  - Gesamtzeit
  - Gesamtstrecke
  - gesamt geladene Energie
  - Ladekosten
- Fahrten- und Ladestopp-Anzahl bleiben sekundär im Kopf
- Leerzustand für Tesla-Browser neu gestapelt, damit kein Text abgeschnitten wird

### Seite 3 · Karte
- Kartenbuttons deutlich höher und fingerfreundlicher
- zukünftiger Standort-Schalter als eigener großer runder Touch-Button
- Standortbutton jetzt größer als zwei alte Kartenbutton-Höhen
- grün = Standort an
- rot = Standort aus
- Folgebedienung ebenfalls vergrößert

### Seite 4 · NerdView / Stand
- Seite ausdrücklich als Stand-/Technikansicht positioniert
- Verriegelt und Sentry aus der Fahrzeugkarte entfernt
- Reifendruck aus dem LiveView entfernt
- stattdessen klare Zusammenfassung „ALLE TÜREN & KLAPPEN GESCHLOSSEN“ oder konkrete offene Elemente
- Fahrzeugkarte dadurch kleiner
- Positionskarte bekommt mehr Fläche
- doppelte Straße/Straßenart-Zeile entfernt
- Adresse fällt auf den bekannten Ortsnamen zurück, solange Reverse Geocoding noch läuft
- technische Akku-, Lade-, Klima-, Software-, Kilometer- und Positionsdaten bleiben hier konzentriert

### Tesla-Browser
- eigener kompakter Landscape-Layoutpfad für das tatsächlich nutzbare Tesla-Browserfenster
- wichtige Werte bleiben groß statt Desktop-UI nur herunterzuskalieren

## 🔐 V0.1.1.42 — Tesla HTTP/2 + TLS 1.3 Compatibility

### Eigentlicher 403-Fix
- Tesla Auth und Owner API werden im Python-Datendienst jetzt über HTTP/2 angesprochen
- TLS 1.3 ist für Tesla Auth/Owner API die Mindestversion
- Python-Tesla-Client von HTTP/1.1-`requests` auf `httpx` mit HTTP/2-Unterstützung umgestellt
- PHP-Tesla-Connector erzwingt ebenfalls HTTP/2 + TLS 1.3
- damit wird derselbe Transportwechsel nachvollzogen, mit dem TeslaMate die Owner-API-403-Probleme seit Juni 2026 behoben hat

### Isolation
- Charging History / Supercharger-Kosten behalten einen separaten HTTP-Client
- die funktionierende Kosten-Synchronisierung wird nicht an die strengere Owner-API-TLS-Policy gekoppelt
- Driving Stream bleibt unabhängig

### Diagnose
- System-Center zeigt den verwendeten Tesla-Transport an
- irreführende 403-Meldung „fehlende Datenberechtigung“ ersetzt
- verbleibende 403 werden als echter Endpoint-/Tokenzugriffsfehler gemeldet, nachdem HTTP/2 + TLS 1.3 bereits aktiv sind

### QA
- Worker-Container muss `httpx` + `h2` enthalten
- TLS-1.3-Unterstützung wird im Worker geprüft
- PHP-cURL muss HTTP/2- und TLS-1.3-Konstanten bereitstellen
- bestehende Charging-History- und Runtime-Smoke-Tests bleiben aktiv

## 🧭 V0.1.1.41 — Information Architecture Reset

### Navigation
- Hauptnavigation komplett neu sortiert
- feste Gruppen: Übersicht, Fahrzeug, Historie, Auswertung, Orte, System
- „Drive“ heißt in der Navigation jetzt verständlich „Fahrzeug“
- Fahrten, Laden und Reisen stehen gemeinsam unter Historie
- Statistik und Sleep/Drain stehen gemeinsam unter Auswertung
- Karte/Geo steht unter Orte
- LiveView bleibt bewusst beim Fahrzeug und öffnet separat
- Connect, Engine, Diagnose und Einstellungen verschwinden aus der Hauptnavigation

### Neues System-Center
- neue zentrale Seite `system.php`
- Tesla-Verbindung, Tokenstatus und Fahrzeugsynchronisation
- Datendienst, Heartbeat, Polling und Driving Stream
- LiveView-Zugang und vertrauenswürdige Geräte
- Supercharger-Kosten-Synchronisierung
- technische Diagnose
- Account und Darstellung
- interne Sprungnavigation statt Wechsel zwischen mehreren Technikseiten

### Dashboard
- technische Engine-/Sync-/Heartbeat-Daten aus dem Dashboard entfernt
- doppelter „Datenspeicher“-Block entfernt
- Dashboard konzentriert sich auf Fahrzeugstatus, aktive Vorgänge und Kernzahlen

### Seitensprache
- „TrakFog XYZ“-Kicker durch feste Bereichsnamen ersetzt
- Header zeigt den Hauptbereich statt denselben Seitentitel ein zweites Mal
- Systemstatus im Header führt nur noch zum zentralen System-Center

### Kompatibilität
- bestehende technische Einzel-URLs bleiben vorerst erhalten
- sie markieren jetzt den Bereich „System“, sind aber kein Teil der normalen Navigation mehr

## 💸 V0.1.1.40 — No-Pay-API Roadmap Cleanup

### Roadmap korrigiert
- bezahlte Tesla Fleet API aus der aktiven TrakFog-Roadmap entfernt
- Fleet Telemetry mit Pay-per-Use ist kein geplanter Pflichtbaustein mehr
- Remote Commands sind nicht Teil der aktiven Roadmap, solange dafür laufende Tesla-API-Gebühren nötig wären
- README, Einstellungen und GitHub-Pages-Landingpage auf denselben Kosten-Grundsatz gebracht

### Neuer Kosten-Grundsatz
- TrakFog soll im normalen Eigenbetrieb ohne laufende Tesla-API-Gebühren nutzbar bleiben
- kein Tesla-Billingkonto als Voraussetzung
- vorhandener kostenfreier Tesla-Zugang wird weiter robust gekapselt und bei Änderungen möglichst kompatibel gehalten

### Nächste Entwicklungsrichtung
- LiveView/NerdView
- POIs und Kartenebenen
- Community-Freigaben
- Benachrichtigungen aus lokal gespeicherten Daten
- tiefere Fahrten-, Lade- und Reiseauswertungen

## 🖥️ V0.1.1.39 — LiveView Layout Foundation & README Cleanup

### LiveView Seite 1
- Grid strukturell korrigiert: Fahrzeug, Akku, Standort, Session und Reise haben jetzt feste Grid-Areas
- der bisherige versteckte Fehler mit einer impliziten vierten Grid-Zeile ist entfernt
- Session und Reise liegen vollständig innerhalb des verfügbaren Viewports
- Responsive- und Landscape-Zeilen wurden auf das neue 4-Zeilen-Layout abgestimmt
- Session-KPIs bekommen eine klar definierte Mindest-/Innenhöhe statt am unteren Rand abzuschneiden

### Live Map
- feste Safe-Zone oben links für Leaflet-Zoomsteuerung
- TrakFog-Kartenkopf startet rechts neben den Zoomtasten
- Leaflet-Zoomcontrol erhält eine definierte, kompakte Position und kollidiert nicht mehr mit „Live Map / Tesla“

### NerdView Position
- Positionskarte als zusammenhängendes Layout neu aufgebaut
- Adresse und Straßeninformationen ruhiger skaliert
- neue Navigationszeile: Fahrtrichtung · Kompass · Höhe
- Kompass sitzt bewusst in der Mitte und kann keine Werte mehr überlagern
- Richtungs- und Höhenwerte sind eigenständige zentrierte Statuskarten
- responsive Größen für Desktop, Tesla-Browser und kleine Landscape-Höhen

### README
- README vollständig aufgeräumt und auf den aktuellen Projektstand reduziert
- Versions-Wand und doppelte Roadmap entfernt
- klare Bereiche für Features, Architektur, Installation, Tesla-Verbindung, Supercharger-Kosten, Zusatzdaten, Geo, Diagnose und Roadmap
- vollständige Versionshistorie verweist jetzt auf `CHANGELOG.md`
- Fleet API und aktuelle Owner-API-Phase klar voneinander getrennt

## 🛠️ V0.1.1.38 — Tesla Connection Recovery

### Verbindung wieder robust
- einzelne Tesla-API-403/408/412/429 gelten nicht mehr als Totalausfall der Tesla-Verbindung
- gespeicherter Access-/Refresh-Token, bekannte Fahrzeuge, lokale Historie und Live-Daten bleiben aktiv
- die Connect-Seite zeigt Teilzugriffsprobleme gelb statt die komplette Integration rot auf `error` zu setzen
- echte Auth-/Tokenfehler bleiben weiterhin rot und werden nicht verschluckt

### Automatische Reparatur von V0.1.1.37
- neue Migration `0.1.1.38.sql`
- von V0.1.1.37 fälschlich auf `error` gesetzte 403-Verbindungen werden beim Containerstart automatisch wieder auf `connected` gesetzt
- der alte pauschale 403-Fehler wird dabei bereinigt
- keine neue Tesla-Anmeldung und keine Fleet-ID erforderlich

### Endpoint-spezifische Fehler
- TrakFog unterscheidet jetzt Fahrzeugliste, Fahrzeug-Detaildaten und andere Tesla-API-Bereiche
- ein abgelehnter Detailbereich wird nicht mehr irreführend als komplett kaputte Tesla-Verbindung dargestellt
- Rate-Limits und schlafende Fahrzeuge bleiben retry-fähige Zustände

### Datendienst
- wenn Teslas Produkt-/Fahrzeuglisten-Endpunkt vorübergehend eingeschränkt ist, bleibt der Worker aktiv und meldet `degraded` statt hart auf `error` zu fallen
- bestehende Streaming-/Historienfunktionen werden dadurch nicht unnötig mitgerissen
- Zusatzdaten und Supercharger-Kosten bleiben weiterhin voneinander und von der Hauptverbindung isoliert

### QA
- neue statische Checks stellen sicher, dass 403-Teilzugriffe nicht mehr den Integrationsstatus zerstören
- Migration, Connect-Warnpfad und Worker-Degraded-Fallback werden geprüft
- Docker-QA und Release-ZIP bleiben unverändert Bestandteil des Releases

## 🧠🚗 V0.1.1.37 — Tesla Intelligence, Update-Radar & NerdView Polish

### Dashboard & Software-Updates
- neuer Tesla Update-Radar auf dem Dashboard
- Zielversion, Tesla-Status und Download-/Installationsfortschritt werden angezeigt, sobald sie im gespeicherten Fahrzeugdatensatz vorhanden sind
- dieselbe Update-Info erscheint auf Fahrzeugseite und NerdView
- dafür wird keine zusätzliche Fahrzeugabfrage ausgelöst

### Tesla Deep Data
- deutlich mehr Fahrzeug-, Lade-, Klima-, Navigations-, Sicherheits- und Serviceinformationen sichtbar
- Reifendruck pro Rad plus TPMS-Warnstatus
- Navi-Ziel, Reststrecke, Restzeit und Verkehrsverzögerung
- Ladeelektrik, Phasen, Fast-Charger-Typ, Klima-/Defrost-/Batterieheizungszustände
- Modellkennung, Trim, Felgen, Farbe, Ladeporttyp und Tesla-Softwarestand
- neuer Deep-Data-Explorer zeigt alle bereits empfangenen Tesla-Rohfelder automatisch

### Tesla Zusatzdaten
- neuer schonender Hintergrund-Sync für nahe Ladeorte, letzte Alerts, Release Notes und Service-Daten
- Standardintervall sechs Stunden
- Zusatzabfragen laufen nur bei ohnehin online erkanntem Fahrzeug und wecken den Tesla nicht eigens
- einzelne optionale Endpoint-Fehler beeinträchtigen die normale Telemetrie nicht
- Daten werden pro Fahrzeug gecacht und auf der Fahrzeugseite angezeigt

### LiveView / NerdView
- Hauptseite erhält kompaktere Session-/Reisebereiche und mehr Sicherheitsabstand gegen Abschneiden
- Positionskarte im NerdView vollständig neu zentriert
- Kompass verkleinert, Werte darunter angeordnet und Schriftgrößen reduziert
- kein Überlagern von Kompass, Fahrtrichtung und Höhe mehr
- Software-Update-Hinweis im NerdView ergänzt

### Roadmap
- sichtbarer Bereich „Jetzt eingebaut / Danach / Später nice-to-have“ in den Einstellungen
- nächster Architekturblock ist die offizielle Tesla Fleet API mit Fleet Telemetry
- Remote Commands bleiben bewusst hinter Fleet API + Virtual Key

### GitHub Pages
- neue statische TrakFog-Landingpage in `docs/`
- Dark-Mode-Design, Features, Self-hosted-Architektur und Roadmap
- keine zusätzliche Pages-Build-Action nötig; Quelle ist für „Deploy from a branch → main /docs“ vorbereitet

### QA
- bestehende sparsame Release-/Docker-QA bleibt erhalten
- zusätzliche statische Checks für Update-Radar, Deep Data, Tesla-Zusatzdaten, NerdView-Update und Pages-Quelle

## 🚗⚡ V0.1.1.36 — Centered Live Map & Tesla Supercharger Costs

### Live Map
- der eigene Tesla bleibt im LiveView dauerhaft im Kartenmittelpunkt
- manuelles Verschieben der Karte ist bewusst deaktiviert
- Pinch-, Mausrad-, Doppelklick- und +/- Zoom bleiben verfügbar und zoomen um den Kartenmittelpunkt
- TrakFog verändert den gewählten Zoom während der Fahrt nicht mehr
- der rote Fahrfaden wächst hinter dem Fahrzeug weiter
- kein automatisches Fit-to-Route mehr – auch nach sehr langen Fahrten
- gewählter Kartenzoom wird lokal im Browser gemerkt
- Live-Routen liefern bis zu 1.200 Punkte, damit lange Fahrten beim manuellen Herauszoomen weiterhin brauchbar gezeichnet werden

### Fahrzeugseite
- Schlafmodus zeigt keinen Akkuwert mehr in einer Geschwindigkeits-Gauge
- schlafender Tesla bekommt eine klare Ruheanzeige
- geparktes Fahrzeug bekommt eine eigene P-Anzeige
- echte Speed-Gauge erscheint nur während der Fahrt
- Laden erhält eine eigene grüne Instrumentdarstellung

### Fahrt & Reise
- keine aktive Reise erzeugt keinen riesigen leeren Bereich mehr
- neuer grafischer Empty-State mit Route/Fahrzeug
- Akku, Reichweite und aktueller Standort bleiben im Ruhebild sichtbar
- aktive Reisen wechseln automatisch zurück auf Reise-Akku, Fahrt/Laden-Balken, KPIs und Budget
- Jetzt- und Ladesession-Karten bleiben parallel nutzbar

### NerdView
- linke Spalte ist jetzt ein echtes Tesla-Fahrinstrument statt einer großen leeren Zahlenfläche
- Speed-Instrument reagiert auf Fahren, Parken, Schlafen und Laden
- Akku-&-Laden-Karte wurde zweispaltig neu aufgebaut
- Akku-Ring und Lade-/Range-Werte nutzen den Platz nebeneinander
- keine abgeschnittenen Ladeleistung-/Volt-/Ampere-Felder mehr bei typischen Tesla-Landscape-Höhen
- Kompass, Adresse, Straße, Straßenart, Klima, Fahrzeugstatus und Reifendruck bleiben Tesla-only

### Tesla Supercharger-Kosten
- neuer automatischer Charging-History-Sync in der permanenten TrakFog Engine
- Tesla-Historie wird pro VIN abgefragt, ohne das Fahrzeug zu wecken
- erster Lauf kann die verfügbare Historie nachladen; danach wird nur noch die neueste Seite regelmäßig geprüft
- Standardintervall 90 Minuten; in den Einstellungen zwischen 30 und 360 Minuten konfigurierbar
- manueller Sofort-Sync kann über die Einstellungen für den nächsten Engine-Tick ausgelöst werden
- Matching erfolgt zunächst in einem ±10-Minuten-Fenster und bei Bedarf ±30 Minuten
- bei mehreren Kandidaten fließt die geladene Energie in die Bewertung ein
- Tesla-Gebühren werden zu einem tatsächlich abgerechneten Gesamtbetrag addiert
- echte 0,00-EUR-Supercharger-Sessions bleiben von fehlenden Kosten unterscheidbar
- automatisch übernommene Kosten erhalten die Quelle `tesla_invoice`, Tesla-Session-ID, Standort und Rohhistorie
- effektiver Preis/kWh wird aus dem tatsächlichen Rechnungsbetrag berechnet
- manuell bestätigte Kosten bleiben geschützt und werden niemals überschrieben
- bereits Tesla-synchronisierte Sessions können aktualisiert werden, falls Tesla den Rechnungsbetrag nachträglich verändert
- Ladehistorie und Lade-Detailseite kennzeichnen Tesla-abgerechnete Kosten sichtbar

### Einstellungen & Diagnose
- neuer Bereich „Tesla Supercharger-Kosten“
- Automatik an/aus
- Intervall einstellbar
- letzter Lauf, geprüfte Sessions, übernommene Kosten und nicht zugeordnete Sessions sichtbar
- „Jetzt synchronisieren“ verfügbar
- Engine- und Systemdiagnose zeigen Charging-History-Status und Fehler

### Datenbank
- neue Migration `0.1.1.36.sql`
- Tesla Charging Session ID, Tesla-Standort, Sync-Zeitpunkt und History-Rohdaten je Ladesession
- Settings für Aktivierung, Intervall, Sofortlauf und Sync-Status

### QA
- Python-Worker wird kompiliert
- LiveView-JavaScript wird syntaktisch geprüft
- zentrierte Kartenlogik und gespeicherter Zoom werden statisch geprüft
- Docker Runtime-Test prüft Tesla-Kostenmatching inklusive Schutz manueller Kosten
- LiveView-Smoke prüft die neuen Reise-/NerdView-Elemente


## 📊 V0.1.1.35 — LiveView Visual Dashboard

Der LiveView übernimmt jetzt die visuelle Stärke der Fahrzeug-Detailseite statt nur Zahlen in großen Flächen zu verteilen.

### Seite 1 · Fahrzeug
- neue große Speed-Gauge statt reinem Zahlenfeld
- kreisförmige Akkuanzeige im Stil der Fahrzeug-Detailseite
- Reichweite und Leistung bleiben direkt am Akku-Gauge
- bestehende Session-Kacheln und Reiseleiste bleiben erhalten
- Fahrzeugzustand steuert Gauge/Inhalt weiterhin automatisch

### Seite 2 · Live Map
- neues schwebendes Fahrzeug-HUD über der Karte
- Akku-Ring, Tempo, Reichweite, aktuelle Fahrt und Standort direkt auf der Karte
- HUD blockiert die Kartenbedienung nicht
- Hell/Dunkel/Nacht sowie Community-Platzhalter bleiben unverändert verfügbar

### Seite 3 · Fahrt & Reise
- neuer Akku-Ring als visuelles Reiseelement
- Fahrzeit und Ladezeit werden als gemeinsamer Aktivitätsbalken dargestellt
- prozentuale Verteilung Fahrt/Laden
- vorhandene Reise-KPIs, Budget und Ladesession bleiben erhalten

### Seite 4 · Tesla NerdView
- Akkuanzeige als echtes Gauge
- neuer Kompass mit Fahrzeug-Heading
- lesbare Fahrtrichtung und Höhe bleiben parallel sichtbar
- technische Tesla-Werte bleiben Tesla-only

### Tesla HTTP 403
- manueller Detailabruf versucht zunächst weiterhin den vollständigen vehicle_data-Datensatz
- antwortet Tesla mit 403, erfolgt automatisch ein zweiter Abruf ohne geschützten location_data-Bereich
- falls auch dieser Request blockiert wird, versucht TrakFog Teslas Standard-vehicle_data-Datensatz
- bereits gespeicherte Akku-, Reichweiten-, Positions-, Heading- und Geschwindigkeitswerte werden durch fehlende Felder nicht mehr mit NULL überschrieben
- derselbe Fallback ist auch in der permanenten TrakFog Engine eingebaut
- bei erfolgreichem reduzierten Abruf erscheint nur ein verständlicher Hinweis statt eines harten Fehlers
- Ursache kann ein fehlender Tesla-Berechtigungsbereich/Scope sein; TrakFog fragt dadurch nicht häufiger ab und weckt kein Fahrzeug zusätzlich

### QA
- neue Visual-Dashboard-Elemente werden im authentifizierten LiveView-Smoke geprüft
- JavaScript-Syntaxprüfung bleibt aktiv
- statische Guards prüfen PHP- und Worker-Fallback für HTTP 403
- Docker Runtime-Smoke bleibt vollständig aktiv

- keine Datenbankmigration erforderlich


## 🧭 V0.1.1.34 — Geocoded Position

Die NerdView zeigt Position jetzt so, wie man sie im Auto wirklich lesen will.

### Position
- sichtbare LAT/LON-Felder entfernt
- Estimated Heading entfernt
- automatisch geocodierte Adresse statt Koordinaten
- Straßenname separat sichtbar
- Straßenart wird soweit möglich aus Nominatim/OSM abgeleitet
- Straßenreferenzen wie A/B/L/K-Nummern werden bevorzugt erkannt
- Fahrtrichtung wird aus dem Fahrzeug-Heading in Himmelsrichtung + Grad übersetzt, z. B. WSW · 254°
- Höhe bleibt als eigener gut lesbarer Wert

### Geocoding
- bereits aufgelöste Geo-Punkte in der Nähe können für die aktuelle Position wiederverwendet werden
- geparkte/schlafende Fahrzeugpositionen werden künftig automatisch in die bestehende Reverse-Geocode-Warteschlange aufgenommen
- aktive Geofence-Mittelpunkte werden ebenfalls geocodiert
- Nominatim-Extratag-Daten werden für künftige Straßenklassifizierung mit angefordert
- keine zusätzliche Tesla-Abfrage und kein Fahrzeug-Wakeup

### QA
- LiveView-Smoke prüft Adresse, Straßenart und Fahrtrichtung
- alte LAT/LON- und Estimated-Heading-Elemente dürfen nicht mehr im LiveView-Markup vorkommen
- Live-Daten-JSON enthält Adress-/Straßenfelder und nur noch ein Heading

- keine Datenbankmigration erforderlich


## 🚗 V0.1.1.33 — Tesla-only NerdView

Die NerdView wird konsequent auf das reduziert, was im Auto wirklich interessiert: den Tesla selbst.

### Aufgeräumt
- Pipeline-/Worker-Anzeigen entfernt
- Frames, Snapshots und Heartbeat entfernt
- interne Vehicle-/Trip-/Charge-/Journey-/Route-IDs entfernt
- keine TrakFog-Debugdaten mehr auf der NerdView
- technische Live-Daten-API liefert für diese Seite nur noch Tesla-bezogene Werte

### Neue Tesla-Aufteilung
- Fahren: Geschwindigkeit, Leistung und Gang
- Akku & Laden: SoC, nutzbarer SoC, Rated/Estimated Range, Ladeleistung, Spannung/Strom, geladene Energie und Restzeit
- Fahrzeug: Kilometerstand, Verriegelung, Sentry, Ladeport, Türen/Hauben, Softwarestand und VIN-Endung
- Reifendruck: VL/VR/HL/HR, sofern Tesla die Werte im letzten Fahrzeugdatensatz liefert
- Klima: Innen-/Außentemperatur, Klima an/aus, Fahrer-/Beifahrer-Solltemperatur und Lüfterstufe
- Position: Ort, GPS, Höhe, Heading und Estimated Heading

### Lesbarkeit
- deutlich größere Werte und Labels
- fünf große Tesla-Blöcke statt vieler kleiner Debugkarten
- vorhandene Fläche wird auf Tesla/Desktop aggressiver genutzt
- kleinere Displays reduzieren nur Sekundärdetails, nicht die Kernwerte
- Datenstand wird als eine einzige kompakte Altersangabe gezeigt

### Datenschutz / Polling
- keine zusätzliche Tesla-Abfrage
- keine neue Datenbankmigration
- LiveView bleibt reine Leseansicht


## 🤓 V0.1.1.32 — NerdView & Night Map

Der LiveView bekommt die Seite, die normale Menschen freiwillig überspringen. 😄

### NerdView
- vierte horizontale LiveView-Seite „NerdView“
- bewusst dichte technische Darstellung ohne Backend-Bedienelemente
- aktuelle Geschwindigkeit, Leistung, SoC und Gang
- Kilometerstand
- Rated Range und Estimated Range
- Heading und Estimated Heading
- GPS mit sieben Nachkommastellen
- Höhe
- Innen-/Außentemperatur, sofern im letzten Tesla-Snapshot vorhanden
- Verriegelung, Sentry und Ladeport-Status, sofern vorhanden
- Datenquelle: Driving Stream, REST Snapshot oder Vehicle Cache
- Driving-Stream-Status und Fresh/Stale-Anzeige
- Alter des letzten Stream-Frames
- Alter des letzten REST-Snapshots
- letzter Fahrzeugkontakt
- Stream-Update-Zähler
- TrakFog-Engine-State und Heartbeat
- interne Vehicle-/Trip-/Charge-/Journey-/Route-IDs sowie Anzahl geladener Routenpunkte
- reine Leseansicht; keine zusätzlichen Tesla-Abfragen

### Dark / Night Map
- neue Kartenstil-Taste direkt auf LiveView-Seite 2
- drei Modi: Hell → Dunkel → Nacht
- Dunkel ist der neue LiveView-Standard
- gewählter Modus wird pro Browser lokal gespeichert
- nur die OSM-Tile-Ebene wird optisch gefiltert
- Route, Tesla-Marker und Overlays behalten ihre normalen Farben
- keine zusätzliche Karten-API und kein neuer Tile-Anbieter erforderlich

### Responsive
- NerdView skaliert von Tesla/Desktop bis Tablet/Smartphone
- auf kleineren Displays werden weniger wichtige Nerd-Blöcke priorisiert ausgeblendet statt die Hauptdaten unlesbar klein zu machen
- Swipe-Pager wächst von drei auf vier Seiten
- Tastatur-/Maus-/Touch-Navigation bleibt erhalten

### QA
- JavaScript-Syntaxprüfung umfasst NerdView und Kartenmodus
- Runtime-Smoke prüft die vierte Seite und den Kartenstil-Schalter
- Live-Daten-JSON wird auf den neuen technischen Telemetrieblock geprüft
- keine Datenbankmigration erforderlich


## 📺 V0.1.1.31 — LiveView Foundation

TrakFog bekommt einen eigenen Vollbild-LiveView für den Tesla-Browser und alle anderen Displays.

### Eigener LiveView-Zugang
- LiveView ist vollständig vom normalen TrakFog-Backend-Login getrennt
- drei Zugangsmodi: deaktiviert, sechsstellige PIN oder bewusst offen
- PIN wird ausschließlich als sicherer Passwort-Hash gespeichert
- Tesla/Browser kann nach erfolgreicher PIN-Eingabe als vertrauenswürdiges Gerät gespeichert werden
- gemerkte Geräte erhalten einen zufälligen 256-Bit-Token; die PIN selbst wird niemals im Browser gespeichert
- Gültigkeitsdauer pro Gerät konfigurierbar
- einzelne Geräte oder alle Geräte können im Backend sofort abgemeldet werden
- neue PIN meldet alle bisher gemerkten Geräte ab
- Rate-Limit gegen PIN-Raten: nach wiederholten Fehlversuchen temporäre Sperre, ohne Speicherung der Klartext-IP

### Tesla-tauglicher PIN-Screen
- großer eigener Zahlenblock statt Pflicht zur Bildschirmtastatur
- sechs visuelle PIN-Felder
- Tastaturbedienung funktioniert zusätzlich
- „Dieses Gerät merken“ standardmäßig aktiv
- optimiert für Touch und Querformat

### Adaptiver Vollbild-LiveView
- keine Sidebar, kein normaler Backend-Header und kein Footer
- nutzt die gesamte verfügbare Browserfläche
- automatische Layoutprofile Compact / Standard / Wide / XL
- Zielgeräte: Tesla, Smartphone, Tablet, Desktop und TV
- Display-Diagnose zeigt echten Viewport, Screen-Größe, Device Pixel Ratio und aktives Layoutprofil
- LiveView merkt die zuletzt geöffnete Seite und das ausgewählte Fahrzeug

### Swipe-Pager
- Seite 1: Fahrzeug-Livewerte
- Seite 2: große Live-Karte
- Seite 3: Fahrt & aktive Reise
- breiter eigener Swipe-Bereich über die komplette Unterkante
- Pointer Events für Touch, Maus und Trackpad
- Pfeiltasten sowie Links/Rechts-Buttons zusätzlich
- Kartenbedienung bleibt von der Swipe-Zone getrennt

### Fahrzeugseite
- Geschwindigkeit im Fahrbetrieb
- Ladeleistung beim Laden
- Akku, Reichweite und Leistung
- Standort und Datenalter
- aktuelle Fahrt oder aktuelle Ladesession
- aktive Reise direkt sichtbar
- Darstellung passt sich automatisch an Fahrzeugzustand an

### Live Map
- eigener Tesla als Live-Marker
- aktive bzw. letzte Route aus gespeicherten Stream-Daten
- Follow/Zentrieren-Modus
- Tesla und Route einzeln ausblendbar
- vorbereitete UI-Schalter für:
  - Standortfreigabe
  - andere freigegebene Teslas
  - geprüfte Community-Orte
- diese Community-Funktionen sind in V0.1.1.31 bewusst noch nicht aktiv; Standort bleibt privat

### Reise-Seite
- aktive Reise, Ziel, Dauer und Strecke
- Anzahl Fahrten und Ladestopps
- Ladekosten und optionales Budget
- aktuelle Fahrt, Leistung, Akku und Ladesession
- liest dieselben lokalen TrakFog-Daten wie Backend und Worker

### Datenschutz / Tesla
- LiveView-Datenendpoint ist nur mit gültigem LiveView-Zugang erreichbar
- LiveView führt keine Tesla-API-Abfrage aus
- Offenlassen im Tesla-Browser verursacht keinen zusätzlichen Wake-up
- Karten-/Live-Daten kommen ausschließlich aus bereits gespeicherten TrakFog-Daten

### QA
- Docker QA prüft JavaScript-Syntax
- richtet im echten Container selbst eine LiveView-PIN ein
- meldet sich ohne normalen Account am LiveView an
- prüft Fahrzeug-, Reise- und Kartenmarkup
- prüft Live-Daten-JSON
- entfernt anschließend die normale PHP-Session und bestätigt, dass der Trusted-Device-Token alleine funktioniert

- DB-Migration `0.1.1.31.sql`
- nächster Block: Community-Layer und freigegebene Teslas/Orte


## 🧳 V0.1.1.30 — Reisen & Touren

Aus einzelnen Fahrten und Ladevorgängen wird eine komplette Reisehistorie.

### Reisen
- neuer Hauptbereich „Reisen“
- Reisearten: Reise, Urlaub, Dienstreise, Roadtrip und eigene Tour
- Status Geplant → Läuft → Beendet
- optionales Ziel / Bezeichnung
- geplanter Start und geplantes Ende
- optionales Ladebudget und Notizen
- Reisen können sofort gestartet oder zunächst geplant werden
- historische Reisen können über vergangene Planzeiten nachträglich aufgebaut werden

### Automatische Zuordnung
- aktive Reisen können neue Fahrten und Ladevorgänge automatisch übernehmen
- permanente TrakFog Engine synchronisiert aktive Reisen fortlaufend
- Auto-Zuordnung verwendet ausschließlich bereits gespeicherte Fahrten/Ladungen und erzeugt keine Tesla-Abfrage
- manuell abgewählte Einträge bleiben auch bei aktiver Auto-Zuordnung ausgeschlossen
- ein manuell ausgewählter Eintrag kann aus einer anderen Reise in die aktuelle Reise verschoben werden
- beim Beenden erfolgt eine abschließende Synchronisation

### Reise-Detailseite
- kombinierte Gesamtkarte aller zugeordneten Fahrten
- Ladestopps als eigene Kartenmarker
- chronologische Timeline aus Fahrten und Ladevorgängen
- Gesamtstrecke
- Anzahl Fahrten und Ladestopps
- geladene Energie
- bestätigte Ladekosten
- Kosten pro 100 km
- Fahrzeit und Ladezeit
- längste Fahrt und längster Ladestopp
- durchschnittlicher Fahrverbrauch
- Budget-Fortschritt
- manuelle Fahrten-/Ladungszuordnung im Reisezeitraum

### Verknüpfungen
- Fahrtenliste zeigt zugehörige Reise
- Ladehistorie zeigt zugehörige Reise
- Fahrt- und Lade-Detailseite verlinken direkt auf die Reise
- Dashboard zeigt aktive Reisen am Fahrzeug und im Systemstatus

### Datenbank / QA
- neue Tabellen `journeys`, `journey_trips`, `journey_charges`
- DB-Migration `0.1.1.30.sql`
- Docker QA seedet eine komplette Testreise und öffnet Reisenliste + Detailseite im authentifizierten Runtime-Smoke-Test

### Roadmap
- Reisen & Touren abgeschlossen
- nächster großer Block: LiveView im Stil von VoltCore


## 🔥 V0.1.1.29 — Heatmaps & Map Analytics

Die Karte zeigt jetzt nicht nur einzelne Fahrten, sondern auch Muster über längere Zeiträume.

### Fahrdichte
- neue optionale Kartenebene „Fahrdichte“
- verarbeitet bereits gespeicherte Tesla Driving-Stream-Punkte
- nur echte Fahrdaten (D/R bzw. Bewegung) fließen ein
- räumliche Gruppierung reduziert große Datenmengen serverseitig
- logarithmische Gewichtung verhindert, dass ein einzelner Hotspot die gesamte Darstellung überstrahlt
- Zeitraum- und Fahrzeugfilter der Karte gelten auch für die Heatmap
- maximal 2.500 verdichtete Fahrzellen pro Kartenaufruf

### Lade-Hotspots
- neue optionale Kartenebene „Lade-Hotspots“
- gruppiert wiederkehrende Ladevorgänge nach Standort
- Häufigkeit der Sessions bestimmt die Intensität
- Zeitraum- und Fahrzeugfilter greifen identisch zur normalen Karte
- bestehende Lade-Marker bleiben unabhängig davon schaltbar

### UI / Performance
- beide Heatmaps sind standardmäßig aus und können separat zugeschaltet werden
- eigene Heatmap-Legende mit niedrig/hoch-Darstellung
- Leaflet.heat wird nur zusammen mit den Karten-Assets geladen
- Fallback auf normale Leaflet-Kreis-Marker, falls das Heatmap-Plugin nicht verfügbar ist
- keine zusätzlichen Tesla-Abfragen und kein Wake-up
- keine Datenbankmigration erforderlich

### Roadmap
- Heatmaps & erweiterte Kartenanalyse abgeschlossen
- nächster großer Historienblock: Reisen & Touren mit Fahrten-/Ladezuordnung


## ✏️ V0.1.1.28 — Draw Your Geofence

Geobereiche können jetzt exakt an die Realität angepasst werden: kleiner Radius für einzelne Lade-/Parkbereiche oder frei gezeichnete Flächen für Parkplätze, Gebäude und Gelände.

### Kreis / Radius
- Mindest-Radius von 25 m auf 5 m reduziert
- neuer Standard-Radius 75 m
- eigener Karten-Editor für Kreise
- Mittelpunkt direkt auf der Karte verschiebbar
- Randgriff verändert den Radius per Drag & Drop
- aktuelle Meterzahl wird live eingeblendet
- numerische Meter-Eingabe bleibt für exakte Werte erhalten

### Freie Fläche / Polygon
- Geofence kann zwischen Radius und freier Fläche umgeschaltet werden
- beliebig geformte Fläche durch Klicks auf die Karte zeichnen
- mindestens drei Eckpunkte
- vorhandene Eckpunkte später wieder verschiebbar
- Rechtsklick auf einen Eckpunkt entfernt ihn, solange mindestens drei Punkte übrig bleiben
- Polygon wird als JSON gespeichert und beim Bearbeiten erneut geladen
- TrakFog prüft serverseitig per Point-in-Polygon, ob Fahrzeug/Fahrt/Ladung innerhalb der gezeichneten Fläche liegt
- damit lassen sich nahe Bereiche wie Gebäude, Mitarbeiterparkplatz und Ladeplätze getrennt benennen

### Bedienung
- normaler Rechtsklick auf die Karte startet weiterhin einen neuen Geobereich
- separater Karten-Editor mit Übernehmen/Abbrechen
- bestehende Kreis- und Polygonbereiche werden unterschiedlich auf der Karte dargestellt
- keine zusätzliche Tesla-Abfrage und kein Wake-up

- DB-Migration `0.1.1.28.sql`
- nächster Karten-Ausbau bleibt: Heatmaps


## 🎯 V0.1.1.27 — Exact Right-Click Coordinates

- Rechtsklick-Koordinate wird nicht mehr aus dem Leaflet-`contextmenu`-Event übernommen
- stattdessen wird die echte Browser-Mausposition relativ zum Karten-Container ermittelt
- daraus berechnet Leaflet anschließend exakt die geklickte Latitude/Longitude
- Browser-Kontextmenü wird innerhalb der Karte unterdrückt
- jeder Rechtsklick an einer anderen Kartenstelle liefert dadurch eine neue Koordinate
- Fokus auf das Namensfeld erfolgt erst nach dem Öffnen des Modals
- keine Datenbankänderung erforderlich


## 🖱️ V0.1.1.26 — Map Right-Click UX

- normaler Linksklick auf die Karte bleibt reine Kartenbedienung
- Rechtsklick auf eine Kartenposition öffnet direkt „Geobereich anlegen“
- Breitengrad/Längengrad werden aus der Rechtsklick-Position übernommen
- der bisherige Linksklick-Handler zum Setzen von Koordinaten wurde entfernt
- „＋ Geobereich“ bleibt als Alternative erhalten, besonders für Touchgeräte
- sichtbarer Kartenhinweis erklärt die neue Rechtsklick-Funktion
- keine Datenbankänderung erforderlich


## 🗺️ V0.1.1.25 — Map & Geo Foundation

Die Karte wird vom Positions-Platzhalter zur echten Tesla-Historienkarte.

### Karte
- Fahrtrouten aus bereits gespeicherten Tesla Driving-Stream-Punkten
- automatische serverseitige Routenausdünnung für performante Karten
- Start- und Zielmarker je Fahrt
- Ladeorte als eigene Marker
- letzte bekannte Fahrzeugpositionen
- Filter nach Fahrzeug und 24 h / 7 / 30 / 90 Tage / Gesamt
- Kartenebenen für Fahrten, Ladeorte, Fahrzeuge und Geobereiche einzeln schaltbar
- direkter Sprung von Karte zu Fahrt oder Ladevorgang
- direkter Sprung aus Fahrten/Ladungen auf die passende Kartenposition
- neue Fahrt-Detailseite mit kompletter Route und Start-/Zielübersicht
- responsive Map-UI im aktuellen TrakFog-Design

### Geo
- neue lokale Geo-Cache-Tabelle
- Reverse Geocoding läuft im permanenten Worker und nicht im Browser
- Fahrtstart, abgeschlossene Fahrtziele und Ladeorte werden automatisch für die Adressauflösung vorgemerkt
- standardmäßig OpenStreetMap Nominatim, über Environment konfigurierbar oder komplett deaktivierbar
- pro Worker-Tick maximal ein neuer Geo-Punkt, damit die Auflösung ruhig im Hintergrund erfolgt und aktive Fahrten keine Geo-Warteschlange fluten
- Adressen werden lokal gecacht und nicht bei jedem Seitenaufruf neu abgefragt
- keinerlei zusätzliche Tesla-Abfragen oder Wake-ups durch Geo/Map

### Geobereiche
- eigene benannte Geofences direkt auf der Karte verwalten
- Typen Zuhause, Arbeit, Ladeort, Reise und Ort
- frei definierbarer Radius
- Karte anklicken übernimmt Koordinaten direkt in das Modal
- eigener Geobereich hat bei der Anzeige Vorrang vor der normalen Adresse
- manuell gepflegter Ladeort bleibt höchste Priorität

### QA / Diagnose
- Docker QA erzeugt nun echte Route, Ladeort und Geo-Testdaten
- Fahrt-Detailseite und Map werden im authentifizierten Runtime-Smoke-Test geöffnet
- Geo-Cache und Reverse-Geocoder erscheinen in der Systemdiagnose

- DB-Migration `0.1.1.25.sql`
- nächster Karten-Ausbau: Heatmaps
- Reisen & Touren bleiben als nächster großer Historienblock auf der Roadmap


## 🩺 V0.1.1.24 — Statistics Recovery + Unified Error UI

Der gemeldete 500er in der Statistik wird abgefangen und der Fehlerweg optisch vollständig an das aktuelle TrakFog-Design angepasst.

### Statistik
- Statistik-Abfragen in unabhängige, fehlertolerante Blöcke zerlegt
- konkreter 500er behoben: PHP 8.4 interpretierte die Datums-Keys beim entpackten `max()` als benannte Parameter; die Werte werden jetzt vor dem Entpacken sauber reindiziert
- Kostenaggregation ist vom restlichen Statistik-Query entkoppelt
- historische/ungewöhnliche Snapshot-Daten können nicht mehr die komplette Seite zu Fall bringen
- JSON-Auswertung prüft Daten vor dem Zugriff
- REST-, Stream-, Fahrten- und Ladeauswertung werden separat geloggt
- falls ein einzelner Statistikblock scheitert, bleibt die Seite benutzbar und zeigt einen gezielten Hinweis mit Fehler-ID statt eines kompletten HTTP 500
- bestätigte kostenlose 0,00-€-Ladungen bleiben in den Kostenstatistiken korrekt erhalten

### Fehlerseiten
- 400 / 401 / 403 / 404 / 405 / 409 / 422 / 429 / 500 / 503 verwenden jetzt eine gemeinsame Fehleroberfläche
- altes Mint-/Fog-Design vollständig entfernt
- Tesla-rotes TrakFog-Branding mit TF-Marke, aktueller Light-/Dark-Theme-Logik und kompakter VoltCore-artiger Formsprache
- Fehler-ID bleibt klar sichtbar
- 5xx-Seiten verlinken zusätzlich direkt zur Systemdiagnose
- Apache-ErrorDocument-Routen auf alle unterstützten Fehlercodes erweitert

### QA
- Docker QA meldet sich jetzt mit einem echten Test-Account an
- QA erzeugt Test-Telemetrie für Fahrzeug, Snapshot, Stream, Fahrt und Ladevorgang
- geschützte Hauptseiten werden per HTTP-Smoke-Test wirklich geöffnet
- Statistik und Sleep werden damit erstmals als echte Runtime-Seiten geprüft
- eigene 404-Prüfung stellt sicher, dass die neue Fehleroberfläche ausgeliefert wird
- Worker liest seine Versionsnummer jetzt direkt aus der VERSION-Datei statt aus einem veraltbaren Hardcode
- Docker-Compose-Metadaten auf V0.1.1.24 angehoben
- Docker-Bootstrap berücksichtigt MariaDBs implizite Commits bei DDL-Migrationen und produziert dadurch keine irreführenden „There is no active transaction“-Fehler mehr
- keine neue Datenbankmigration erforderlich
- nächster Funktionsblock bleibt: Geo, Adressen und Geofences


## 👻 V0.1.1.23 — Real Costs + Sleep & Phantom Drain

Kosten werden auf den tatsächlich bezahlten Betrag umgestellt und TrakFog bekommt eine eigene schlaf-freundliche Energieanalyse.

### Kosten
- Eingabe jetzt als tatsächlich bezahlter Gesamtpreis je Ladevorgang
- neue Sessions zeigen 0,00 € als sichtbare Vorgabe, bleiben intern aber „noch offen“
- erst bewusst gespeicherte 0,00 € gelten als echte kostenlose Ladung
- unbestätigte Kosten fließen nicht in Durchschnittswerte ein
- effektiver Preis pro kWh wird automatisch aus Gesamtpreis / geladener Energie berechnet
- bestätigte 0,00-€-Sessions werden korrekt als 0,000 €/kWh ausgewertet
- Kosten können wieder auf „offen“ gesetzt werden

### Sleep & Phantom Drain
- neue Seite „Sleep“
- Tesla-Zustandswechsel werden in eigener Historie gespeichert
- Schlafsession startet bei asleep/offline und bleibt über Engine-Neustarts erhalten
- Abschluss erst mit einem frischen echten Online-Fahrzeugdatensatz
- Start-/End-SoC, Range, Kilometerstand und Dauer werden pro Schlafphase gespeichert
- Phantom Drain in Prozent, Reichweitenverlust und gewichtete Prozent-pro-Tag-Rate
- Sessions unter 15 Minuten werden aus der Phantom-Drain-Berechnung ausgeschlossen
- Ladezeiträume, erkennbare Bewegung und fehlende SoC-Daten werden automatisch als ungeeignet markiert
- 7 Tage / 30 Tage / 1 Jahr / Gesamt
- aktuelle Ruhephase, Wake-up-Zähler, längste Schlafphase und Zustandswechsel
- keinerlei zusätzliche Tesla-Abfrage durch die Sleep-Seite

### Roadmap ergänzt
- Geoadressen / Reverse Geocoding / benannte Geofences
- Reisen & Touren mit automatischer und manueller Zuordnung von Fahrten und Ladevorgängen

- DB-Migration `0.1.1.23.sql`
- nächster Block: Geo, Adressen und Geofences


## 💶 V0.1.1.22 — Direct Charge Pricing

Die Kostenpflege wird bewusst vereinfacht: kein Tarif-Setup mehr, sondern der Preis direkt am einzelnen Ladevorgang.

- Ladetarif-Verwaltung aus den Einstellungen entfernt
- keine automatische Tarifzuordnung mehr beim Start einer Ladesession
- pro Ladevorgang direktes Feld „Preis pro kWh“
- Komma- und Punkt-Eingabe werden akzeptiert
- Kosten werden sofort aus geladener Energie × Preis/kWh berechnet
- bei laufenden Ladesessions wachsen die Kosten automatisch mit der nachgeladenen Energie
- Preis kann jederzeit geändert oder durch Leeren des Feldes entfernt werden
- Ladeort/Bezeichnung bleibt direkt an der Session editierbar
- Ladehistorie und Statistik verwenden weiterhin die daraus berechneten Kosten
- vorhandene DB-Struktur aus V0.1.1.21 bleibt aus Upgrade-Kompatibilität bestehen, wird im normalen Workflow aber nicht mehr benötigt
- keine neue Datenbankmigration erforderlich
- nächster Block: Phantom Drain / Sleep-Analyse


## 💶 V0.1.1.21 — Charging Tariffs & Costs

TrakFog kann Ladepreise und echte Ladekosten jetzt selbst sauber verwalten, ohne von einer externen Preisquelle abhängig zu sein.

- eigene Ladetarife unter Einstellungen mit Name, Anbieter/Ort, Preis pro kWh, Währung und Notiz
- ein Tarif kann als Standard markiert werden
- der Standardtarif wird beim Start einer neuen Ladesession als Preis-Snapshot übernommen
- spätere Tarifänderungen verändern historische Sessions nicht
- laufende tarifbasierte Sessions aktualisieren ihre Kosten automatisch mit der geladenen Energie
- pro Ladesession kann ein anderer Tarif ausgewählt werden
- tatsächliche Rechnungs-/Gesamtkosten können manuell festgeschrieben werden und überschreiben die Tarifberechnung
- Ladeort/Bezeichnung kann direkt an der Session gepflegt werden
- Kostenquelle unterscheidet Tarif-Snapshot und manuelle/tatsächliche Rechnung
- Ladehistorie zeigt Kosten, effektiven Preis pro kWh und Tarif
- Statistik ergänzt Ladekosten, durchschnittlichen Ladepreis und Kosten pro 100 km
- Tages-, Monats- und Jahresauswertungen zeigen vorhandene Kostenwerte
- DB-Migration `0.1.1.21.sql` wird beim Docker-Start automatisch angewendet
- keine automatische Supercharger-Preisquelle erforderlich
- nächster Block: Phantom Drain / Sleep-Analyse


## 📊 V0.1.1.20 — Period Analytics

TrakFog fasst Fahr- und Ladehistorie jetzt zusätzlich in verständlichen Tages-, Monats- und Jahresansichten zusammen.

- kompakte Kennzahlen für Heute, aktuellen Monat und aktuelles Jahr
- Tagesvergleich der letzten 14 Tage
- Monatsvergleich der letzten 12 Monate
- Jahresvergleich der letzten 5 Jahre
- pro Zeitraum: Fahrten, Strecke, Fahrenergie, Verbrauch, Ladevorgänge und geladene Energie
- vorhandene Ladekosten werden bereits mit aggregiert und sind damit für den nächsten Kostenblock vorbereitet
- lokale TrakFog-Zeitzone wird beim Zuordnen von Fahrten und Ladungen berücksichtigt
- responsive Vergleichsbalken für Strecke und geladene Energie
- Auswertung vollständig aus vorhandener Historie, ohne zusätzliche Tesla-Abfragen
- keine Datenbankänderung erforderlich
- nächster Block: Ladepreise und Kosten


## ⚡ V0.1.1.19 — Charging Curves

Ladevorgänge sind jetzt nicht mehr nur Historienzeilen, sondern echte Sessions mit eigener Detailansicht.

- neue Detailseite pro Ladesession
- Ladeleistung als zeitliche Ladekurve
- SoC-Verlauf über die komplette Session
- kumulierte geladene Energie als Verlauf
- Spannung und Ladestrom aus den gespeicherten Tesla Charge-State-Daten
- adaptive 30-Sekunden-, 1-Minuten- oder 5-Minuten-Bündelung je nach Sessiondauer
- aktive Ladesession aktualisiert die Detailseite automatisch
- Ladehistorie verlinkt direkt auf „Kurve & Details“
- Ladekurven werden ausschließlich aus vorhandenen Snapshots rekonstruiert und wecken den Tesla nicht
- Chart-Renderer für mehrere Live-Chartseiten verallgemeinert
- keine Datenbankänderung erforderlich
- nächster Block: Tages-/Monats-/Jahresstatistiken


## 📈 V0.1.1.18 — Analytics Charts

Die Statistik wird vom Platzhalter zur echten Tesla-Zeitreihenansicht.

- Fahrzeugfilter für mehrere Teslas vorbereitet
- Zeitraumfilter für 24 Stunden, 7 Tage und 30 Tage
- Akkuverlauf aus REST-Snapshots
- Rated-Range-Verlauf aus REST-Snapshots
- Innen-/Außentemperatur aus den gespeicherten Tesla-Klimadaten
- Geschwindigkeitsverlauf aus dem Tesla Driving Stream
- Leistungsdiagramm inklusive Rekuperationsbereich aus dem Driving Stream
- automatische serverseitige Zeit-Bündelung für performante Charts
- eigener kleiner SVG-Chart-Renderer ohne externe Chart-Bibliothek/CDN
- responsive Darstellung für Desktop und Smartphone
- Statistik aktualisiert sich weiter live aus der Datenbank, ohne Tesla zusätzlich zu wecken
- keine Datenbankänderung erforderlich
- nächster Block: echte Ladekurven pro Ladesession


## 🔴 V0.1.1.17 — Live UI ohne F5

Die Tesla-Oberfläche aktualisiert sich jetzt automatisch aus den bereits von der TrakFog Engine gespeicherten Daten.

- Dashboard aktualisiert Fahrzeugzustand, Akku, Reichweite, aktive Fahrt/Ladung und Datenspeicher ohne F5
- Drive und Fahrzeugdetail ziehen neue Werte automatisch nach
- Fahrten und Ladevorgänge aktualisieren laufende Sessions inklusive Strecke, Dauer, Energie und SoC
- TrakFog Engine aktualisiert Heartbeat, Poll-Status, Streamwerte und aktive Erkennungen live
- globale Engine-Anzeigen in Desktop- und Mobile-Header werden mit aktualisiert
- Live-Refresh pausiert bei ausgeblendeten Browser-Tabs und verhindert parallele Requests
- reine DB-/HTML-Aktualisierung: der Browser löst keine zusätzlichen Tesla-API-Abfragen aus und weckt kein Fahrzeug
- keine Datenbankänderung erforderlich
- nächster Block: Akku-, Range-, Temperatur-, Speed- und Power-Charts


## ⚡ V0.1.1.16 — Automatic Charging

TrakFog erkennt Ladevorgänge jetzt automatisch aus den Tesla-REST-Daten und führt aktive Sessions laufend fort.

- neue Ladesession startet bei `Charging` / `Starting` oder echter Ladeleistung
- Session bleibt über Engine-/Container-Neustarts hinweg offen
- Abschluss bei `Complete`, `Disconnected` oder sicherer längerer Inaktivität
- Energie, Start-/End-SoC, maximale Ladeleistung, Standort, Zustand und Poll-Anzahl werden gespeichert
- kurze Ladepausen bleiben Teil derselben Session
- Ladehistorie komplett auf Live-/Historienbetrieb umgestellt
- aktive Ladesession erscheint auf Dashboard, Fahrzeugdetail und in der TrakFog Engine
- DB-Migration `0.1.1.16.sql` wird beim Docker-Start automatisch angewendet
- nächster Block: Live-UI ohne manuelles Neuladen

## 🛣️ V0.1.1.15 — Automatic Trips

Der Tesla Driving Stream wird jetzt erstmals wirklich in Fahrzeug-Historie übersetzt.

- automatische Fahrterkennung direkt aus Tesla-Stream-Daten
- neue Fahrt startet bei D/R oder echter Bewegung
- laufende Fahrt bleibt als offener Datensatz bestehen und überlebt Engine-/Container-Neustarts
- Fahrtende wird bei Parkstellung bzw. sicherer Inaktivität automatisch erkannt
- Strecke wird aus dem Tesla-Kilometerstand fortgeschrieben
- Maximaltempo, SoC Start/Ende, Range Start/Ende und Stream-Paketanzahl werden gespeichert
- Verbrauchsschätzung aus integrierten Tesla-Leistungswerten inklusive Wh/km
- Fahrten-Seite komplett auf Live-/Historienbetrieb umgestellt
- aktive Fahrt ist im Dashboard, Fahrzeugdetail und in der TrakFog Engine sichtbar
- Fahrzeugdetail verlinkt wieder korrekt auf die Tesla-Karte statt auf das entfernte alte Explore-Modul
- Kartenaufruf kann ein Fahrzeug gezielt fokussieren
- alter Hinweis „Auto-Polling aus“ durch den tatsächlichen Engine-Status ersetzt
- HTTPS-Diagnose erkennt jetzt Nginx Proxy Manager / X-Forwarded-Proto korrekt
- DB-Migration `0.1.1.15.sql` wird beim Docker-Start automatisch angewendet

## 🚘 V0.1.1.14 — VoltCore UI & TrakFog Engine

TrakFog bekommt eine deutlich ruhigere, technischere Tesla-Oberfläche nach dem bewährten VoltCore-Prinzip.

- horizontale Top-Navigation durch feste VoltCore-artige Sidebar ersetzt
- kompakter App-Header mit Engine-Status, Account und Logout
- mobil als Drawer-Navigation mit eigener Topbar
- Light/Dark-Design auf gemeinsame Oberflächen-, Linien- und Formularsprache umgestellt
- Tesla-roter Akzent statt des bisherigen Mint/Teal-Looks
- öffentliche Marketing-Startseite entfernt: Root führt direkt zum Login bzw. nach Anmeldung zum Dashboard
- Login komplett im neuen Stil aufgebaut, inklusive Passwortanzeige und Theme-Umschalter
- user-facing „Docker Worker“ vollständig zu „TrakFog Engine“ umbenannt
- Tesla Driving Stream trennt jetzt Verbindung, Datenfrische und empfangene Datenpakete verständlich
- veraltete `update.php`-Links aus Einstellungen und Diagnose entfernt
- Diagnosepfade an den `public/`-Webroot angepasst
- keine Datenbankänderung erforderlich

## 📡 V0.1.1.13 — Stream Reconnect & Live-Diagnose

Der erste echte Fahrzeugtest hat gezeigt: Socket verbunden, aber noch keine `data:update`-Frames. Deshalb wird die Streaming-Diagnose jetzt klarer und der Reconnect TeslaMate-näher.

- nach 30 Sekunden ohne Tesla-Frame wird die WebSocket-Verbindung vollständig neu aufgebaut
- neuer Zustand `waiting_data`: Socket verbunden und Subscription gesendet, aber noch kein echtes Stream-Event
- Worker-Seite zeigt jetzt letzte Stream-Werte für Tempo, SoC, Leistung und GPS
- Stream-Status wird in der Oberfläche verständlicher beschriftet
- Fahrzeugdetail bevorzugt frische Stream-Werte für Gang, Tempo und Position
- das bisherige künstliche Gang-Fallback `P` wurde entfernt; ohne echte Daten steht jetzt `–`
- User-Agent-Versionen auf V0.1.1.13 angehoben
- keine Datenbankänderung erforderlich

## 📡 V0.1.1.12 — Tesla Driving Stream

Jetzt wird es live. Der permanente Docker-Worker hält pro erkanntem Tesla eine eigene WebSocket-Verbindung zur Tesla-Streaming-Infrastruktur.

- persistenter Owner-API Driving Stream über `/streaming/`
- Stream-Felder: Speed, Odometer, SoC, Elevation, GPS, Heading, Power, Shift State und Range
- automatische Reconnects und Re-Subscribe bei schlafenden/getrennten Fahrzeugen
- Stream-Verbindung weckt den Tesla nicht absichtlich per REST auf
- hochauflösende Stream-Samples werden separat von den normalen REST-Snapshots gespeichert
- aktuelle Stream-Werte aktualisieren Fahrzeugposition, Geschwindigkeit, SoC und Kilometerstand
- eigener Stream-Status je Fahrzeug inklusive Update-Zähler, letztem Event, Gang und Fehleranzeige
- DB-Migration `0.1.1.12.sql`; Docker wendet sie beim Update automatisch an
- Grundlage für automatische Fahrterkennung im nächsten Block

## 🩺 V0.1.1.11 — Worker Status Fix

Der Docker-Worker behält jetzt seinen letzten echten Tesla-Poll sauber im Blick, statt ihn zwischen zwei Polls mit dem Heartbeat zu überschreiben.

- Heartbeat aktualisiert nur noch die Lebensmeldung
- letzter echter Poll bleibt mit Fahrzeugen, Online-Status, Snapshots und Zeitstempel sichtbar
- Worker-State springt zwischen Polls nicht mehr auf `idle`
- Zustände wie `online`, `sleeping`, `waiting_for_tesla`, `paused` und `error` bleiben aussagekräftig
- Heartbeat- und Poll-Zeitstempel werden in der Weboberfläche in der konfigurierten lokalen Zeitzone angezeigt
- keine Datenbankänderung erforderlich

## 🧹 V0.1.1.10 — Structure Cleanup

Der zweite Besendurchgang. Diesmal wurde nicht nur Altcode entfernt, sondern die Webstruktur selbst sauber neu geordnet. 😂🧹

- alle öffentlichen PHP-Seiten liegen jetzt unter `public/`
- CSS/JS liegt unter `public/assets/`
- Apache liefert ausschließlich `public/` aus
- `install.php`, `update.php` und der alte `config/`-Pfad sind entfernt
- der Legacy-`config/local.php`-Fallback ist entfernt
- interne `.htaccess`-Schutzdateien sind nicht mehr nötig
- Docker-QA reagiert jetzt auch auf Änderungen unter `public/`
- keine Datenbankänderung erforderlich

## 🧹 V0.1.1.9 — Repository Cleanup

- alter Shared-Hosting-/Cron-Collector entfernt
- obsolete Collector-Settings bereinigt
- Browser-Geolocation entfernt
- Leaflet nur noch auf der Karten-Seite
- Portainer-Dokumentation überarbeitet

## 🐳 V0.1.1.8 — Docker / Portainer Foundation

- Portainer-/Docker-Stack
- MariaDB 11.8
- PHP 8.4 + Apache
- permanenter Python-Worker
- Healthchecks
- automatische DB-Initialisierung und Migrationen
- NPM-/WebSocket-ready

## 🤖 V0.1.1.7 — Sleep-Friendly Collector

Erster automatischer, schlaf-freundlicher Tesla-Collector.

## 🚗 V0.1.1.6 — Tesla Focus

TrakFog wurde vollständig auf Tesla als Projektfokus umgestellt.

---

Ältere Versionsdetails bleiben über die jeweiligen GitHub Releases und Tags nachvollziehbar.
