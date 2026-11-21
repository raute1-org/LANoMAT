# Arcade terminals (launcher fleet)

The LANoMAT launcher turns any PC into a gaming terminal: a keyboard-and-mouse cabinet, a
gamepad couch box, a borrowed laptop. Terminals pair like an agent (the MCP device flow, see
[mcp-ops.md](mcp-ops.md)), report in over a heartbeat, and appear in one orga monitor — so the
desk can see at a glance which cabinets are up, what they are playing and what they have
installed.

Part of the `GameRecipes` module (the launcher's download page and install plans live there).
When the module is off, none of these routes exist. `MODULES_PROFILE=core` does not include it.

Placeholders below: `<app>` is the public https origin of LANoMAT, `<token>` a launcher access
token.

## How a terminal pairs

1. The launcher runs `POST <app>/api/mcp/device` and shows the returned `user_code`.
2. Someone with an orga/participant account opens `<app>/mcp/connect`, compares the code and the
   device name, and approves it (**Verbinden**). The approver's role is what a later
   `GET /api/v1/terminals` check sees — a terminal paired by a participant reports, but only an
   orga or admin can read the fleet list.
3. The launcher polls `POST <app>/api/mcp/token` and receives its bearer token (90 days, slid
   forward on use). It stores the token locally and never shows it again.
4. Every heartbeat now lands on **one** row: the token is the identity (`launcher_terminals.mcp_token_id`,
   unique). The same launcher restarts into the same row; a revoked token and a fresh pairing
   create a new one.

No user id is ever sent by the launcher. The server resolves the user from the token.

## Heartbeat contract

### `POST /api/v1/terminals/heartbeat`

Bearer token required. Send it every **30 seconds** while the launcher runs; 30 requests/minute
per token are allowed (a reconnect burst fits). JSON body:

| Field | Required | Type | Notes |
|-------|----------|------|-------|
| `name` | yes | string, max 60 | The visible terminal name, e.g. `Kabinett 7`. Control characters are stripped, whitespace collapsed. |
| `os` | no | `windows` \| `linux` \| `macos` | |
| `arch` | no | string, max 16 | `x86_64`, `aarch64`, … |
| `version` | no | string, max 32 | Launcher version, e.g. `1.4.2`. |
| `state` | no | `online` \| `playing` \| `idle` \| `offline` | What the launcher is doing. Omitted = `online`. `offline` is the goodbye of a clean shutdown. |
| `current_game_slug` | no | recipe slug | Set while `state=playing`, or while an install is running (the game being installed); `null` when idle. |
| `installed` | no | list of recipe slugs, max 500 | Normalised to lower case and deduplicated by the server. |
| `gamepad` | no | boolean | A gamepad is connected on this machine. |

```json
{
  "name": "Kabinett 7",
  "os": "windows",
  "arch": "x86_64",
  "version": "1.4.2",
  "state": "playing",
  "current_game_slug": "quake-live",
  "installed": ["quake-live", "unreal-tournament-2004"],
  "gamepad": true
}
```

Success (200):

```json
{ "ok": true, "terminal_id": 12, "server_time": "2026-11-21T18:04:30+00:00" }
```

`server_time` is the launcher's clock reference; `terminal_id` stays stable for this token.

Errors:

| Status | Meaning |
|--------|---------|
| 401 | missing, unknown, revoked or expired token — re-pair |
| 422 | invalid payload; `errors` names the field (log it, keep the old state) |
| 429 | more than 30 heartbeats in a minute — back off, keep the 30 s cadence |

`last_seen_at` and `ip` are **server facts**: a launcher cannot send them, and a future timestamp
does not make it look younger. `last_seen_at` is the heartbeat arrival time, `ip` the connection
address as seen by the app (behind Traefik, the forwarded client address).

### Being offline

The server never trusts a reported state alone. A terminal counts as online while its last
heartbeat is at most **90 seconds** old (`LauncherTerminal::OFFLINE_AFTER_SECONDS`, three missed
beats). After that the monitor shows it as offline — a cabinet that was unplugged mid-game never
gets to say goodbye. The stored `state` stays what was last reported, so "it was playing
quake-live when it vanished" remains visible.

Heartbeat every 30 s. Do not heartbeat faster than every 10 s; do not stop heartbeating while a
game runs (a paused launcher looks dead).

## The fleet list

### `GET /api/v1/terminals`

Bearer token of an **orga or admin**; participants and helpers get `403`.
Returns all terminals, online first, then by name:

```json
{
  "data": [
    {
      "id": 12,
      "name": "Kabinett 7",
      "state": "playing",
      "online": true,
      "os": "windows",
      "arch": "x86_64",
      "version": "1.4.2",
      "current_game_slug": "quake-live",
      "installed": ["quake-live", "unreal-tournament-2004"],
      "installed_count": 2,
      "gamepad": true,
      "last_seen_at": "2026-11-21T18:04:30+00:00",
      "ip": "192.0.2.42",
      "paired_user": { "id": 7, "name": "Ada" },
      "token_name": "Kabinett 7"
    }
  ],
  "heartbeat_seconds": 30,
  "offline_after_seconds": 90,
  "server_time": "2026-11-21T18:04:31+00:00"
}
```

`state` is the derived state (stale heartbeat = `offline`, whatever was reported). `paired_user`
and `token_name` are the only user data — no e-mail, no Discord id, no role.

The same data is the admin monitor at `<app>/admin/launcher-terminals` (orga only, read-only,
refreshes every 15 s, delete action for offline rows).

## What a launcher must implement

1. Device pairing against `/api/mcp/device` + `/api/mcp/token`, storing the bearer token.
2. A 30 s heartbeat timer to `POST /api/v1/terminals/heartbeat` with the payload above, including
   `state` transitions (`idle` → `playing` when a game starts, back to `idle` when it exits).
3. On a clean shutdown, one final heartbeat with `state: "offline"`.
4. The recipe list installed on the machine, re-sent whenever it changes (the server keeps the
   last list, no deltas).
5. Survive 422/429/5xx: log, keep the previous state, retry on the normal tick. Only a 401 means
   "re-pair".

A per-terminal command channel (`POST /api/v1/terminals/{id}/command`, e.g. "restart launcher")
is deliberately not part of this pilot: the monitor is read-only and the heartbeat is the only
writer.

## Setting up a terminal

Two scripts turn a fresh PC into a terminal. Both are idempotent, need no administrator rights,
write no secrets and only touch the current user's profile:

```bash
# Linux, from the unpacked launcher tarball (or next to the binary)
./install-terminal.sh lanomat-launcher-<version>-linux-x86_64.tar.gz --arcade --name "Kabinett 7"
./install-terminal.sh lanomat-launcher --kiosk --autostart=systemd   # locked fullscreen
./install-terminal.sh --uninstall
```

```powershell
# Windows
powershell -ExecutionPolicy Bypass -File .\install-terminal.ps1 -Arcade -Name "Kabinett 7"
powershell -ExecutionPolicy Bypass -File .\install-terminal.ps1 .\lanomat-launcher.zip -Kiosk
powershell -ExecutionPolicy Bypass -File .\install-terminal.ps1 -Uninstall
```

`install-terminal.sh` installs through the sibling `install-desktop.sh` (binary in
`~/.local/bin`, desktop entry, icon, `lanomat://` handler) and then adds the terminal part:
`~/.config/autostart/lanomat-launcher.desktop` (or, with `--autostart=systemd`, a `--user` unit
wanted by `graphical-session.target`). `--arcade` starts the arcade window, `--kiosk` locks the
fullscreen (Escape cannot leave it), `--no-autostart` skips the boot start. The Windows script
copies the exe to `%LOCALAPPDATA%\Programs\LANoMAT Launcher`, writes the Start Menu shortcut
(with `--arcade`) and the `HKCU\...\Run` value.

`--name` / `-Name` exports `LANOMAT_TERMINAL_NAME` in the autostart command (on Windows: in a
small `.cmd` shim the shortcut and the Run value start), which is exactly the `name` the heartbeat
sends. Without it the terminal reports its hostname. Nothing else has to be configured per
cabinet: pairing is the device flow above, and it survives reboots (the token is in the launcher
config).

What the launcher then sends, and when:

- every 30 s (`LauncherTerminal::HEARTBEAT_SECONDS`), plus immediately after pairing, on install
  start and finish, on launch, and on logout (one `state: "offline"` goodbye, also on a clean
  window close);
- `state` is `playing` while the launched game's process runs (the launcher checks the
  executable path each tick and drops back to `idle` when it is gone), `idle` during an install
  with `current_game_slug` naming the game being installed, `offline` only for the goodbye;
- `installed` is the launcher's own `installs.json` ledger, re-sent on every beat;
- `gamepad` follows the UI's Gamepad API state (`set_gamepad`).

A failing heartbeat is cosmetic: it is logged at debug and retried on the next tick (or the next
state change). Only a 401 stops it, because the pairing is then gone.

The fleet is visible at `<app>/admin/launcher-terminals` (orga only, refreshes every 15 s) and as
`GET <app>/api/v1/terminals` for tooling. Both read `state`, `last_seen_at` and the 90 s offline
derivation described above; the terminal opens no inbound port and the server needs no extra
service for the monitor.

## Security notes

- The token is the only identity; it belongs to the user who approved the pairing. Revoking it
  under `/mcp/connect` stops the heartbeat immediately (401) and detaches the row
  (`mcp_token_id` becomes `null`, the row survives for the monitor's history).
- The list is orga-only (`LauncherTerminalPolicy::viewAny`). Participants never see other
  terminals, their IPs or who paired them.
- The heartbeat can only write its own row — there is no terminal id in the request, so a
  terminal cannot overwrite another one.
- Rate limit: `terminal-heartbeat`, 30/minute per token digest + client IP (same shape as
  `mcp-api`).
