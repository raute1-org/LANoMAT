"""Minimal ServerQuery-REST sidecar for the TeamSpeak server.

`planetteamspeak/ts3-php-framework` (the obvious PHP-side ServerQuery
library) declares PHP 8.1/8.2/8.3 only (latest release 1.3.0) and would break
`composer install` on this project's PHP 8.4 — see the M8 roadmap's "No
PHP-version-capped dependency" constraint. Rather than pull in an unmaintained
Python ServerQuery wrapper of unknown quality either, this sidecar talks
ServerQuery directly: it is a small, line-based text protocol over a plain
TCP socket (see the `_ServerQueryConnection` class below), so a purpose-built
client is a couple dozen lines — the same "own minimal sidecar over a
third-party dependency of uncertain provenance" call the M3 mumble-admin
sidecar made against `murmur-rest`.

Exposes the identical REST surface as `docker/mumble-admin/app.py`
(`GET/POST /channels`, `PATCH|DELETE /channels/{id}`, `GET /healthz`, the same
Bearer-token auth, the same `ChannelOut` JSON shape) so `HttpTeamSpeakClient`
(Task 8.3) and `HttpMumbleClient` are interchangeable behind `VoiceClient`.

Talks to the TeamSpeak server's ServerQuery interface (`TEAMSPEAK_HOST:10011`,
enabled by default on the official `teamspeak` image) using a dedicated
query-account (`TEAMSPEAK_QUERY_USER`/`TEAMSPEAK_QUERY_PASSWORD`, see
docker/teamspeak-admin/README.md for how to create one and scope it to the
virtual server this sidecar manages, `TEAMSPEAK_SERVER_ID`).

Auth: a single shared-secret bearer token (`TEAMSPEAK_ADMIN_TOKEN`), the same
shape as `MUMBLE_ADMIN_TOKEN` — one LAN-internal secret per sidecar. This
sits in front of the ServerQuery call, which itself is authenticated
separately against the TeamSpeak server via the query-account credentials.

// M8-infra-later: this image is unbuilt/unverified this phase (mode A) — no
real TeamSpeak server was run against it. The ServerQuery command choices
below (channelcreate/channeledit/channeldelete/channellist -client) are
standard per the public ServerQuery documentation, but the mapping has not
been exercised against a live server. Real-verify on real hardware before
relying on this in production (see docs/teamspeak-setup.md).
"""

from __future__ import annotations

import os
import socket
from contextlib import asynccontextmanager
from typing import Optional

from fastapi import Depends, FastAPI, HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel

QUERY_HOST = os.environ.get("TEAMSPEAK_HOST", "teamspeak")
QUERY_PORT = int(os.environ.get("TEAMSPEAK_QUERY_PORT", "10011"))
QUERY_USER = os.environ.get("TEAMSPEAK_QUERY_USER", "serveradmin")
QUERY_PASSWORD = os.environ.get("TEAMSPEAK_QUERY_PASSWORD", "")
SERVER_ID = int(os.environ.get("TEAMSPEAK_SERVER_ID", "1"))
ADMIN_TOKEN = os.environ.get("TEAMSPEAK_ADMIN_TOKEN", "")
QUERY_TIMEOUT_SECONDS = float(os.environ.get("TEAMSPEAK_QUERY_TIMEOUT", "10"))


@asynccontextmanager
async def lifespan(_: FastAPI):
    yield
    # Nothing to release on shutdown: unlike the Ice communicator in
    # mumble-admin, every ServerQuery call below opens and closes its own
    # short-lived socket (see `_query()`) rather than holding a long-lived
    # connection, so there is no persistent resource to clean up here.


app = FastAPI(
    title="teamspeak-admin",
    description="ServerQuery-REST sidecar for the TeamSpeak server",
    lifespan=lifespan,
)
bearer = HTTPBearer(auto_error=False)


class ChannelIn(BaseModel):
    name: str
    parent: int = 0
    temporary: bool = False


class ChannelPatch(BaseModel):
    name: str


class ChannelOut(BaseModel):
    id: int
    name: str
    parent: int
    temporary: bool
    occupants: int = 0


def require_token(credentials: HTTPAuthorizationCredentials | None = Security(bearer)) -> None:
    if not ADMIN_TOKEN:
        # No token configured: refuse to serve rather than run open (LAN-internal
        # secret is mandatory per the compose/.env.example contract).
        raise HTTPException(status_code=503, detail="TEAMSPEAK_ADMIN_TOKEN not configured")
    if credentials is None or credentials.credentials != ADMIN_TOKEN:
        raise HTTPException(status_code=401, detail="invalid or missing bearer token")


class ServerQueryError(Exception):
    """A ServerQuery `error id=N msg=...` response with a non-zero id."""

    def __init__(self, error_id: int, message: str) -> None:
        self.error_id = error_id
        self.message = message
        super().__init__(f"ServerQuery error {error_id}: {message}")


def _escape(value: str) -> str:
    """Escape a value per the ServerQuery parameter escaping rules.

    Order matters: backslash first (so later escapes aren't re-escaped),
    then the rest. See the official ServerQuery manual's "Escaping
    special characters" section.
    """
    replacements = [
        ("\\", "\\\\"),
        ("/", "\\/"),
        (" ", "\\s"),
        ("|", "\\p"),
        ("\a", "\\a"),
        ("\b", "\\b"),
        ("\f", "\\f"),
        ("\n", "\\n"),
        ("\r", "\\r"),
        ("\t", "\\t"),
        ("\v", "\\v"),
    ]
    for search, replacement in replacements:
        value = value.replace(search, replacement)
    return value


def _unescape(value: str) -> str:
    replacements = [
        ("\\s", " "),
        ("\\p", "|"),
        ("\\a", "\a"),
        ("\\b", "\b"),
        ("\\f", "\f"),
        ("\\n", "\n"),
        ("\\r", "\r"),
        ("\\t", "\t"),
        ("\\v", "\v"),
        ("\\/", "/"),
        ("\\\\", "\\"),
    ]
    for search, replacement in replacements:
        value = value.replace(search, replacement)
    return value


def _build_command(command: str, params: dict[str, str | int | bool] | None = None) -> str:
    parts = [command]
    for key, value in (params or {}).items():
        if isinstance(value, bool):
            parts.append(f"{key}={1 if value else 0}")
        elif isinstance(value, int):
            parts.append(f"{key}={value}")
        else:
            parts.append(f"{key}={_escape(str(value))}")
    return " ".join(parts)


def _parse_error_line(line: str) -> ServerQueryError:
    error_id = 0
    message = "ok"
    for token in line.split():
        if token.startswith("id="):
            error_id = int(token[len("id=") :])
        elif token.startswith("msg="):
            message = _unescape(token[len("msg=") :])
    return ServerQueryError(error_id, message)


def _parse_rows(line: str) -> list[dict[str, str]]:
    """Parse a ServerQuery response body (`|`-separated rows, space-separated
    `key=value` pairs) into a list of dicts."""
    line = line.strip()
    if not line:
        return []
    rows = []
    for raw_row in line.split("|"):
        row: dict[str, str] = {}
        for token in raw_row.split(" "):
            if not token:
                continue
            if "=" in token:
                key, _, value = token.partition("=")
                row[key] = _unescape(value)
            else:
                row[token] = ""
        if row:
            rows.append(row)
    return rows


class _ServerQueryConnection:
    """A short-lived ServerQuery session: connect, login, `use`, run one
    command, logout, disconnect. Simpler and more robust than pooling a
    long-lived connection across FastAPI requests (no keepalive/reconnect
    logic needed), at the cost of one extra round trip per HTTP call — an
    acceptable trade for an admin sidecar that is not on any hot path.
    """

    def __init__(self) -> None:
        self._socket: Optional[socket.socket] = None
        self._buffer = b""

    def __enter__(self) -> "_ServerQueryConnection":
        self._socket = socket.create_connection((QUERY_HOST, QUERY_PORT), timeout=QUERY_TIMEOUT_SECONDS)
        # The server greets with two banner lines ("TS3\n" and a welcome
        # message) before it accepts any command.
        self._read_line()
        self._read_line()
        self._login()
        self._use_server()
        return self

    def __exit__(self, *_exc_info: object) -> None:
        if self._socket is not None:
            try:
                self._send("quit")
                self._read_response()
            except OSError:
                pass
            finally:
                self._socket.close()
                self._socket = None

    def _send(self, line: str) -> None:
        assert self._socket is not None
        self._socket.sendall(line.encode("utf-8") + b"\n")

    def _read_line(self) -> str:
        assert self._socket is not None
        while b"\n" not in self._buffer:
            chunk = self._socket.recv(4096)
            if not chunk:
                break
            self._buffer += chunk
        line, _, self._buffer = self._buffer.partition(b"\n")
        return line.decode("utf-8", errors="replace").rstrip("\r")

    def _read_response(self) -> list[dict[str, str]]:
        """Read lines until the `error` line that terminates every
        ServerQuery response, raising on a non-zero error id."""
        data_line = ""
        while True:
            line = self._read_line()
            if line.startswith("error"):
                error = _parse_error_line(line)
                if error.error_id != 0:
                    raise error
                return _parse_rows(data_line)
            data_line = line

    def _login(self) -> None:
        self._send(_build_command("login", {"client_login_name": QUERY_USER, "client_login_password": QUERY_PASSWORD}))
        self._read_response()

    def _use_server(self) -> None:
        self._send(_build_command("use", {"sid": SERVER_ID}))
        self._read_response()

    def command(self, name: str, params: dict[str, str | int | bool] | None = None) -> list[dict[str, str]]:
        self._send(_build_command(name, params))
        return self._read_response()


def _map_query_errors(func):
    def wrapper(*args, **kwargs):
        try:
            return func(*args, **kwargs)
        except HTTPException:
            raise
        except ServerQueryError as exc:
            # error id 768 = "invalid channel ID", the ServerQuery equivalent
            # of Murmur's InvalidChannelException in mumble-admin.
            if exc.error_id == 768:
                raise HTTPException(status_code=404, detail="unknown channel id") from exc
            if exc.error_id in (520, 3331):
                # 520 = invalid loginname/password, 3331 = flood ban — both
                # are ServerQuery-side auth/rate problems, not the caller's
                # bearer token (already checked by require_token).
                raise HTTPException(status_code=502, detail=f"ServerQuery authentication failed: {exc.message}") from exc
            raise HTTPException(status_code=502, detail=f"ServerQuery call failed: {exc.message}") from exc
        except (OSError, TimeoutError) as exc:
            raise HTTPException(status_code=502, detail=f"could not reach TeamSpeak ServerQuery: {exc}") from exc

    return wrapper


def _occupant_counts() -> dict[int, int]:
    """Client count per channel, via `clientlist` (each connected client
    reports its `cid`); channels with no connected clients simply have no
    entry and default to 0."""
    with _ServerQueryConnection() as conn:
        clients = conn.command("clientlist")
    counts: dict[int, int] = {}
    for client in clients:
        cid = int(client.get("cid", "0"))
        counts[cid] = counts.get(cid, 0) + 1
    return counts


def _channel_to_out(row: dict[str, str], occupants: dict[int, int]) -> ChannelOut:
    channel_id = int(row["cid"])
    return ChannelOut(
        id=channel_id,
        name=row.get("channel_name", ""),
        parent=int(row.get("pid", "0")),
        temporary=row.get("channel_flag_permanent", "1") == "0",
        occupants=occupants.get(channel_id, 0),
    )


@app.get("/channels", response_model=list[ChannelOut])
@_map_query_errors
def list_channels(_: None = Depends(require_token)) -> list[ChannelOut]:
    with _ServerQueryConnection() as conn:
        rows = conn.command("channellist")
    occupants = _occupant_counts()
    return [_channel_to_out(row, occupants) for row in rows]


@app.post("/channels", response_model=ChannelOut, status_code=201)
@_map_query_errors
def create_channel(payload: ChannelIn, _: None = Depends(require_token)) -> ChannelOut:
    # NOTE on `temporary`: ServerQuery's `channelcreate` accepts
    # `channel_flag_temporary` directly (unlike Murmur's Ice API, which has
    # no equivalent create-time flag — see mumble-admin's matching note).
    # channel_flag_permanent=0 + channel_flag_semi_permanent=0 together with
    # channel_flag_temporary=1 mark a channel that disappears once empty and
    # the query session disconnects; we set both flags for a single
    # unambiguous "temporary" concept mirroring `VoiceChannel::$temporary`.
    params: dict[str, str | int | bool] = {
        "channel_name": payload.name,
        "cpid": payload.parent,
    }
    if payload.temporary:
        params["channel_flag_temporary"] = True
        params["channel_flag_permanent"] = False
    else:
        params["channel_flag_permanent"] = True

    with _ServerQueryConnection() as conn:
        created = conn.command("channelcreate", params)
        channel_id = int(created[0]["cid"])
        rows = conn.command("channelinfo", {"cid": channel_id})
    row = rows[0] if rows else {}
    row.setdefault("cid", str(channel_id))
    row.setdefault("channel_name", payload.name)
    row.setdefault("pid", str(payload.parent))
    row.setdefault("channel_flag_permanent", "0" if payload.temporary else "1")
    return _channel_to_out(row, {})


@app.patch("/channels/{channel_id}", response_model=ChannelOut)
@_map_query_errors
def rename_channel(channel_id: int, payload: ChannelPatch, _: None = Depends(require_token)) -> ChannelOut:
    with _ServerQueryConnection() as conn:
        conn.command("channeledit", {"cid": channel_id, "channel_name": payload.name})
        rows = conn.command("channelinfo", {"cid": channel_id})
    row = rows[0] if rows else {}
    row.setdefault("cid", str(channel_id))
    row.setdefault("channel_name", payload.name)
    occupants = _occupant_counts()
    return _channel_to_out(row, occupants)


@app.delete("/channels/{channel_id}", status_code=204)
@_map_query_errors
def delete_channel(channel_id: int, _: None = Depends(require_token)) -> None:
    with _ServerQueryConnection() as conn:
        conn.command("channeldelete", {"cid": channel_id, "force": True})


@app.get("/healthz")
def healthz() -> dict[str, str]:
    return {"status": "ok"}
