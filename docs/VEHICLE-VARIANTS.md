# Fahrzeugvarianten und TrakFog-Public-Studioillustrationen

Seit **V0.1.1.91** nutzt TrakFog Public sechs **eigens generierte, realistische Elektrofahrzeugillustrationen** mit transparentem Hintergrund im optimierten AVIF-Format. Dies sind keine von Tesla bereitgestellten Produktbilder. Die Bilder ersetzen die alten schematischen SVG-Platzhalter.

| Fahrzeugvariante | Bilddatei |
| --- | --- |
| Model 3 klassisch | `model-3-classic.avif` |
| Model 3 Highland | `model-3-white.avif` |
| Model Y klassisch | `model-y-classic.avif` |
| Model Y Juniper | `model-y-white.avif` |
| Model S | `model-s-white.avif` |
| Model X | `model-x-white.avif` |

Die VIN-Erkennung erfolgt weiterhin lokal ohne externe Decoder. Die 10. VIN-Stelle bezeichnet das Modelljahr, ist aber kein zuverlässiger Facelift-Nachweis. Übergangsjahre (Model 3 2023 und Model Y 2025) bleiben ohne weitere Modellangaben zunächst unklar. Unter **Fahrzeugdetail → Fahrzeugdarstellung** kann eine zum Fahrzeug passende Variante ausgewählt werden. Dashboard und LiveView verwenden dieselbe Zuordnung.

Die sechs transparenten Bilder besitzen dasselbe 4:3-Format wie die bisherige Fahrzeugbühne und werden lokal ausgeliefert. Da bereits ein weicher Bodenschatten zu den generierten Bildern gehört, werden die früheren zusätzlichen Schatten der Public-Bühne ausgeblendet.

Siehe [ASSET-ORIGINS.md](ASSET-ORIGINS.md) für die Herkunft und [PUBLIC-BETA-CHECKLIST.md](PUBLIC-BETA-CHECKLIST.md) für die Veröffentlichungskontrollen.
