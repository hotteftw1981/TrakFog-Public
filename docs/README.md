# TrakFog GitHub Pages

Die statische Projektseite liegt in `docs/` und ist bewusst unabhängig vom TrakFog-Backend.

## Empfohlene Pages-Konfiguration

### Während der privaten Entwicklung
Zum internen Testen kann GitHub Pages vorübergehend aus `main /docs` veröffentlicht werden, sofern der verwendete GitHub-Plan Pages für private Repositories unterstützt.

### Beim Public-Cutover
Sobald der geprüfte Public-Release auf `stable` liegt und das [Freigabegate](PUBLIC-BETA-CHECKLIST.md) vollständig erledigt ist:

1. Repository → **Settings → Pages**
2. **Build and deployment → Deploy from a branch**
3. Branch **stable**
4. Ordner **/docs**
5. Speichern

Damit zeigt die öffentliche Projektseite immer den veröffentlichten Community-Stand und niemals einen unfertigen `main`-Commit.

## Branding

- `docs/assets/trakfog-logo.svg` – horizontale Wortmarke
- `docs/assets/trakfog-icon.svg` – quadratisches Icon
- dieselbe Bildsprache kann später für PWA, Favicon und Release-Grafiken verwendet werden

Die Landingpage enthält keine Zugangsdaten, Fahrzeugpositionen oder Live-API-Aufrufe.
