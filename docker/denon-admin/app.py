"""Denon AVR telnet sidecar for LANoMAT's room audio (orga only).

Holds one telnet session (port 23) to the receiver, keeps its state current
from the receiver's own replies, and exposes a small bearer-authenticated REST
API. LANoMAT never speaks telnet itself: the receiver IP lives only in this
sidecar's environment (`DENON_HOST`), never in the repository.

Protocol and state parsing are ported from the owner's Omarchy plugin
(`kleintonno.denon/scripts/denon_bridge.py`), minus Zone 2 and minus the
widget-only relative commands (up/down/toggle). Only the commands in
`build_command` can reach the receiver.

Routes:
- GET  /state    -> receiver state (bearer)
- POST /command  -> {"command": "...", "value": ...} (bearer), 200/422/503
- GET  /healthz  -> {"status": "ok", "connected": bool} (no auth)

Auth: one shared-secret bearer token (`DENON_ADMIN_TOKEN`), compared in
constant time. Without a token the sidecar refuses to serve (503) rather than
run open.
"""

from __future__ import annotations

import copy
import hmac
import math
import os
import re
import select
import socket
import threading
import time
from collections import deque
from contextlib import asynccontextmanager
from typing import Any

from fastapi import Depends, FastAPI, HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel

DENON_HOST = os.environ.get("DENON_HOST", "")
DENON_PORT = int(os.environ.get("DENON_PORT", "23"))
ADMIN_TOKEN = os.environ.get("DENON_ADMIN_TOKEN", "")

RETRY_SECONDS = 5.0
HEARTBEAT_SECONDS = 30.0
STALE_SECONDS = 75.0
CONNECT_TIMEOUT_SECONDS = 3.0
# Denon asks for at least 50 ms between commands.
SEND_GAP_SECONDS = 0.08

# Sent on every (re)connect. `SSSOD ?` (the plugin's used-input filter) is
# deliberately not queried; the input list is SSFUN names plus the defaults.
QUERIES = ["PW?", "ZM?", "MV?", "MU?", "SI?", "MS?", "SSFUN ?"]

# Compact sound-mode whitelist: key -> (display name, telnet command).
MODES = {
    "stereo": ("Stereo", "MSSTEREO"),
    "direct": ("Direct", "MSDIRECT"),
    "pure": ("Pure Direct", "MSPURE DIRECT"),
    "auto": ("Auto", "MSAUTO"),
    "movie": ("Movie", "MSMOVIE"),
    "music": ("Music", "MSMUSIC"),
    "game": ("Game", "MSGAME"),
}

# Inputs that SSFUN does not always list; they fill the gaps in the picker.
DEFAULT_SOURCE_NAMES = {
    "TUNER": "Tuner",
    "NET": "HEOS Music",
    "BT": "Bluetooth",
    "USB": "USB",
}

CODE_RE = re.compile(r"[A-Z0-9/]{1,12}")
VOLUME_RE = re.compile(r"MV(\d{2})(5?)")
VOLUME_CMD_RE = re.compile(r"MV\d{2}5?")
VOLUME_MIN = 0.0
VOLUME_MAX = 98.0
MAX_NAME = 32
MAX_BUFFER = 4096


class ReceiverUnreachable(Exception):
    """The telnet session to the receiver is not up."""


def sanitize(text: object) -> str:
    """Receiver text is untrusted: keep it short, printable and markup-free."""
    cleaned = "".join(
        ch for ch in str(text) if ch.isprintable() and ch not in "<>&\"'`"
    )
    return " ".join(cleaned.split())[:MAX_NAME].strip()


def format_volume(value: float) -> str:
    """Volume 0..98 in 0.5 steps -> Denon MV command (MV32, MV325, MV05)."""
    value = max(VOLUME_MIN, min(VOLUME_MAX, round(float(value) * 2) / 2))
    whole = int(value)
    return "MV%02d%s" % (whole, "5" if value - whole else "")


def new_state(sources: list[dict[str, str]] | None = None) -> dict[str, Any]:
    return {
        "connected": False,
        "power": False,
        # None until the receiver answers.
        "mainZone": None,
        "volume": None,
        "volumeMax": VOLUME_MAX,
        "mute": False,
        "source": "",
        "mode": "",
        "sources": sources if sources is not None else [],
        "modes": [{"key": key, "name": name} for key, (name, _) in MODES.items()],
    }


class Receiver:
    """Parses receiver lines into state. Not thread-safe; the Bridge holds the lock."""

    def __init__(self) -> None:
        self._names: dict[str, str] = {}
        self.state = new_state(self._source_list())

    def reset_connection(self) -> None:
        """Drop live values on disconnect; keep the input list so the picker does not flicker."""
        self.state = new_state(self.state["sources"])

    def _source_list(self) -> list[dict[str, str]]:
        names = {**DEFAULT_SOURCE_NAMES, **self._names}
        return [{"code": code, "name": name} for code, name in names.items()]

    def parse_line(self, line: str) -> bool:
        """Apply one receiver line; return True when the state changed."""
        line = line.strip()
        if not line:
            return False
        before = copy.deepcopy(self.state)
        s = self.state

        if line in ("PWON", "PWSTANDBY"):
            s["power"] = line == "PWON"
        elif line in ("ZMON", "ZMOFF"):
            s["mainZone"] = line == "ZMON"
        elif line.startswith("MVMAX"):
            m = re.fullmatch(r"MVMAX ?(\d{2})(5?)", line)
            if m:
                s["volumeMax"] = int(m.group(1)) + (0.5 if m.group(2) else 0.0)
        elif VOLUME_RE.fullmatch(line):
            m = VOLUME_RE.fullmatch(line)
            assert m is not None
            s["volume"] = int(m.group(1)) + (0.5 if m.group(2) else 0.0)
        elif line in ("MUON", "MUOFF"):
            s["mute"] = line == "MUON"
        elif line.startswith("SSFUN"):
            rest = line[5:]
            if rest.strip() != "END":
                code, _, name = rest.strip().partition(" ")
                if CODE_RE.fullmatch(code):
                    self._names[code] = sanitize(name)
                    s["sources"] = self._source_list()
        elif line.startswith("SI"):
            code = line[2:].strip()
            if CODE_RE.fullmatch(code):
                s["source"] = code
        elif line.startswith("MS") and not line.startswith("MSQUICK"):
            s["mode"] = sanitize(line[2:].title())

        return self.state != before


def _is_number(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def build_command(command: object, value: object) -> str:
    """Whitelisted widget command -> telnet command. Raises ValueError otherwise."""
    if command == "power" and value in ("on", "off"):
        return "PWON" if value == "on" else "PWSTANDBY"
    if command == "volume":
        if not _is_number(value):
            raise ValueError("volume must be a number")
        number = float(value)  # type: ignore[arg-type]
        if not math.isfinite(number) or not VOLUME_MIN <= number <= VOLUME_MAX:
            raise ValueError("volume must be between 0 and 98")
        return format_volume(number)
    if command == "mute" and value in ("on", "off"):
        return "MUON" if value == "on" else "MUOFF"
    if command == "source" and isinstance(value, str) and CODE_RE.fullmatch(value):
        return "SI" + value
    if command == "mode" and isinstance(value, str) and value in MODES:
        return MODES[value][1]
    raise ValueError(f"unsupported command {command!r} with value {value!r}")


class Bridge:
    """Owns the telnet session in one background thread and queues commands for it.

    `_sock` is touched only by the loop thread. Shared state (receiver state,
    outbox, buffer, timers) is guarded by `_lock`.
    """

    def __init__(self, host: str, port: int = DENON_PORT) -> None:
        self._host = host
        self._port = port
        self._lock = threading.Lock()
        self._receiver = Receiver()
        self._outbox: deque[str] = deque()
        self._buffer = b""
        self._sock: socket.socket | None = None
        self._next_send = 0.0
        self._next_retry = 0.0
        self._last_rx = 0.0
        self._last_heartbeat = 0.0
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, name="denon-telnet", daemon=True)

    def start(self) -> None:
        # Without a host there is nothing to connect to; stay disconnected.
        if self._host and not self._thread.is_alive():
            self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        if self._thread.is_alive():
            self._thread.join(timeout=2)

    @property
    def connected(self) -> bool:
        with self._lock:
            return bool(self._receiver.state["connected"])

    def snapshot(self) -> dict[str, Any]:
        with self._lock:
            return copy.deepcopy(self._receiver.state)

    def enqueue(self, command: str) -> None:
        with self._lock:
            if not self._receiver.state["connected"]:
                raise ReceiverUnreachable("receiver not connected")
            # A dragged slider sends many volumes; only the newest one matters.
            if VOLUME_CMD_RE.fullmatch(command):
                self._outbox = deque(c for c in self._outbox if not VOLUME_CMD_RE.fullmatch(c))
            self._outbox.append(command)

    # --- loop thread ---------------------------------------------------------

    def _run(self) -> None:
        while not self._stop.is_set():
            now = time.monotonic()
            if self._sock is None:
                if now >= self._next_retry:
                    self._connect()
                if self._sock is None:
                    self._stop.wait(0.5)
                    continue
            if now - self._last_rx > STALE_SECONDS:
                self._disconnect()
                continue
            if now - self._last_heartbeat > HEARTBEAT_SECONDS:
                with self._lock:
                    self._outbox.append("PW?")
                self._last_heartbeat = now
            with self._lock:
                pending = bool(self._outbox)
            try:
                readable, _, _ = select.select(
                    [self._sock], [], [], SEND_GAP_SECONDS if pending else 1.0
                )
            except (OSError, ValueError):
                self._disconnect()
                continue
            if readable:
                self._read()
            self._flush()
        self._disconnect()

    def _connect(self) -> None:
        now = time.monotonic()
        try:
            sock = socket.create_connection((self._host, self._port), timeout=CONNECT_TIMEOUT_SECONDS)
        except OSError:
            self._next_retry = now + RETRY_SECONDS
            return
        sock.setblocking(False)
        self._sock = sock
        with self._lock:
            self._buffer = b""
            self._outbox = deque(QUERIES)
            self._receiver.state["connected"] = True
        self._last_rx = now
        self._last_heartbeat = now

    def _disconnect(self) -> None:
        if self._sock is not None:
            try:
                self._sock.close()
            except OSError:
                pass
        self._sock = None
        with self._lock:
            self._outbox.clear()
            self._buffer = b""
            self._receiver.reset_connection()
        self._next_retry = time.monotonic() + RETRY_SECONDS

    def _read(self) -> None:
        sock = self._sock
        if sock is None:
            return
        try:
            data = sock.recv(4096)
        except BlockingIOError:
            return
        except OSError:
            data = b""
        if not data:
            self._disconnect()
            return
        self._last_rx = time.monotonic()
        self._handle_bytes(data)

    def _handle_bytes(self, data: bytes) -> None:
        with self._lock:
            self._buffer += data
            while b"\r" in self._buffer:
                raw, self._buffer = self._buffer.split(b"\r", 1)
                self._receiver.parse_line(raw.decode("utf-8", "replace"))
            if len(self._buffer) > MAX_BUFFER:
                self._buffer = b""

    def _flush(self) -> None:
        sock = self._sock
        if sock is None or time.monotonic() < self._next_send:
            return
        with self._lock:
            if not self._outbox:
                return
            command = self._outbox.popleft()
        try:
            sock.sendall((command + "\r").encode("ascii"))
        except OSError:
            self._disconnect()
            return
        self._next_send = time.monotonic() + SEND_GAP_SECONDS


bridge = Bridge(DENON_HOST)


@asynccontextmanager
async def lifespan(_: FastAPI):
    bridge.start()
    yield
    bridge.stop()


app = FastAPI(
    title="denon-admin",
    description="Denon AVR telnet sidecar for LANoMAT room audio",
    lifespan=lifespan,
)
bearer = HTTPBearer(auto_error=False)


def require_token(credentials: HTTPAuthorizationCredentials | None = Security(bearer)) -> None:
    if not ADMIN_TOKEN:
        raise HTTPException(status_code=503, detail="DENON_ADMIN_TOKEN not configured")
    if credentials is None or not hmac.compare_digest(
        credentials.credentials.encode(), ADMIN_TOKEN.encode()
    ):
        raise HTTPException(status_code=401, detail="invalid or missing bearer token")


class CommandIn(BaseModel):
    command: str
    value: Any = None


@app.get("/healthz")
def healthz() -> dict[str, Any]:
    return {"status": "ok", "connected": bridge.connected}


@app.get("/state")
def get_state(_: None = Depends(require_token)) -> dict[str, Any]:
    return bridge.snapshot()


@app.post("/command")
def post_command(body: CommandIn, _: None = Depends(require_token)) -> dict[str, bool]:
    try:
        telnet = build_command(body.command, body.value)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        bridge.enqueue(telnet)
    except ReceiverUnreachable as exc:
        raise HTTPException(status_code=503, detail="receiver not connected") from exc
    return {"ok": True}
