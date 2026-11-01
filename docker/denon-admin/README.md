# denon-admin

A small FastAPI sidecar that keeps one telnet session to the venue's Denon
AV receiver and exposes a bearer-authenticated REST API for LANoMAT's room
audio module (orga only). LANoMAT never speaks telnet itself.

The protocol and state parsing are ported from the owner's Omarchy plugin
`kleintonno.denon` (`scripts/denon_bridge.py`). Zone 2 is left out, and the
widget-only relative commands (`up`, `down`, `toggle`) are not offered.

## Configuration

| Variable             | Default | Meaning                                                             |
| -------------------- | ------- | ------------------------------------------------------------------- |
| `DENON_HOST`         | *(none)* | Receiver address on the venue LAN, e.g. `203.0.113.10`. Set only in the venue's `.env`, never in the repository. Empty means the session never starts. |
| `DENON_PORT`         | `23`    | Receiver telnet control port.                                        |
| `DENON_ADMIN_TOKEN`  | *(required)* | Bearer token the REST API requires. Without it the API answers 503. |

## REST contract

All routes except `/healthz` require `Authorization: Bearer <DENON_ADMIN_TOKEN>`
(401 when missing or wrong, constant-time comparison).

| Method | Path        | Body                                  | Response                                                                 |
| ------ | ----------- | ------------------------------------- | ------------------------------------------------------------------------ |
| GET    | `/state`    | –                                     | `{connected, power, mainZone, volume, volumeMax, mute, source, mode, sources, modes}` |
| POST   | `/command`  | `{"command": ..., "value": ...}`      | `200 {"ok": true}`, `422` for an unknown command or value, `503` when not connected |
| GET    | `/healthz`  | –                                     | `200 {"status": "ok", "connected": bool}` (no auth)                      |

Whitelisted commands and their values:

| `command` | `value`                                             | Sent to the receiver |
| --------- | --------------------------------------------------- | -------------------- |
| `power`   | `"on"` or `"off"`                                   | `PWON` / `PWSTANDBY` |
| `volume`  | number `0`..`98` (rounded to 0.5 steps)            | `MV325`, `MV05`, ... |
| `mute`    | `"on"` or `"off"`                                   | `MUON` / `MUOFF`     |
| `source`  | code matching `^[A-Z0-9/]{1,12}$`                   | `SI<code>`           |
| `mode`    | a key of `MODES` (`stereo`, `direct`, `pure`, `auto`, `movie`, `music`, `game`) | the mode's `MS...` command |

Anything else returns `422` and is never forwarded.

## Behaviour

- One telnet session in a background thread. It reconnects every 5 s after a
  drop, and the state reports `connected: false` instead of failing.
- On connect it queries `PW? ZM? MV? MU? SI? MS? SSFUN ?`. It sends `PW?` as a
  heartbeat every 30 s and treats 75 s without any reply as a dead session.
- Commands go out at least 0.08 s apart. Queued volume commands are coalesced,
  so a dragged slider sends only the newest value.
- Receiver text (input names, sound mode) is sanitised: printable, no
  `<>&"'` or backtick, whitespace collapsed, at most 32 characters.

## Running it

```bash
docker compose --profile prod up -d denon-admin
curl -s http://127.0.0.1:8087/healthz
```

The sidecar runs as a non-root user and binds to loopback on the host. The
Laravel app reaches it over the compose network at `http://denon-admin:8000`.

## Unverified until real hardware

This sidecar is built against the protocol as the owner's plugin uses it. It
has not yet been run against the venue's receiver. Open points to verify on
the real device:

- the receiver model and firmware (zone count, the `NET` input name);
- that HEOS input switching on `NET` works while Music Assistant streams;
- the sidecar's network placement. It must reach the receiver's telnet port
  (23) on the venue LAN, which the default compose network may not do; if
  not, run the service with `network_mode: host` as Music Assistant does.
- whether the input list should also query `SSSOD ?`. The plugin does, to
  show only the inputs in use. This sidecar follows the spec's query list
  and merges the default input names instead, so unused inputs may appear.
