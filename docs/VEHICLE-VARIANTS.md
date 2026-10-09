# Fahrzeugvarianten und originale Public-Beta-Illustrationen

TrakFog Public nutzt sechs **eigens gestaltete, generische SVG-Elektrofahrzeugillustrationen**. Sie sind keine Tesla-Produktbilder und sollen keine genaue Ausstattung, Front oder Karosserie eines Tesla-Modells darstellen. Die alte Tesla-Bildsammlung wird bewusst weder als Datei noch in der Git-Historie dieses Repositories veröffentlicht.

| Identifizierte Fahrzeugvariante | Öffentliches, abstraktes Bild |
| --- | --- |
| Model 3 klassisch | `model-3-classic.svg` |
| Model 3 Highland | `model-3-white.svg` |
| Model Y klassisch | `model-y-classic.svg` |
| Model Y Juniper | `model-y-white.svg` |
| Model S | `model-s-white.svg` |
| Model X | `model-x-white.svg` |

Die originale VIN-Auswertung arbeitet lokal ohne externe Decoder; die 10. Stelle nennt das Modelljahr und bestätigt **nicht** sicher ein Facelift. Übergangsjahre werden konservativ behandelt: Model Y 2025 und Model 3 2023 bleiben ohne explizite Konfiguration zunächst unklar, sofern keine manuelle Auswahl erfolgt. Die Variantenauswahl unter **Fahrzeugdetail → Fahrzeugdarstellung** kann eine zulässige Variante speichern, die immer gegen die tatsächliche Modellfamilie geprüft wird. Dashboard und LiveView greifen auf denselben Resolver zu.

In der Public Beta sind die Vektoren absichtlich frei von Tesla-spezifischer Karosserie, Markenzeichen und fotorealistischen Modelldetails. Die Displaygröße nutzt weiterhin eine 1448×1086 SVG-Koordinatenfläche, damit die bestehenden responsiven Komponenten und Schattenebenen kompatibel bleiben.

Siehe [ASSET-ORIGINS.md](ASSET-ORIGINS.md) für Herkunft und Freigabe, [PUBLIC-BETA-CHECKLIST.md](PUBLIC-BETA-CHECKLIST.md) für die externen Freigabeschritte.
