from __future__ import annotations

import base64
import json
import ssl
import uuid
from datetime import datetime, timezone
from typing import Any

import httpx

from .config import Config
from .crypto import TrakFogCrypto
from .db import Database


class TeslaError(RuntimeError):
    def __init__(
        self,
        status: int,
        message: str,
        *,
        area: str | None = None,
        endpoint: str | None = None,
        tesla_error: str | None = None,
        error_description: str | None = None,
        txid: str | None = None,
        http_version: str | None = None,
    ) -> None:
        super().__init__(message)
        self.status = status
        self.area = area
        self.endpoint = endpoint
        self.tesla_error = tesla_error
        self.error_description = error_description
        self.txid = txid
        self.http_version = http_version

    def diagnostic(self) -> dict[str, Any]:
        return {
            "status": self.status,
            "area": self.area,
            "endpoint": self.endpoint,
            "tesla_error": self.tesla_error,
            "error_description": self.error_description,
            "txid": self.txid,
            "http_version": self.http_version,
        }


class TeslaClient:
    CHARGING_HISTORY_ENDPOINT = "https://akamai-apigateway-charging-ownership.tesla.com"
    CHARGING_HISTORY_QUERY = """
    query getChargingHistoryV2($pageNumber: Int!, $sortBy: String, $sortOrder: SortByEnum, $latestSession: Boolean) {
      me {
        charging {
          historyV2(
            pageNumber: $pageNumber
            sortBy: $sortBy
            sortOrder: $sortOrder
            latestSession: $latestSession
          ) {
            data {
              chargeSessionId
              sessionId
              siteLocationName
              chargeStartDateTime
              chargeStopDateTime
              chargingPackage {
                energyApplied
              }
              fees {
                feeType
                usageBase
                totalDue
                currencyCode
              }
            }
            hasMoreData
            pageNumber
            totalResults
          }
        }
      }
    }
    """

    ENDPOINTS = ";".join(
        [
            "charge_state",
            "climate_state",
            "closures_state",
            "drive_state",
            "gui_settings",
            "location_data",
            "vehicle_config",
            "vehicle_state",
            "vehicle_data_combo",
        ]
    )

    def __init__(self, config: Config, db: Database) -> None:
        self.config = config
        self.db = db
        self.crypto = TrakFogCrypto(config.app_key)

        # Tesla tightened the Owner API/Auth transport requirements in June 2026.
        # HTTP/1.1 clients can receive misleading HTTP 403 responses even with
        # otherwise valid Owner API tokens. Keep the Tesla control plane on
        # HTTP/2 with TLS 1.3 minimum.
        tesla_tls = ssl.create_default_context()
        tesla_tls.minimum_version = ssl.TLSVersion.TLSv1_3

        self.session = httpx.Client(
            http2=True,
            verify=tesla_tls,
            follow_redirects=True,
            timeout=httpx.Timeout(25.0),
            headers={
                "Accept": "application/json",
                "User-Agent": "TrakFog-Worker/0.1.1.46",
            },
        )

        # Charging History is a separate Tesla/Akamai service. It already
        # worked before the Owner API transport change, so keep its TLS policy
        # independent while still allowing HTTP/2 negotiation.
        self.charging_session = httpx.Client(
            http2=True,
            follow_redirects=True,
            timeout=httpx.Timeout(35.0),
        )

    def integration(self) -> dict[str, Any] | None:
        with self.db.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                SELECT *
                FROM integrations
                WHERE type='tesla_owner_api'
                ORDER BY id DESC
                LIMIT 1
                """
            )
            return cur.fetchone()

    def _jwt_expiry(self, token: str) -> datetime | None:
        try:
            parts = token.split(".")
            if len(parts) < 2:
                return None
            payload = parts[1] + "=" * (-len(parts[1]) % 4)
            decoded = json.loads(base64.urlsafe_b64decode(payload.encode("ascii")))
            exp = int(decoded.get("exp", 0))
            return datetime.fromtimestamp(exp, tz=timezone.utc) if exp else None
        except Exception:
            return None

    def _tokens(self, integration: dict[str, Any]) -> tuple[str, str | None]:
        access = self.crypto.decrypt(integration.get("access_token_enc"))
        refresh = self.crypto.decrypt(integration.get("refresh_token_enc"))
        if not access:
            raise TeslaError(401, "No decryptable Tesla access token stored")
        return access, refresh

    def _store_tokens(
        self,
        integration_id: int,
        access: str,
        refresh: str | None,
        current_refresh_enc: str | None,
    ) -> None:
        expiry = self._jwt_expiry(access)
        expires_at = expiry.strftime("%Y-%m-%d %H:%M:%S") if expiry else None
        refresh_enc = (
            self.crypto.encrypt(refresh)
            if refresh
            else current_refresh_enc
        )

        with self.db.connection() as conn, conn.cursor() as cur:
            cur.execute(
                """
                UPDATE integrations
                SET access_token_enc=%s,
                    refresh_token_enc=%s,
                    token_expires_at=%s,
                    status='connected',
                    last_error=NULL
                WHERE id=%s
                """,
                (
                    self.crypto.encrypt(access),
                    refresh_enc,
                    expires_at,
                    integration_id,
                ),
            )

    def refresh(self, integration: dict[str, Any]) -> dict[str, Any]:
        _, refresh = self._tokens(integration)
        if not refresh:
            raise TeslaError(401, "No Tesla refresh token stored")

        response = self.session.post(
            self.config.tesla_auth_host + "/oauth2/v3/token",
            json={
                "grant_type": "refresh_token",
                "client_id": "ownerapi",
                "refresh_token": refresh,
                "scope": "openid email offline_access",
            },
            timeout=25,
        )

        if not response.is_success:
            details = self._response_error_details(response)
            tesla_text = details["tesla_error"] or details["error_description"]
            raise TeslaError(
                response.status_code,
                f"Tesla token refresh failed ({response.status_code})"
                + (f": {tesla_text}" if tesla_text else ""),
                area="auth",
                endpoint="/oauth2/v3/token",
                tesla_error=details["tesla_error"],
                error_description=details["error_description"],
                txid=details["txid"],
                http_version=details["http_version"],
            )

        payload = response.json()
        access = str(payload.get("access_token") or "")
        if not access:
            raise TeslaError(401, "Tesla refresh returned no access token")

        new_refresh = str(payload.get("refresh_token") or refresh)
        self._store_tokens(
            int(integration["id"]),
            access,
            new_refresh,
            integration.get("refresh_token_enc"),
        )
        return self.integration() or integration

    def ensure_fresh(self, integration: dict[str, Any]) -> dict[str, Any]:
        access, _ = self._tokens(integration)
        expiry = self._jwt_expiry(access)

        if expiry and (expiry - datetime.now(timezone.utc)).total_seconds() <= 90:
            return self.refresh(integration)

        return integration

    def access_token(
        self,
        integration: dict[str, Any],
        *,
        force_refresh: bool = False,
    ) -> str:
        integration = self.refresh(integration) if force_refresh else self.ensure_fresh(integration)
        access, _ = self._tokens(integration)
        return access

    @staticmethod
    def _clean_error_value(value: Any, limit: int = 300) -> str | None:
        if value is None:
            return None
        if isinstance(value, (dict, list)):
            value = json.dumps(value, ensure_ascii=False, separators=(",", ":"))
        text = str(value).strip()
        if not text:
            return None

        # Error payloads should never contain credentials, but keep diagnostics
        # defensive: redact bearer-like/JWT-like values before persisting them.
        parts = text.split()
        safe_parts: list[str] = []
        for part in parts:
            if part.lower().startswith("bearer"):
                safe_parts.append("[redacted]")
                continue
            if part.count(".") == 2 and len(part) > 80:
                safe_parts.append("[redacted-jwt]")
                continue
            safe_parts.append(part)
        return " ".join(safe_parts)[:limit]

    @classmethod
    def _response_error_details(cls, response: httpx.Response) -> dict[str, str | None]:
        payload: Any = None
        try:
            payload = response.json()
        except Exception:
            payload = None

        tesla_error = error_description = txid = None
        if isinstance(payload, dict):
            error_value = payload.get("error")
            if isinstance(error_value, dict):
                tesla_error = cls._clean_error_value(
                    error_value.get("message")
                    or error_value.get("error")
                    or error_value.get("code")
                )
                error_description = cls._clean_error_value(
                    error_value.get("description")
                    or error_value.get("error_description")
                )
                txid = cls._clean_error_value(error_value.get("txid") or error_value.get("request_id"), 120)
            else:
                tesla_error = cls._clean_error_value(error_value)

            error_description = error_description or cls._clean_error_value(
                payload.get("error_description")
                or payload.get("message")
                or payload.get("detail")
            )
            txid = txid or cls._clean_error_value(
                payload.get("txid")
                or payload.get("request_id")
                or payload.get("trace_id"),
                120,
            )
        else:
            tesla_error = cls._clean_error_value(response.text, 300)

        return {
            "tesla_error": tesla_error,
            "error_description": error_description,
            "txid": txid,
            "http_version": cls._clean_error_value(response.http_version, 32),
        }

    def _request(
        self,
        integration: dict[str, Any],
        method: str,
        path: str,
        *,
        params: dict[str, Any] | None = None,
        retry_401: bool = True,
    ) -> dict[str, Any]:
        integration = self.ensure_fresh(integration)
        access, _ = self._tokens(integration)

        response = self.session.request(
            method,
            self.config.tesla_api_host + path,
            params=params,
            headers={"Authorization": f"Bearer {access}"},
            timeout=25,
        )

        if response.status_code == 401 and retry_401:
            integration = self.refresh(integration)
            return self._request(
                integration,
                method,
                path,
                params=params,
                retry_401=False,
            )

        if not response.is_success:
            area = (
                "products"
                if path.startswith("/api/1/products")
                else ("vehicle_data" if "/vehicle_data" in path else "owner_api")
            )
            details = self._response_error_details(response)
            tesla_text = details["tesla_error"] or details["error_description"]
            message = {
                403: (
                    f"Tesla {area} returned HTTP 403"
                    + (f": {tesla_text}" if tesla_text else "")
                ),
                408: "Vehicle asleep or currently unavailable",
                412: "Legacy Tesla Owner API endpoint unavailable",
                429: "Tesla API rate limited",
            }.get(response.status_code, f"Tesla API HTTP {response.status_code}")
            raise TeslaError(
                response.status_code,
                message,
                area=area,
                endpoint=path.split("?", 1)[0],
                tesla_error=details["tesla_error"],
                error_description=details["error_description"],
                txid=details["txid"],
                http_version=details["http_version"],
            )

        return response.json()

    def products(self, integration: dict[str, Any]) -> list[dict[str, Any]]:
        payload = self._request(integration, "GET", "/api/1/products")
        products = payload.get("response") or []
        return [
            item
            for item in products
            if isinstance(item, dict) and "vehicle_id" in item
        ]

    def _charging_history_page(
        self,
        integration: dict[str, Any],
        vin: str,
        page: int,
        *,
        retry_401: bool = True,
    ) -> dict[str, Any]:
        integration = self.ensure_fresh(integration)
        access, _ = self._tokens(integration)
        request_id = str(uuid.uuid4())

        response = self.charging_session.post(
            self.CHARGING_HISTORY_ENDPOINT + "/graphql",
            params={
                "deviceLanguage": "en",
                "deviceCountry": "US",
                "ttpLocale": "en_US",
                "vin": vin,
                "operationName": "getChargingHistoryV2",
            },
            json={
                "query": self.CHARGING_HISTORY_QUERY,
                "variables": {
                    "sortBy": "start_datetime",
                    "sortOrder": "DESC",
                    "pageNumber": page,
                    "latestSession": False,
                },
                "operationName": "getChargingHistoryV2",
            },
            headers={
                "Authorization": f"Bearer {access}",
                "Accept": "*/*",
                "Content-Type": "application/json",
                "User-Agent": "okhttp/4.11.0",
                "x-tesla-user-agent": "com.teslamotors.tesla/4.41.0/723d1365/android/14",
                "x-request-id": request_id,
                "x-txid": request_id,
                "Accept-Language": "en",
                "charset": "utf-8",
                "Cache-Control": "no-cache",
            },
            timeout=35,
        )

        if response.status_code == 401 and retry_401:
            integration = self.refresh(integration)
            return self._charging_history_page(
                integration,
                vin,
                page,
                retry_401=False,
            )

        if not response.is_success:
            message = {
                401: "Tesla charging history token rejected",
                403: "Tesla charging history access forbidden",
                429: "Tesla charging history rate limited",
            }.get(response.status_code, f"Tesla charging history HTTP {response.status_code}")
            raise TeslaError(response.status_code, message)

        payload = response.json()
        errors = payload.get("errors")
        if errors:
            raise TeslaError(200, "Tesla charging history GraphQL error: " + str(errors)[:500])

        history = (
            payload.get("data", {})
            .get("me", {})
            .get("charging", {})
            .get("historyV2")
        )
        if not isinstance(history, dict):
            raise TeslaError(200, "Tesla charging history returned no historyV2 payload")
        return history

    @staticmethod
    def _history_float(value: Any) -> float | None:
        if value is None or isinstance(value, bool):
            return None
        try:
            return float(value)
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _history_datetime(value: Any) -> str | None:
        if not isinstance(value, str) or not value.strip():
            return None
        try:
            parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
        except ValueError:
            return None
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)
        return parsed.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    def _normalize_charging_session(self, raw: dict[str, Any]) -> dict[str, Any]:
        fees = raw.get("fees") if isinstance(raw.get("fees"), list) else []
        charging_fee = next(
            (
                item
                for item in fees
                if isinstance(item, dict) and str(item.get("feeType") or "").upper() == "CHARGING"
            ),
            None,
        )
        if charging_fee is None:
            charging_fee = next((item for item in fees if isinstance(item, dict)), None)

        costs = [
            value
            for item in fees
            if isinstance(item, dict)
            for value in [self._history_float(item.get("totalDue"))]
            if value is not None
        ]
        cost = round(sum(costs), 2) if costs else None

        package = raw.get("chargingPackage") if isinstance(raw.get("chargingPackage"), dict) else {}
        energy = self._history_float(package.get("energyApplied"))
        if energy is None and isinstance(charging_fee, dict):
            energy = self._history_float(charging_fee.get("usageBase"))

        currency = None
        if isinstance(charging_fee, dict):
            currency = str(charging_fee.get("currencyCode") or "").upper() or None
        if currency is None:
            for item in fees:
                if isinstance(item, dict) and item.get("currencyCode"):
                    currency = str(item["currencyCode"]).upper()
                    break

        return {
            "session_id": str(raw.get("chargeSessionId") or raw.get("sessionId") or "").strip() or None,
            "start_at": self._history_datetime(raw.get("chargeStartDateTime")),
            "end_at": self._history_datetime(raw.get("chargeStopDateTime")),
            "location": str(raw.get("siteLocationName") or "").strip() or None,
            "energy_kwh": energy,
            "cost_amount": cost,
            "currency": currency,
            "fees": fees,
            "raw": raw,
        }

    def charging_history(
        self,
        integration: dict[str, Any],
        vin: str,
        *,
        page_limit: int | None = None,
    ) -> list[dict[str, Any]]:
        sessions: list[dict[str, Any]] = []
        page = 1

        while page <= 100:
            if page_limit is not None and page > page_limit:
                break

            current_integration = self.integration() or integration
            history = self._charging_history_page(current_integration, vin, page)
            batch = history.get("data") if isinstance(history.get("data"), list) else []
            sessions.extend(
                self._normalize_charging_session(item)
                for item in batch
                if isinstance(item, dict)
            )

            if not history.get("hasMoreData") or not batch:
                break
            page += 1

        if page > 100:
            raise TeslaError(0, "Tesla charging history returned more than 100 pages")

        return sessions

    def vehicle_extras(
        self,
        integration: dict[str, Any],
        external_id: str,
    ) -> tuple[dict[str, Any], list[dict[str, Any]]]:
        endpoints = {
            "nearby_charging": f"/api/1/vehicles/{external_id}/nearby_charging_sites",
            "recent_alerts": f"/api/1/vehicles/{external_id}/recent_alerts",
            "release_notes": f"/api/1/vehicles/{external_id}/release_notes",
            "service_data": f"/api/1/vehicles/{external_id}/service_data",
        }
        payloads: dict[str, Any] = {}
        errors: list[dict[str, Any]] = []

        for key, path in endpoints.items():
            try:
                payload = self._request(integration, "GET", path)
                payloads[key] = payload.get("response")
            except TeslaError as exc:
                errors.append({
                    "endpoint": key,
                    "status": exc.status,
                    "message": str(exc)[:240],
                })

        return payloads, errors

    def vehicle_data(self, integration: dict[str, Any], external_id: str) -> dict[str, Any]:
        path = f"/api/1/vehicles/{external_id}/vehicle_data"

        try:
            payload = self._request(
                integration,
                "GET",
                path,
                params={"endpoints": self.ENDPOINTS},
            )
            response = payload.get("response") or {}
            if isinstance(response, dict):
                response["_trakfog_fetch_mode"] = "full"
                return response
            return {}
        except TeslaError as exc:
            if exc.status != 403:
                raise

        safe_endpoints = ";".join(
            [
                "charge_state",
                "climate_state",
                "closures_state",
                "drive_state",
                "gui_settings",
                "vehicle_config",
                "vehicle_state",
            ]
        )

        try:
            payload = self._request(
                integration,
                "GET",
                path,
                params={"endpoints": safe_endpoints},
            )
            response = payload.get("response") or {}
            if isinstance(response, dict):
                response["_trakfog_fetch_mode"] = "without_location"
                return response
            return {}
        except TeslaError as exc:
            if exc.status != 403:
                raise

        payload = self._request(integration, "GET", path)
        response = payload.get("response") or {}
        if isinstance(response, dict):
            response["_trakfog_fetch_mode"] = "default"
            return response
        return {}
