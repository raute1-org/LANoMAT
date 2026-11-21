---
title: Environment
description: The environment variables that matter, grouped by area, with production notes.
sidebar_icon: settings
---

`.env.example` is the canonical list with comments. This page is the map: what each group does and
which values must differ in production.

## App and display

| Variable | Default | Note |
|---|---|---|
| `APP_ENV` | `local` | `production` at a LAN |
| `APP_DEBUG` | `true` | **`false`** in production |
| `APP_URL` | `http://localhost:8000` | The externally reachable origin; QR codes and OAuth redirects use it |
| `APP_DISPLAY_TIMEZONE` | `Europe/Berlin` | Storage stays UTC; all UI formats in this zone |
| `APP_LOCALE` | `de` | German UI copy |
| `MODULES_PROFILE` | `core` | `core` or `full`; see module toggles below |
| `CSP_MODE` | `report-only` | `enforce` after a clean report, or `off` |

## Database, cache and queue

| Variable | Default | Note |
|---|---|---|
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5434` | Host-side dev. In compose: `postgres` / `5432` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `lanomat` | Must match `POSTGRES_*` |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6380` | Host-side dev; compose uses `redis` / `6379` |
| `QUEUE_CONNECTION` | `redis` | The `queue` service runs `queue:work` |
| `CACHE_STORE` | `redis` | |
| `CACHE_LIMITER_STORE` | `database` | Keeps login/check-in working during a Redis outage |
| `SESSION_DRIVER` | `database` | |

## Reverb (websockets)

| Variable | Note |
|---|---|
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | Where the browser connects; `https` + `443` behind TLS |
| `REVERB_ALLOWED_ORIGINS` | **Host name only**, no scheme (`lan.example`). Reverb compares the `Origin` host |
| `REVERB_APP_ID` / `_KEY` / `_SECRET` | Must match the Reverb service |

Reverb refuses a handshake without an `Origin`. A native client must send its server's origin. See
[Deployment](/docs/start/deployment).

## Discord

| Variable | Note |
|---|---|
| `DISCORD_CLIENT_ID` / `_SECRET` / `_REDIRECT_URI` | OAuth login |
| `DISCORD_BOT_TOKEN` | Announcements, DMs, channels |
| `DISCORD_LOGIN_GUILD_ID` | Optional login gate; admins exempt, fail-closed |
| `DISCORD_ANNOUNCE_CHANNEL_ID` | Empty = announcements silently skipped |
| `DISCORD_APPLICATION_ID` | Bot user id; required for per-match channel permissions |
| `DISCORD_PUBLIC_KEY` | Ed25519 fallback interactions endpoint |
| `DISCORD_GATEWAY_BRIDGE_SECRET` | Shared secret for the Gateway sidecar ingress |
| `DISCORD_GATEWAY_INGRESS_URL` | Default `http://app/internal/discord/gateway` |
| `DISCORD_GATEWAY_HEALTH_URL` | Sidecar health; Preflight treats non-200 as down |
| `DISCORD_MATCH_CHANNEL_CLEANUP_DELAY_MINUTES` | Default 30 |

## Voice

| Variable | Note |
|---|---|
| `MUMBLE_ICE_SECRET` | Ice secret + sidecar bearer token |
| `MUMBLE_SERVER_PASSWORD` | Join password shown to orga |
| `MUMBLE_ADMIN_REST_URL` | Default `http://mumble-admin:8000` |
| `MUMBLE_HOST` / `MUMBLE_PORT` | Public join address (default 64738) |
| `MUMBLE_SSL_CERT` / `_KEY` | Optional trusted TLS instead of Mumble's self-signed cert |
| `TEAMSPEAK_QUERY_PASSWORD` | ServerQuery account password |
| `TEAMSPEAK_ADMIN_TOKEN` | Sidecar bearer token |
| `TEAMSPEAK_ADMIN_REST_URL` | Default `http://teamspeak-admin:8000` |
| `TEAMSPEAK_HOST` / `TEAMSPEAK_PORT` | Public join address (default 9987) |

## Web voice (LiveKit)

| Variable | Note |
|---|---|
| `MODULE_WEB_VOICE` | Enable the module (or `MODULES_PROFILE=full`) |
| `LIVEKIT_URL` | Browser-facing `wss://` on a **trusted** certificate; the microphone needs a secure context |
| `LIVEKIT_API_URL` | Internal `http://livekit:7880` |
| `LIVEKIT_API_KEY` / `_SECRET` | Must match `docker/livekit/livekit.yaml`; secret ≥ 32 chars |
| `LIVEKIT_NODE_IP` / `LIVEKIT_DOMAIN` | Used by the render script / compose |
| `VOICE_CLIENT_MIN_VERSION` | Refuse older native clients (empty = no minimum) |

These values surface as the Sprachchat page and the desktop-client handshake; a missing `wss://` or a
key mismatch shows up as an empty channel list, not as a server error.

| Dark | Light |
|---|---|
| ![Sprachchat web voice page with no channels before a match, dark](/screens/talk-dark.png) | ![Sprachchat web voice page with no channels before a match, light](/screens/talk-light.png) |
| `SPRACHCHAT · EMPTY · LIVEKIT` | `SPRACHCHAT · LIGHT` |

## Pelican and game servers

| Variable | Note |
|---|---|
| `PELICAN_PANEL_URL` | Panel base URL (`PELICAN_URL` is an alias) |
| `PELICAN_APPLICATION_TOKEN` | `papp_…`, create/show/delete servers, nodes, allocations, eggs |
| `PELICAN_CLIENT_TOKEN` | `pacc_…`, power and runtime state; must belong to a non-admin service user |
| `PELICAN_OWNER_USER_ID` | Panel user that owns every created server |
| `PELICAN_DEFAULT_NODE_ID` | Node for new servers (`PELICAN_NODE_ID` alias) |
| `PELICAN_PORT_POOL` | Default `27015-27050` |
| `PELICAN_MAX_RUNNING_SERVERS` | Optional node-wide cap; empty = unlimited |
| `PELICAN_STEAM_WEB_API_KEY` / `PELICAN_GSLT` | Secrets for presets that need them |

## Files and gallery

| Variable | Note |
|---|---|
| `FILES_STORE` | `local` (default) or `host` |
| `FILES_HOST_ID` | The `RemoteHost` id for the file host |
| `FILES_HOST_URL` | `https://files.<domain>:8443`; LAN-only |
| `FILES_HOST_SECRET` | ≥ 32 chars, same as nginx's |
| `FILES_TUS_URL` | Enables resumable tus uploads through tusd |
| `FILES_TUS_HOOK_SECRET` | ≥ 32 chars, same as tusd/nginx |
| `FILES_PER_USER_QUOTA_MB` | Default 100 GB per person per event |
| `FILES_MAX_FILE_GB` | Default 50 GB; keep equal to tusd `-max-size` |
| `FILES_URL_TTL_SECONDS` | Signed download lifetime, default 300 |
| `GALLERY_MAX_UPLOAD_MB` / `_MAX_EDGE` / `_THUMB_WIDTH` / `_JPEG_QUALITY` | Upload pipeline |
| `IMAGE_DRIVER` | Leave empty for GD; GD's re-encode strips EXIF/GPS |

The admin file table is where the quota, store and signed-download settings become visible: every row
shows size, approval status and the storage target.

| Dark | Light |
|---|---|
| ![Filament file table with approved ISOs, patches and avatars per event, dark](/screens/admin-shared-files-dark.png) | ![Filament file table with approved ISOs, patches and avatars per event, light](/screens/admin-shared-files-light.png) |
| `ADMIN · DATEIEN · FREIGEGEBEN · STORE` | `ADMIN · LIGHT` |

## Music, room audio, provisioning

| Variable | Note |
|---|---|
| `MUSIC_ASSISTANT_URL` / `_TOKEN` / `_PLAYER_ID` | Jukebox backend |
| `DENON_ADMIN_REST_URL` / `DENON_ADMIN_TOKEN` | Room audio sidecar; token must match |
| `PROVISIONING_DRIVER` | `proxmox` or `fake`; the token is registered via command, not env |
| `LANCACHE_IMAGE` / `_UPSTREAM_DNS` / `_CACHE_VOLUME` | LanCache bootstrap defaults |

## Deploy and Traefik

| Variable | Note |
|---|---|
| `APP_DOMAIN` | Public host routed to `app`; `ws.${APP_DOMAIN}` is derived |
| `ACME_EMAIL` | Let's Encrypt account email |
| `TRAEFIK_STATIC_FILE` | Venue variant of the static Traefik config |
| `VENUE_UPSTREAM` | Cloud `venue-entry` target address |
| `BACKUP_MARKER_PATH` | Read by the Preflight backup check |

## Module toggles

```dotenv
MODULES_PROFILE=core          # or full
MODULE_JUKEBOX=true           # per-module override
MODULE_CUSTOM_SERVERS=false
MODULE_MCP=true
```

Empty (`MODULE_FOO=`) counts as unset, not as a disable. `php artisan lanomat:modules` prints the
result. A module whose `requires()` is off fails boot.

## MCP server

| Variable | Default | Note |
|---|---|---|
| `MCP_CODE_TTL` | `600` | Device-code lifetime in seconds |
| `MCP_POLL_INTERVAL` | `5` | Minimum seconds between token exchanges |
| `MCP_TOKEN_TTL_DAYS` | `90` | Bearer token lifetime; slid on use |
| `MCP_DEFAULT_MODEL` | `venice/e2ee-deepseek-v4-1-flash-1017-abliterated` | Advertised by `lanomat_me` for the agent |

The code lifetime and poll interval govern the pairing page below; the token TTL governs the entries
under "Verbundene Geräte" until they are disconnected.

| Dark | Light |
|---|---|
| ![Agent and MCP pairing page with a code field and two connected agents, dark](/screens/mcp-connect-dark.png) | ![Agent and MCP pairing page with a code field and two connected agents, light](/screens/mcp-connect-light.png) |
| `AGENT & MCP · CODE · CONNECTED AGENTS` | `AGENT & MCP · LIGHT` |

## Game recipes

| Variable | Default | Note |
|---|---|---|
| `MODULE_GAME_RECIPES` | profile | Enable the module (`GameRecipes` requires `Files` and `Mcp`) |
| `RECIPES_SOURCE` | `resources/recipes` | Directory, `smb://host/share[/path]` or `git+https://…`; used by the daily sync |
| `RECIPES_SMB_USER` / `RECIPES_SMB_PASSWORD` | empty | Optional SMB credentials; keep the file `0600` |
| `RECIPES_PREFER_LAN` | `true` | LAN copies first; external URLs only with the agent's `--allow-external` |
| `LAUNCHER_DIST_PATH` | `clients-dist/launcher` | Where the published launcher builds and `manifest.json` live; read by `/launcher` and the Preflight `launcher_build` check |
| `LAUNCHER_LINEUP` | `unreal-tournament-2004,need-for-speed-underground-2,need-for-speed-underground-2-browser` | Comma-separated recipe allowlist for the launcher catalogue, `/games`, the home chat cards, MCP `recipes_list` and the install link next to a server; the lineup players see is this list **plus** every recipe with `game_recipes.in_lineup = true` (the panel toggle **In der Spieleliste zeigen**). Empty = unrestricted: the whole visible catalogue is shown and the flag changes nothing |

Malformed entries in `LAUNCHER_LINEUP` are ignored, so a typo never hides the catalogue. The flag is
the only way a shared game reaches the players without a deploy: publishing a contributed recipe in
the panel sets it (`LauncherLineup::slugs()`).

### Recipe pull requests

`config/recipes.php` → `pull_requests`. Off by default. When enabled, LANoMAT proposes a recipe's text
to the git catalogue as one pull request per recipe (branch `recipes/<slug>` by default); game files
stay on the LAN file host. The token is a fine-grained GitHub token with **Contents** and **Pull
requests** read and write on exactly that repository.

| Variable | Default | Note |
|---|---|---|
| `RECIPES_PR_ENABLED` | `false` | Master switch. Off = no proposal is ever sent, and `lanomat:recipes:pr-status` does nothing |
| `RECIPES_PR_REPOSITORY` | empty | `owner/name`; `.env.example` uses `raute1-org/LANoMAT` |
| `RECIPES_PR_TOKEN` | empty | Fine-grained token, scoped to that one repository |
| `RECIPES_PR_BASE` | `main` | Base branch the pull request targets |
| `RECIPES_PR_BRANCH_PREFIX` | `recipes/` | One branch per recipe: `recipes/<slug>`; a later proposal moves the same branch |
| `RECIPES_PR_PATH` | `resources/recipes` | Catalogue path inside the repository (`<path>/<slug>/recipe.yaml`, `<slug>/SKILL.md`, `_catalog.md`) |
| `RECIPES_PR_TRIGGER` | `contribute` | `contribute` opens the pull request as soon as a participant shares; `publish` waits for the orga. With the switch on, publishing a contributed or panel recipe always opens or updates it |
| `RECIPES_PR_ATTRIBUTION` | `false` | Adds the contributor's display name to the pull request body. Leave it off for a public repository |
| `RECIPES_PR_API_URL` | `https://api.github.com` | GitHub REST base; set it for GitHub Enterprise |

`RECIPES_PR_ENABLED`, `RECIPES_PR_REPOSITORY` and `RECIPES_PR_TOKEN` must all be there: enabled
without them, the Preflight `recipe_repository` check warns and every shared recipe stays a local
draft. See [Preflight](/docs/system/modules/preflight) and
[Contributing](/docs/recipes/contributing).

## Agent and model variables (client side)

These are **not** read by the LANoMAT server; they configure the pi agent on a participant's PC:

| Variable | Note |
|---|---|
| `VENICE_API_KEY` | Venice key; never stored by LANoMAT |
| `LANOMAT_AGENT_MODEL` | Full `provider/model`, e.g. `venice/e2ee-deepseek-v4-1-flash-1017-abliterated` |
| `OPENAI_MODEL_BASE_URL` / `_API_KEY` / `_NAME` / `_PROVIDER` | Generic OpenAI-compatible endpoint |
| `LANOMAT_TOKEN` | Paired MCP bearer token |

See [Models & fuel](/docs/agent/venice) for precedence.

## Runbooks for the values above

The repository's infrastructure docs go deeper than an env table:

| Area | Runbook |
|---|---|
| Production deploy, TLS, backups | [docs/deploy-lan-raute1.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/deploy-lan-raute1.md) |
| File host (SFTP, tusd, nginx) | [docs/infra/file-host.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/infra/file-host.md) |
| LiveKit web voice | [docs/infra/livekit-web-voice.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/infra/livekit-web-voice.md) |
| Native client API | [docs/infra/native-voice-client-api.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/infra/native-voice-client-api.md) |
| Venue network | [docs/infra/venue-network-crs354.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/infra/venue-network-crs354.md) |
| Pelican and Wings | [docs/gameservers-pelican.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/gameservers-pelican.md) |
| LanCache setup | [docs/lancache-setup.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/lancache-setup.md) |
| Room audio (Denon) | [docs/infra/room-audio-denon.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/infra/room-audio-denon.md) |

<div class="callout-live">
  <strong>Production minimum.</strong> <code>APP_ENV=production</code>,
  <code>APP_DEBUG=false</code>, a real <code>APP_URL</code>, a strong <code>DB_PASSWORD</code>,
  <code>REVERB_ALLOWED_ORIGINS</code> as a host name, and a backup marker outside the container.
</div>
