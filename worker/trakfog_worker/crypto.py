from __future__ import annotations

import base64
import os

from cryptography.hazmat.primitives.ciphers.aead import AESGCM


class TrakFogCrypto:
    def __init__(self, app_key: str) -> None:
        if not app_key.startswith("base64:"):
            raise RuntimeError("TRAKFOG_APP_KEY must start with base64:")

        raw = base64.b64decode(app_key[7:], validate=True)
        if len(raw) < 32:
            raise RuntimeError("TRAKFOG_APP_KEY is too short")

        self._key = raw[:32]

    def decrypt(self, encoded: str | None) -> str | None:
        if not encoded:
            return None

        raw = base64.b64decode(encoded, validate=True)
        if len(raw) < 29:
            return None

        iv = raw[:12]
        tag = raw[12:28]
        ciphertext = raw[28:]

        try:
            plain = AESGCM(self._key).decrypt(iv, ciphertext + tag, None)
            return plain.decode("utf-8")
        except Exception:
            return None

    def encrypt(self, plain: str) -> str:
        iv = os.urandom(12)
        encrypted = AESGCM(self._key).encrypt(iv, plain.encode("utf-8"), None)
        ciphertext, tag = encrypted[:-16], encrypted[-16:]
        return base64.b64encode(iv + tag + ciphertext).decode("ascii")
