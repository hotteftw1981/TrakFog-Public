# TrakFog Integration API v1

Die Integration API verbindet TrakFog sicher mit externen Systemen wie VoltCore. Sie ist bewusst **fahrzeuganbieter-neutral**: Ein Verbraucher spricht mit TrakFog, nicht direkt mit Tesla.

## Sicherheit

- API-Tokens werden nur einmal im Klartext angezeigt.
- In der Datenbank liegt ausschließlich ein SHA-256-Hash des zufälligen 256-Bit-Tokens.
- Tokens besitzen Scopes; V1 startet mit `vehicle:read`.
- Standard-Limit: 120 Requests pro Minute und Token.
- Sperrbare Zugänge, letzter Zugriff und Request-Audit ohne Payloads oder Tesla-Zugangsdaten.
- Tesla Access-/Refresh-Tokens werden über diese API niemals ausgegeben.
- Für produktive Verbindungen HTTPS verwenden.

## Authentifizierung

Im TrakFog-Backend unter **System → Integration API** einen Zugang erstellen. Der Token wird genau einmal angezeigt.

```http
Authorization: Bearer tfk_<token>
```

## Fahrzeuge auflisten

```http
GET /api/v1/vehicles
```

Beispiel:

```json
{
  "ok": true,
  "api_version": 1,
  "vehicles": [
    {
      "id": 3,
      "name": "Mein Tesla",
      "vin": "LRW...",
      "soc": 43,
      "target_soc": 80,
      "charging": true,
      "freshness": {
        "status": "fresh",
        "age_seconds": 8,
        "updated_at": "2026-10-08T11:42:15+00:00"
      }
    }
  ]
}
```

## Fahrzeugstatus

```http
GET /api/v1/vehicles/{id}/status
```

Antwortfelder:

- Identität: TrakFog-ID, Name, VIN, Datenquelle, Fahrzeugzustand
- Batterie: SoC, nutzbarer SoC, Ziel-SoC, Reichweite
- Laden: aktiv, Zustand, Fahrzeug-Ladeleistung, lokale TrakFog-Ladesession
- Kilometerstand
- `freshness.status`: `fresh`, `stale`, `offline` oder `unknown`
- Alter und Zeitpunkt der zuletzt verfügbaren Fahrzeugdaten

VoltCore soll einen alten Wert niemals als Live-Wert darstellen: `freshness` und `age_seconds` müssen berücksichtigt werden.

## Fehler

JSON-Fehler besitzen `error` und `message`. Wichtige Statuscodes:

- `401` – Token fehlt, ist ungültig oder gesperrt
- `403` – Scope fehlt
- `404` – Endpoint/Fahrzeug nicht vorhanden
- `405` – Methode nicht erlaubt
- `429` – Rate-Limit überschritten
- `500` – interner Fehler mit Fehler-ID

## VoltCore-MVP

1. In TrakFog einen API-Zugang mit Scope `vehicle:read` erzeugen.
2. VoltCore ruft `/api/v1/vehicles` ab und speichert die TrakFog-Fahrzeug-ID zur eigenen Fahrzeug-/Benutzerzuordnung.
3. Bei einer laufenden OCPP-Session ruft VoltCore `/api/v1/vehicles/{id}/status` periodisch ab.
4. VoltCore zeigt SoC und Ziel-SoC nur unter Beachtung der Frische an.
5. Eine spätere API-Stufe kann Lade-Session-Korrelation und Webhooks ergänzen, ohne die V1-Verträge zu brechen.
