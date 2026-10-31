# Music Assistant — Jukebox backend setup (M11, LAN-Radio/Jukebox)

> **Mode A — unverified until real hardware.** Everything below (the
> `music-assistant` compose service, the HTTP/JSON-RPC command envelope
> `HttpMusicClient` sends, and the "sync-implies-play" assumption the
> jukebox tick relies on) is code + config + docs written this phase, but
> has **not** been run against a real Music Assistant server. `HttpMusicClient`
> itself is fully implemented and unit-tested (via `Http::fake()`, never a
> real MA call), but the wire format needs a real-verify pass against a
> running server's `:8095/api-docs` before relying on it in production —
> see the checklist at the end of this document.

Music Assistant (MA) is the backend `MusicClient` talks to
(`app/Modules/Jukebox/MusicAssistant/HttpMusicClient.php`). MA is the
"tone source": it abstracts 50+ streaming/local sources (Spotify, Apple
Music, YouTube Music, Tidal, local files, Subsonic, radio, …) and drives
LAN-capable players (Snapcast, Chromecast, AirPlay, DLNA, Squeezelite,
…) behind a single API that owns a real, reorderable queue. LANoMAT never
touches audio bytes — it is the voting/remote-control layer in front of MA
(see `docs/architecture.md`'s "Jukebox / LAN-Radio" section for the module
design).

## Why a real backend instead of our own player logic

Earlier planning considered a direct go-librespot + raw Spotify Web API
path, which would have required LANoMAT to own queue management itself
(Spotify's own queue isn't reorderable, so that path can only ever push
the *next* single track). Music Assistant already owns a proper,
reorderable queue and supports many players and sources, so LANoMAT's job
shrinks to one operation: mirror its vote-ordered queue into MA's queue via
`syncQueue()`. See "Fallback: go-librespot + Spotify Web API" below for the
alternative path this replaces, kept as a documented non-default option.

## 1. Compose service (prod profile only)

Like `teamspeak`/`teamspeak-admin` (see `docs/teamspeak-setup.md`), the
`music-assistant` service in `compose.yml` is `profiles: [prod]`-only —
this keeps the mode-A, not-yet-real-verified backend out of the default
dev stack (`docker compose up -d`, no profile) entirely, so nothing
changes for day-to-day dev work until this is real-verified.

```bash
docker compose --profile prod up -d music-assistant
```

Unlike every other service in `compose.yml`, `music-assistant` runs with
`network_mode: host` rather than joining the shared compose network. This
follows Music Assistant's own documented requirement: it needs host
networking for local player discovery (mDNS/uPnP — Snapcast, Chromecast,
AirPlay, Sonos, …) and for streaming audio to those devices on the LAN;
bridge/compose networking is documented as unsupported once real
networked players are involved. Two practical consequences:

- MA's HTTP/JSON-RPC API + web UI (port `8095`) and its audio-streaming
  port (`8097`, with fallback to subsequent ports if occupied) are
  reachable on the **host's own** network interface, not via a
  service-name DNS entry on the compose network. Set
  `MUSIC_ASSISTANT_URL` to `http://<host-lan-ip>:8095`, not
  `http://music-assistant:8095` (that hostname only resolves for the
  bridge-networked services elsewhere in this compose file).
- Because host networking bypasses the loopback-only pattern
  `mumble-admin`/`teamspeak-admin` use (both bound to
  `127.0.0.1:<port>:8000` in `compose.yml`), restrict access to `8095`/
  `8097` at the host firewall to the LAN/venue network — do not expose
  either port to the wider internet.

MA persists its own state (library index, provider config, player config)
under `/data` in the official image — mounted to a named volume
(`music_assistant_data`) so it survives container recreation.

## 2. Obtaining a long-lived bearer token

Music Assistant's HTTP API (the same namespaced `@api_command`s its own WS
clients use, mirrored over HTTP/JSON-RPC — see `HttpMusicClient`'s class
docblock) is authenticated with a bearer token, sent via
`Http::withToken(...)` exactly like `HttpDiscordClient`/`HttpMumbleClient`.
Generate a long-lived token from MA's own web UI (Settings → **Core** →
**API**, or the equivalent "long-lived access token" panel depending on
the MA version running) and set it as `MUSIC_ASSISTANT_TOKEN` below. There
is no separate "admin token vs. query password" split here (unlike
TeamSpeak) — the one bearer token gates the whole HTTP surface.

## 3. Setting up a player/output (e.g. Snapcast)

MA needs at least one configured **player** — the actual audio output
wired to the venue's PA — before `syncQueue()`/`nowPlaying()` have
anything to report against:

1. In MA's web UI, add a player provider (Snapcast is a common LAN choice:
   run a `snapserver` alongside MA, plug the venue's amp/speakers into a
   `snapclient`, or run `snapclient` directly on a machine with a line-out
   to the PA). Chromecast/AirPlay/DLNA/Squeezelite/MPD work the same way
   if that fits the venue's hardware better.
2. Once the player appears in MA's **Players** list, note its **player
   ID** (a UUID-like string MA assigns) — this is what LANoMAT calls its
   "queue id" (`HttpMusicClient` addresses `player_queues/*` commands by
   this id).
3. Set that id as `MUSIC_ASSISTANT_PLAYER_ID` below. LANoMAT only ever
   drives **one** configured player per deployment (config-bound, never a
   per-request parameter — see `MusicClient`'s contract docblock);
   running multiple simultaneous jukebox players is out of scope for this
   phase.

## 4. `.env` configuration

See `.env.example`'s Music Assistant section for the full set with inline
comments. Summary:

| Variable | Meaning |
|---|---|
| `MUSIC_ASSISTANT_URL` | MA's HTTP/JSON-RPC base URL |
| `MUSIC_ASSISTANT_TOKEN` | Long-lived bearer token for MA's API (step 2 above) |
| `MUSIC_ASSISTANT_PLAYER_ID` | The MA player/queue id `syncQueue`/`nowPlaying`/`skip` target (step 3 above) |

These feed `config('services.music_assistant.*')` (`config/services.php`),
which the app's service container binding constructs `HttpMusicClient`
from when resolving the `MusicClient` contract.

> **`.env.example`'s current default is `http://music-assistant:8095`**,
> written before this task confirmed MA's own docs require
> `network_mode: host` (see §1 above) — that hostname only resolves on
> the shared compose network the rest of the stack uses, not for a
> host-networked service. Set `MUSIC_ASSISTANT_URL` explicitly to
> `http://<host-lan-ip>:8095` in production; updating the shipped default
> itself is an app-config change, tracked as a real-infra follow-up
> alongside the rest of this document's verification checklist.

## 5. Real verification (Music Assistant 2.10.9, 2026-10-31)

`HttpMusicClient` was run against a real server with a Sendspin web player and five local test tracks. Three
assumptions of the first version were wrong and are fixed (the REST envelope `POST /api` with `{command, args}` was right):

| Was | Is |
|---|---|
| `music/search` with `query`, result in `result` | argument `search_query`; results are grouped by media type (`tracks`, `radio`, ...) |
| `player_queues/queue_command` (skip/pause/resume) | the command does not exist; use `player_queues/next`, `pause`, `resume` |
| `player_queues/items` returns an object with `current_item`; `move_item` with a URI and `pos_target` | `items` is a list; the current track and the playback state come from `player_queues/get_active_queue`; reordering is done with `play_media` (`replace`, `replace_next`) and `delete_item` |

Behaviour learned on the wire:

- `play_media` with `option: replace` **starts playback** of the first item (no extra play call is needed).
- A **paused** web player reports `state: idle` with the position kept, a **finished** queue reports `state: idle` with
  `elapsed_time: 0`. The client tells them apart by the position; otherwise the jukebox tick would skip a paused track.
- `syncQueue()` now receives the track that should play *now* first, then the vote-ordered upcoming ones. When that
  track already plays it is left alone (only the upcoming part is replaced); otherwise the list is started. The first
  version sent only the upcoming list, so a promoted track never reached the player.
- A web player gets a **new id with every browser connection** and disappears when the page closes or the phone locks
  (`PlayerUnavailableError` -> HTTP 500 -> `MusicUnavailable`, which the app logs and survives). Use a stable player
  (Snapcast, Chromecast, Squeezelite) for a real event.
- Not verified: Snapcast/Chromecast players, crossfade, and more than one concurrent event.

## 6. Fallback: go-librespot + Spotify Web API (documented, NOT the default)

If a Music Assistant server is not wanted for a given deployment, a direct
go-librespot + raw Spotify Web API path remains a documented alternative
`MusicClient` backend — **not implemented this phase, and not the
recommended path**, but the roadmap keeps it as a fallback because it has
no MA dependency at all:

- Only the **orga's Spotify Premium account** performs the OAuth exchange
  (Spotify's small-app "5 users in dev mode" cap does not bite here, since
  only the host account authenticates).
- LANoMAT itself would own the queue ordering and push only the **next**
  single track via `PUT /me/player/play?device_id=…&uris=[…]`, because
  Spotify's own play queue is not reorderable — the exact "shove the next
  song" trick Music Assistant's native queue makes unnecessary.
- Playback is Spotify-only (no local files/other sources) and inherits
  Spotify's login-flow fragility (librespot's reverse-engineered auth
  periodically breaks when Spotify changes its login flow).
- This path would need its own `MusicClient` implementation
  (e.g. `SpotifyMusicClient`) alongside `HttpMusicClient` — the contract
  is already backend-agnostic, so adding it later does not require
  touching the Jukebox module's actions, policy, or UI.

Music Assistant is preferred over this fallback because it: owns a real
reorderable queue (no "next song only" trick needed), supports many more
sources including local files/Subsonic (covering the no-internet case),
and decouples the streaming-account dependency from LANoMAT's own code —
that dependency now lives inside MA, not in `HttpMusicClient`.

## Relationship to the rest of the Jukebox module

`HttpMusicClient` is the sole implementation of both `MusicClient` and the
optional `PlaybackControl` capability today, resolved wherever the
`MusicClient` contract is type-hinted (`AddToQueue`, `ToggleVote`,
`SkipCurrent`, `SyncQueueToPlayer`, `JukeboxTickCommand`, …). Nothing
downstream needs to know it is MA specifically — see
`docs/architecture.md`'s "Jukebox / LAN-Radio" section for the module's
full design, including the vote-order queue read-model
(`JukeboxQueue`), the checked-in participation gate, the anti-flood cap,
the community skip threshold, and the graceful `MusicUnavailable`
degradation path that keeps a down MA server from taking down any other
part of the app.
