"""Minimal Ice-REST sidecar for the Mumble server.

`murmur-rest` (github.com/alfg/murmur-rest) was evaluated and rejected: its
last real commit is from 2024-07 (per the upstream GitHub API), it targets
Flask + an old Ice binding, and it is not published for any recent Mumble/Ice
combination. Per the design doc (docs/superpowers/specs/2026-07-13-...,
section 6.12/16) and the M3 roadmap (task 3.19), the fallback is this small,
purpose-built sidecar: it only implements what LANoMAT's `MumbleClient`
(Task 20) needs — channel list/create/rename/delete — and stays behind that
interface, so it is never touched by the Laravel test suite.

Talks to the Mumble server's Ice interface (`MUMBLE_CONFIG_ICE`, enabled in
compose.yml) using the `Murmur` Ice module (stable Mumble 1.4.x slice, see
docker/mumble-admin/Murmur.ice). Ice's Python binding has no portable
manylinux wheel, so this image installs the `python3-zeroc-ice` Ubuntu
package (apt, universe) instead of a pip package — it matches the Ice ABI
(3.7) the official `mumblevoip/mumble-server` image itself ships
(`libzeroc-ice3.7t64`).

Auth: a single shared-secret bearer token (`MUMBLE_ADMIN_TOKEN`, defaults to
the same value as `MUMBLE_ICE_SECRET` — one LAN-internal secret to manage).
This sits in front of the Ice call, which itself is authenticated separately
against the Mumble server via `icesecretwrite`.
"""

from __future__ import annotations

import functools
import os
import sys
from contextlib import asynccontextmanager
from typing import Optional

import Ice  # type: ignore[import-not-found]
from fastapi import Depends, FastAPI, HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel

ICE_HOST = os.environ.get("MUMBLE_ICE_HOST", "mumble")
ICE_PORT = os.environ.get("MUMBLE_ICE_PORT", "6502")
ICE_SECRET = os.environ.get("MUMBLE_ICE_SECRET", "")
ADMIN_TOKEN = os.environ.get("MUMBLE_ADMIN_TOKEN", ICE_SECRET)
SERVER_ID = int(os.environ.get("MUMBLE_SERVER_ID", "1"))
SLICE_FILE = os.environ.get("MUMBLE_ICE_SLICE", "/app/Murmur.ice")
# Base Ice slice include dir (zeroc-ice-slice apt package): Murmur.ice's
# `#include <Ice/SliceChecksumDict.ice>` needs this on the include path.
SLICE_INCLUDE_DIR = os.environ.get("MUMBLE_ICE_SLICE_INCLUDE_DIR", "/usr/share/ice/slice")


@asynccontextmanager
async def lifespan(_: FastAPI):
    yield
    # Cleanly release the Ice communicator (its worker threads/sockets) on
    # shutdown instead of leaking it — it is only ever created lazily, on
    # first use, by `_get_server()`.
    if _ice_communicator is not None:
        _ice_communicator.destroy()


app = FastAPI(title="mumble-admin", description="Ice-REST sidecar for the Mumble server", lifespan=lifespan)
bearer = HTTPBearer(auto_error=False)

_ice_communicator: Optional["Ice.Communicator"] = None
_murmur_module = None


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


def require_token(credentials: HTTPAuthorizationCredentials | None = Security(bearer)) -> None:
    if not ADMIN_TOKEN:
        # No token configured: refuse to serve rather than run open (LAN-internal
        # secret is mandatory per the compose/.env.example contract).
        raise HTTPException(status_code=503, detail="MUMBLE_ADMIN_TOKEN/MUMBLE_ICE_SECRET not configured")
    if credentials is None or credentials.credentials != ADMIN_TOKEN:
        raise HTTPException(status_code=401, detail="invalid or missing bearer token")


def _map_ice_errors(func):
    """Translate Murmur/Ice exceptions into sane HTTP statuses.

    Without this, `Murmur.InvalidChannelException` (e.g. deleting an
    already-removed channel — routine once Tasks 20/21 land) or
    `Murmur.InvalidSecretException` (Ice auth misconfigured on our side)
    would propagate out of FastAPI as an unhandled 500 with a raw stack
    trace. Applied to every endpoint (and `_get_server`, which all of them
    call) so the mapping is consistent everywhere Ice is touched.
    """

    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        murmur = _load_murmur_module()
        try:
            return func(*args, **kwargs)
        except HTTPException:
            # Already a deliberate HTTP response (e.g. require_token, or a
            # sane status raised inside `_get_server`) — pass through as-is.
            raise
        except murmur.InvalidChannelException as exc:
            raise HTTPException(status_code=404, detail="unknown channel id") from exc
        except murmur.InvalidSecretException as exc:
            # Ice-level auth failure between this sidecar and Murmur (wrong
            # MUMBLE_ICE_SECRET / icesecretwrite mismatch) — a server-side
            # misconfiguration, distinct from the client's bearer token
            # (already rejected earlier by `require_token`).
            raise HTTPException(status_code=502, detail="Ice authentication with Mumble server failed") from exc
        except Ice.Exception as exc:
            raise HTTPException(status_code=502, detail=f"Mumble Ice call failed: {exc}") from exc

    return wrapper


def _load_murmur_module():
    global _murmur_module
    if _murmur_module is None:
        Ice.loadSlice(f"-I{SLICE_INCLUDE_DIR} {SLICE_FILE}")
        import Murmur  # type: ignore[import-not-found]  # noqa: PLC0415 (generated at runtime by loadSlice)

        _murmur_module = Murmur
    return _murmur_module


def _get_server():
    """Return a live proxy to the configured Murmur virtual server."""
    global _ice_communicator
    murmur = _load_murmur_module()
    if _ice_communicator is None:
        _ice_communicator = Ice.initialize(sys.argv)
    proxy = _ice_communicator.stringToProxy(f"Meta:tcp -h {ICE_HOST} -p {ICE_PORT}")
    meta = murmur.MetaPrx.checkedCast(proxy)
    if meta is None:
        raise HTTPException(status_code=502, detail="could not reach Mumble Ice Meta interface")
    context = {"secret": ICE_SECRET} if ICE_SECRET else {}
    server = meta.getServer(SERVER_ID, context)
    if server is None:
        raise HTTPException(status_code=502, detail=f"Mumble server {SERVER_ID} does not exist")
    return server, context


@app.get("/channels", response_model=list[ChannelOut])
@_map_ice_errors
def list_channels(_: None = Depends(require_token)) -> list[ChannelOut]:
    server, context = _get_server()
    channels = server.getChannels(context)
    return [
        ChannelOut(id=c.id, name=c.name, parent=c.parent, temporary=c.temporary)
        for c in channels.values()
    ]


@app.post("/channels", response_model=ChannelOut, status_code=201)
@_map_ice_errors
def create_channel(payload: ChannelIn, _: None = Depends(require_token)) -> ChannelOut:
    # NOTE on `temporary`: verified against a live server (docker compose up)
    # that Murmur's Ice API does not support flipping a channel to temporary
    # after creation — `setChannelState(temporary=True)` is silently ignored
    # server-side; the server only ever marks a channel temporary itself,
    # when a *client* creates it on the fly (and auto-removes it once empty).
    # There is no Ice call to create a temporary channel directly. So this
    # field is accepted for forward-compatibility with the `MumbleClient`
    # contract (Task 20) but currently has no effect; callers that need
    # auto-cleanup must delete the channel themselves (e.g. via
    # `MatchCompleted`/`TournamentCompleted` cleanup jobs, task 3.21).
    server, context = _get_server()
    channel_id = server.addChannel(payload.name, payload.parent, context)
    state = server.getChannelState(channel_id, context)
    return ChannelOut(id=state.id, name=state.name, parent=state.parent, temporary=state.temporary)


@app.patch("/channels/{channel_id}", response_model=ChannelOut)
@_map_ice_errors
def rename_channel(channel_id: int, payload: ChannelPatch, _: None = Depends(require_token)) -> ChannelOut:
    server, context = _get_server()
    state = server.getChannelState(channel_id, context)
    state.name = payload.name
    server.setChannelState(state, context)
    return ChannelOut(id=state.id, name=state.name, parent=state.parent, temporary=state.temporary)


@app.delete("/channels/{channel_id}", status_code=204)
@_map_ice_errors
def delete_channel(channel_id: int, _: None = Depends(require_token)) -> None:
    server, context = _get_server()
    server.removeChannel(channel_id, context)


@app.get("/healthz")
def healthz() -> dict[str, str]:
    return {"status": "ok"}
