# Game servers via Pelican

The optional `GameServers` module provisions one Pelican game server per match and
hands the join details to the match page, the Discord embed and the infoscreens.
LANoMAT talks to Pelican only through the `PelicanClient` contract
(`app/Modules/GameServers/Contracts/PelicanClient.php`); `HttpPelicanClient` is the
real implementation, `FakePelicanClient` the test double. The module is off in the
`core` profile (`MODULE_GAME_SERVERS`, see `config/modules.php`).

## Two APIs, two token types

Pelican splits its REST API in two, with a different key type for each:

| API | Path | Key prefix | Used for |
| --- | ---- | ---------- | -------- |
| Application | `/api/application/*` | `papp_` | create / show / list / delete servers, nodes, allocations, eggs |
| Client | `/api/client/*` | `pacc_` | power signals, runtime state (`/resources`), "which servers can this key see" |

(`ptla_` / `ptlc_` are Pterodactyl prefixes and no longer apply.)

**Application key.** Create it in the panel admin area (API keys). Give it read/write
on *Servers*, read on *Nodes* and *Eggs*, and read on *Allocations* — scopes are
enforced per resource, a key without the right scope gets `403`.

**Client key.** Create it as the *service user's* account API key. The Client API only
exposes servers the key's user owns or has been added to; anything else is a `404`.
That is why LANoMAT creates every server with `PELICAN_OWNER_USER_ID` as the owner:
it must be the user the client key belongs to.

**Use a non-admin service user for the client key.** A root admin's client key is also
accepted on `/api/application/*` endpoints, so a leaked client key from an admin account
would be a full panel takeover. A plain user that owns the LANoMAT servers can start
and stop them and nothing else.

## Environment

| Variable | Meaning |
| -------- | ------- |
| `PELICAN_PANEL_URL` (alias `PELICAN_URL`) | Panel base URL, no trailing path |
| `PELICAN_APPLICATION_TOKEN` | Application key (`papp_...`) |
| `PELICAN_CLIENT_TOKEN` | Client key (`pacc_...`) of the non-admin service user |
| `PELICAN_OWNER_USER_ID` | Panel id of that service user (owner of created servers) |
| `PELICAN_DEFAULT_NODE_ID` (legacy `PELICAN_NODE_ID`) | Node new servers are placed on |
| `PELICAN_TIMEOUT`, `PELICAN_CONNECT_TIMEOUT` | Seconds, default 10 and 5 |
| `PELICAN_DEFAULT_DISK_MB`, `PELICAN_DEFAULT_CPU_PERCENT` | Limits for presets that set none (5120 / 0) |
| `PELICAN_MAX_RAM_MB`, `PELICAN_MAX_SLOTS`, `PELICAN_MAX_SERVERS_PER_USER`, `PELICAN_MAX_RUNNING_SERVERS` | Guardrails (unchanged) |

Migration note: `PELICAN_PANEL_URL`, `PELICAN_APPLICATION_TOKEN`, `PELICAN_CLIENT_TOKEN`
and `PELICAN_NODE_ID` keep working. New: `PELICAN_OWNER_USER_ID` is **required** for
provisioning. If URL or a token is missing the client throws a `GameServerException`
(German message in the admin UI) instead of calling out, so an unconfigured module
degrades gracefully.

## What a preset has to carry

Pelican refuses a server without `name`, `user`, `egg`, `environment`, `limits`
(`memory`, `swap`, `disk`, `io`, `cpu`), `feature_limits` (`databases`, `backups`) and an
`allocation.default`. LANoMAT builds that from the game's effective config
(`ServerSpecBuilder`):

- **environment** — the egg's own variable defaults (so `required` egg variables never
  422), then `max_players` / `map` / `password` mapped onto `MAX_PLAYERS` / `SRCDS_MAP` /
  `SERVER_PASSWORD` *only if the egg declares that variable*, then the config's explicit
  `environment` map, which always wins.
- **limits** — memory is `memory_mb` if set, else the slot-based estimate (the RAM
  guardrail judges exactly this number); disk and cpu from `disk_mb` / `cpu_percent` or
  the `PELICAN_DEFAULT_*` fallbacks.
- **allocation** — the first free allocation on the default node.
- **owner** — `PELICAN_OWNER_USER_ID`.

`memory_mb`, `disk_mb`, `cpu_percent` and `environment` live in the game's
`default_server_config` / preset JSON (`ServerConfig`). They are not yet editable in
the Filament form; set them through the config upload ("Upload" mode of the default
config) or a seeder.

## Provisioning flow and states

1. `ProvisionMatchServerJob` creates the server (`POST /api/application/servers`,
   `start_on_completion: true`) and stores both the numeric id (`pelican_server_id`,
   Application API) and the short `pelican_server_identifier` (Client API).
2. `PollServerStatusJob` calls `getServer()`, which merges two sources: while the panel
   reports `installing` / `install_failed` / `suspended` that status wins; once the
   Application API `status` is `null` (installed) the real state comes from the Client API
   `GET /api/client/servers/{identifier}/resources` (`running`, `offline`, `starting`,
   `stopping`). A `409` from the Client API means "still installing".
3. `running` flips the link to Ready and publishes the join info (allocation `alias ?? ip`
   and port).

Power actions (Filament server links) address the server by identifier; legacy links
without one get it resolved once and stored.

## Network reachability

LANoMAT only needs to reach the **panel** over HTTP(S); it never talks to Wings
directly. The panel in turn talks to Wings (its node daemon) and Wings must be able to
reach the panel back (`/api/remote/*` for config and install callbacks). When the panel
and the nodes sit in different networks — for example LANoMAT in the cloud and the
game node at the LAN — connect them with a VPN tunnel (a point-to-point WireGuard link
is enough) and use tunnel addresses as the panel URL and the node FQDN. Players connect
to the allocation's address and port on the node; the allocation `alias` can carry the
public name or LAN address players should use when it differs from the node's own IP.
Allow only the tunnel and the game ports through the firewall.

## Live contract check

`scripts/ops/pelican-contract-check.sh` (an artisan command, `gameservers:pelican-check`,
underneath) is a smoke test against a real panel. It is **not** part of CI and reads
everything from the environment; tokens are never printed.

```bash
export PELICAN_PANEL_URL=...  PELICAN_APPLICATION_TOKEN=...  PELICAN_CLIENT_TOKEN=...
scripts/ops/pelican-contract-check.sh                        # read-only
scripts/ops/pelican-contract-check.sh --lifecycle --egg=<id> --owner=<user id>
```

Read-only calls: nodes, allocations of the node, eggs, servers, the client key itself
(`GET /api/client`), and — when a server exists — its resources and merged details. Each
prints `PASS` / `FAIL` and the exit code is non-zero on any failure. `--lifecycle`
additionally creates one small throwaway server through the same `createServer()` path
LANoMAT uses, starts it, checks that `/resources` reports a state, stops it, deletes it
and verifies the panel is clean again.

## Verified against a live panel

The client was validated against a real panel with Wings 1.0.0-rc.1. Where the real API
differs from older docs the client follows the real API:

- `attributes.allocation` on a server is an integer id; ip and port come from the
  allocation resource (`?include=allocations` on the server, or the node's allocations).
- The Application API `status` is `null` once a server is installed, even if it is
  stopped or crashed.
- `/api/application/eggs` returns a plain list **without** `meta.pagination`; nodes and
  servers are paginated. The client treats a missing `meta` as a single page.
- Errors arrive as `{"errors":[{"code","status","detail","meta"}]}`; a `422` lists every
  missing field, which `GameServerException::pelicanRejected()` carries as `details`.
- Create is never retried (a replayed `POST` could create a second server); reads, power
  signals and deletes retry on connection errors, `429` and `5xx`. Deleting an already
  deleted server counts as success so retried cleanup stays idempotent.
- `start_on_completion: true` on create is honoured: a server started by the panel after
  the install shows `starting` on `/resources` with no power signal from LANoMAT.
- Minecraft eggs (Paper, Vanilla) have no `EULA` environment variable; the egg only
  carries a panel-side `eula` feature. A throwaway Minecraft server therefore goes
  `starting` and then `offline` within seconds — the contract check reports that
  transition as a PASS, because it proves create, install, start, `/resources` and
  stop, not that the game boots. Accept the EULA once in the panel (or write
  `eula.txt`) for a server meant to stay up. Real match games (CS2 etc.) are unaffected.
- The `required` variables differ per egg (Paper wants `BUILD_NUMBER`, `SERVER_JARFILE`;
  Vanilla `VANILLA_VERSION`, `SERVER_JARFILE`), which is why the builder starts from the
  egg's defaults.
- Allocations: two concurrent provisions can pick the same free allocation; the panel then
  answers `422` and the second job is marked Failed (manual fallback), it never
  double-books.
