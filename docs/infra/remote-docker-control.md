# Remote Docker control of a game server

A permanent server that runs as a Docker container on a managed remote host (a cloud machine
registered in the Hosts module) can be controlled from LANoMAT: start, stop, restart, console
commands (RCON) and logs. Everything goes through the existing `RemoteExecutor` over SSH, there
is no second SSH path.

## Prerequisites

- The machine is registered as a remote host, its host key is pinned and the probe is green.
- The SSH user is in the `docker` group.
- The container ships a console tool LANoMAT knows. Today that is `rcon-cli` (the
  `itzg/minecraft-server` image); RCON itself stays inside the container and is never exposed.
- The server is a permanent server of source `external` without a Pelican link.

## Linking

```bash
php artisan gameservers:link-remote-docker "<SERVER TITLE OR ENTRY ID>" \
  --host=<HOST NAME OR ID> --container=<CONTAINER NAME> [--console=rcon-cli]
```

An admin can do the same on the server settings page ("Mit Docker-Container verknüpfen"). The
command checks the pinned host key and that the container exists (`docker inspect`), refuses a
server that is already linked to Pelican, and prints ids and names only. The server stays
`source=external`; removing the entry never touches the container. Unlinking: delete the host
(the link then falls back to "not controlled") or clear `remote_host_id`.

## Who can do what

`ServerLinkPolicy::configure`: orga and admin. Participants and helpers get 403. The participant
page `/servers` shows an "Server-Steuerung" block only when the server sends `canManage`
(decided server-side per server); the admin panel page `/admin/server-settings` has the same
controls under "Steuerung".

Routes (all behind login, policy `configure`, throttle `remote-console` = 30 per minute and user):

- `POST /servers/{serverLink}/remote/power` with `action` = `start`, `stop` or `restart`
- `POST /servers/{serverLink}/remote/command` with `command`
- `GET /servers/{serverLink}/remote/logs?lines=1..500`

## Safety model

- The container name must match `^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`.
- Every value that reaches the shell goes through `escapeshellarg`. A console command is exactly
  one shell argument (`docker exec <container> rcon-cli '<command>'`), so `;`, `&&`, `$(...)`
  and backticks are plain text for the console tool.
- A command is one line of printable ASCII, 1 to 256 characters, and must not start with `-`
  (the console tool would read it as a flag). ASCII only because `escapeshellarg` drops bytes it
  cannot place in the server locale.
- Logs: 1 to 500 lines. Output is cleaned (valid UTF-8, no ANSI or Minecraft colour codes, no
  control characters) and cut to the last 64 KB.
- `docker inspect` only reads `.State.Status`, never the container environment (it holds the
  RCON password). Remote output and errors are not logged and not forwarded as error text.
- SSH reads time out after 15 s, power commands get 120 s. A timeout counts as a failure.
- Audit: `Log::info('remote_docker.<action>')` with user id and server id; a console command is
  shortened to 80 characters.

## Quick commands (Minecraft)

The console field offers list, tps, save-all, whitelist add/remove, op, deop and say. They only
fill the input and are sent and validated like any typed command.

## Status

The scheduled permanent-server refresh asks Docker for the container state
(`docker inspect`), so a stopped container shows as offline.
