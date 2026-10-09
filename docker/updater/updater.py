#!/usr/bin/env python3
from __future__ import annotations

import hmac
import json
import os
import re
import stat
import subprocess
import tempfile
import threading
import time
import urllib.parse
import urllib.request
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

REPOSITORY = "hotteftw1981/TrakFog-Public"
TOKEN_FILE = Path(os.getenv("TRAKFOG_UPDATER_TOKEN_FILE", "/var/lib/trakfog/updater_token"))
STATUS_FILE = Path(os.getenv("TRAKFOG_UPDATER_STATUS_FILE", "/var/lib/trakfog/updater_status.json"))
VERSION_RE = re.compile(r"^v?(\d+(?:\.\d+){2,3}(?:[-+][0-9A-Za-z.-]+)?)$")
TAG_RE = re.compile(r"^v?\d+(?:\.\d+){2,3}(?:[-+][0-9A-Za-z.-]+)?$")
MAX_ARCHIVE_BYTES = 250 * 1024 * 1024

_update_lock = threading.Lock()
_compose_ready: bool | None = None


def _read_token() -> str | None:
    try:
        token = TOKEN_FILE.read_text(encoding="utf-8").strip()
    except OSError:
        return None
    return token or None


def _write_status(payload: dict[str, Any]) -> None:
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    data = dict(payload)
    data["updated_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    tmp = STATUS_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    os.chmod(tmp, 0o644)
    os.replace(tmp, STATUS_FILE)


def _read_status() -> dict[str, Any] | None:
    try:
        data = json.loads(STATUS_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return data if isinstance(data, dict) else None


def _compose_available() -> bool:
    global _compose_ready
    if _compose_ready is not None:
        return _compose_ready
    try:
        result = subprocess.run(
            ["docker", "compose", "version"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=8,
            check=False,
        )
        _compose_ready = result.returncode == 0
    except (OSError, subprocess.SubprocessError):
        _compose_ready = False
    return _compose_ready


def _ready() -> dict[str, Any]:
    token_ready = _read_token() is not None
    socket_ready = Path("/var/run/docker.sock").exists()
    compose_ready = _compose_available()
    return {
        "ok": True,
        "ready": token_ready and socket_ready and compose_ready,
        "busy": _update_lock.locked(),
        "token_ready": token_ready,
        "docker_socket_ready": socket_ready,
        "compose_ready": compose_ready,
        "last": _read_status(),
    }


def _normalize_version(value: str) -> str | None:
    match = VERSION_RE.fullmatch(value.strip())
    return match.group(1) if match else None


def _github_headers(token: str | None) -> dict[str, str]:
    headers = {
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "TrakFog-Docker-Updater/1",
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return headers


def _verify_latest_release(repository: str, tag: str, token: str | None) -> None:
    url = f"https://api.github.com/repos/{repository}/releases/latest"
    request = urllib.request.Request(url, headers=_github_headers(token))
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.loads(response.read().decode("utf-8"))
    if not isinstance(payload, dict) or str(payload.get("tag_name", "")).strip() != tag:
        raise RuntimeError("Der angeforderte Tag ist nicht das aktuell veröffentlichte Stable Release.")


def _download_archive(repository: str, tag: str, token: str | None, destination: Path) -> None:
    encoded_tag = urllib.parse.quote(tag, safe="")
    url = f"https://api.github.com/repos/{repository}/zipball/{encoded_tag}"
    request = urllib.request.Request(url, headers=_github_headers(token))
    with urllib.request.urlopen(request, timeout=60) as response, destination.open("wb") as out:
        total = 0
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            total += len(chunk)
            if total > MAX_ARCHIVE_BYTES:
                raise RuntimeError("Release-Archiv überschreitet das Größenlimit.")
            out.write(chunk)


def _safe_extract(archive: Path, destination: Path) -> Path:
    destination.mkdir(parents=True, exist_ok=True)
    root = destination.resolve()
    with zipfile.ZipFile(archive) as zf:
        for member in zf.infolist():
            target = (destination / member.filename).resolve()
            if target != root and root not in target.parents:
                raise RuntimeError("Unsicherer Pfad im Release-Archiv.")
            file_type = (member.external_attr >> 16) & 0o170000
            if file_type == stat.S_IFLNK:
                raise RuntimeError("Symlinks im Release-Archiv werden nicht akzeptiert.")
        zf.extractall(destination)

    for candidate in destination.iterdir():
        if candidate.is_dir() and (candidate / "docker-compose.yml").is_file() and (candidate / "VERSION").is_file():
            return candidate
    raise RuntimeError("Release-Archiv enthält keinen gültigen TrakFog-Stack.")


def _run_update(payload: dict[str, Any]) -> None:
    repository = str(payload["repository"])
    version = str(payload["version"])
    tag = str(payload["tag"])
    github_token = str(payload.get("github_token") or "").strip() or None

    try:
        _write_status({"state": "verifying", "version": version, "tag": tag})
        _verify_latest_release(repository, tag, github_token)

        with tempfile.TemporaryDirectory(prefix="trakfog-update-") as temp:
            temp_dir = Path(temp)
            archive = temp_dir / "release.zip"
            source_dir = temp_dir / "source"

            _write_status({"state": "downloading", "version": version, "tag": tag})
            _download_archive(repository, tag, github_token, archive)
            release_root = _safe_extract(archive, source_dir)

            archive_version = _normalize_version((release_root / "VERSION").read_text(encoding="utf-8"))
            if archive_version != version:
                raise RuntimeError(
                    f"Release-Version stimmt nicht: erwartet {version}, Archiv enthält {archive_version or 'ungültig'}."
                )

            _write_status({"state": "building", "version": version, "tag": tag})
            result = subprocess.run(
                [
                    "docker", "compose",
                    "-p", "trakfog",
                    "-f", str(release_root / "docker-compose.yml"),
                    "up", "-d", "--build", "--no-deps",
                    "web", "worker",
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                timeout=30 * 60,
                check=False,
                env=os.environ.copy(),
            )
            output = (result.stdout or "")[-12000:]
            if result.returncode != 0:
                raise RuntimeError(
                    "Docker Compose Update fehlgeschlagen: "
                    + (output.strip()[-3000:] if output.strip() else f"Exit-Code {result.returncode}")
                )

            _write_status({
                "state": "success",
                "version": version,
                "tag": tag,
                "message": "Web und Datendienst wurden neu gebaut und gestartet.",
                "compose_output_tail": output[-3000:],
            })
    except Exception as exc:
        _write_status({
            "state": "error",
            "version": version,
            "tag": tag,
            "message": str(exc)[:4000],
        })
    finally:
        _update_lock.release()


class Handler(BaseHTTPRequestHandler):
    server_version = "TrakFogUpdater/1"

    def log_message(self, fmt: str, *args: Any) -> None:
        print("[updater] " + (fmt % args), flush=True)

    def _send(self, status: int, payload: dict[str, Any]) -> None:
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def _authorized(self) -> bool:
        token = _read_token()
        supplied = self.headers.get("X-TrakFog-Updater-Token", "").strip()
        return token is not None and supplied != "" and hmac.compare_digest(token, supplied)

    def do_GET(self) -> None:
        if self.path == "/health":
            state = _ready()
            self._send(200 if state["ready"] else 503, state)
            return
        if self.path == "/status":
            if not self._authorized():
                self._send(401, {"ok": False, "error": "Unauthorized"})
                return
            self._send(200, _ready())
            return
        self._send(404, {"ok": False, "error": "Not found"})

    def do_POST(self) -> None:
        if self.path != "/update":
            self._send(404, {"ok": False, "error": "Not found"})
            return
        if not self._authorized():
            self._send(401, {"ok": False, "error": "Unauthorized"})
            return

        state = _ready()
        if not state["ready"]:
            self._send(503, {"ok": False, "error": "Docker-Updater ist nicht bereit.", **state})
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if length <= 0 or length > 65536:
            self._send(400, {"ok": False, "error": "Ungültige Request-Größe."})
            return

        try:
            payload = json.loads(self.rfile.read(length))
        except (json.JSONDecodeError, UnicodeDecodeError):
            self._send(400, {"ok": False, "error": "Ungültiges JSON."})
            return
        if not isinstance(payload, dict):
            self._send(400, {"ok": False, "error": "Ungültiger Update-Auftrag."})
            return

        repository = str(payload.get("repository", "")).strip()
        version = _normalize_version(str(payload.get("version", "")))
        tag = str(payload.get("tag", "")).strip()

        if repository != REPOSITORY:
            self._send(400, {"ok": False, "error": "Nicht erlaubtes Repository."})
            return
        if version is None or not TAG_RE.fullmatch(tag) or _normalize_version(tag) != version:
            self._send(400, {"ok": False, "error": "Ungültige Release-Version oder Tag."})
            return
        if not _update_lock.acquire(blocking=False):
            self._send(409, {"ok": False, "error": "Es läuft bereits ein Update."})
            return

        _write_status({"state": "accepted", "version": version, "tag": tag})
        threading.Thread(
            target=_run_update,
            args=({**payload, "version": version, "tag": tag},),
            daemon=True,
        ).start()
        self._send(202, {"ok": True, "status": "accepted", "version": version, "tag": tag})


def main() -> None:
    ThreadingHTTPServer(("0.0.0.0", 8765), Handler).serve_forever()


if __name__ == "__main__":
    main()
