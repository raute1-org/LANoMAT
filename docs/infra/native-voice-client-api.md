# Native voice client API

Server side of the planned desktop voice client (Tauri app, Rust LiveKit core, global push-to-talk).
The desktop app talks to the same LiveKit rooms as the browser page, see
[livekit-web-voice.md](livekit-web-voice.md). This document covers only what the LANoMAT server
offers such a client. It is part of the WebVoice module: when the module is off, none of these
routes exist.

Placeholders used below: `<app>` is the public https origin of LANoMAT, `<token>` an access token,
`<device-code>` a device code.

## How a device is paired

A device authorization flow in the style of OAuth 2.0 device grant (RFC 8628) and `gh auth login`.
The client never sees a password or a Discord token.

1. The client calls `POST <app>/api/voice-client/device` and shows the returned `user_code`.
2. The user opens `verification_url` (`<app>/talk/connect`, or `verification_url_complete` with the
   code prefilled), logs in the normal way (Discord) and compares the code and the device name with
   what the app shows. **Verbinden** approves, **Ablehnen** denies.
3. Meanwhile the client polls `POST <app>/api/voice-client/token` every `interval` seconds. The
   approved request is exchanged exactly once for a bearer access token.

### `POST /api/voice-client/device`

Unauthenticated. Body (JSON): `device_name` (optional string, at most 120 characters, shown to the
user on the approval page; control characters are stripped, stored truncated to 60).

```json
{
  "device_code": "<device-code>",
  "user_code": "ABCD-EFGH",
  "verification_url": "<app>/talk/connect",
  "verification_url_complete": "<app>/talk/connect?code=ABCD-EFGH",
  "expires_in": 600,
  "interval": 5
}
```

The user code has eight characters from an alphabet without `0 O 1 I L`, is single use and valid for
10 minutes (`webvoice.native.code_ttl`).

### `POST /api/voice-client/token`

Unauthenticated. Body: `device_code`. Success (200):

```json
{ "access_token": "<token>", "token_type": "Bearer", "expires_in": 7776000 }
```

Errors are `400` with `{"error": "<code>"}`:

| error | Meaning |
|-------|---------|
| `authorization_pending` | the user has not decided yet, keep polling |
| `slow_down` | polled before `interval` elapsed; the response carries a new, longer `interval` (+5 s each time) |
| `access_denied` | the user denied; the request is gone |
| `expired_token` | the 10 minutes ran out; start over |
| `invalid_grant` | unknown or already used device code |

Malformed input is a `422`. A rate limited caller gets `429`.

## Token lifetime and revocation

* The access token is opaque, `lvc_` plus 48 random characters. It is **sliding**: it expires
  `webvoice.native.token_ttl_days` (default 90) days after its last use. The expiry is moved on use at
  most every five minutes.
* A user sees their devices under **Verbundene Geräte** on `/talk/connect` (name, connected on, last
  used) and can **Trennen** (revoke) each one. A user can revoke only their own devices; admins pass
  through the app-wide admin gate.
* A revoked or expired token answers `401` immediately. Deleting a user deletes their tokens
  (cascade). There is no separate "blocked user" state in LANoMAT. A role change takes effect on the
  next request, because the role is read live and never stored in the token.
* `webvoice:prune` (daily) removes spent pairing requests and tokens that have been dead for 30 days.

## API v1

All routes are JSON under `<app>/api/voice-client/v1`, authenticated with
`Authorization: Bearer <token>`. They are rate limited per token (120 per minute; room tokens 30 per
minute). Besides voice it carries text chat, realtime channel auth, notifications and the event functions
(sections below), and the "play" routes (now feed, tournaments, matches, game servers). A missing, malformed, unknown,
revoked or expired token is `401` with a `WWW-Authenticate: Bearer` header.

| Route | Result |
|-------|--------|
| `GET me` | `{id, name, role, identity}`; `identity` is the LiveKit identity (`u<id>`) the client will have in a room |
| `GET rooms` | `{rooms: [{id, name, description, kind}]}`: exactly the rooms the browser page lists for that user (`VisibleChannels`: general rooms, the user's own match rooms, orga and admins see every live room; archived never) |
| `POST rooms/{room}/token` | the same payload as the browser, from the same action (`IssueJoinToken`): `{url, token, identity, muted, audio}`. A moderator mute arrives as `muted: true` and a token without the publish right. Never takes identity or room name from the client. |
| `GET config` | `{api_version, livekit_url, voice_ready, audio, modules, realtime, client}`: the LiveKit URL the client must use, the audio tuning defaults (bitrate, DTX, RED, `jitter_buffer_target_ms`), the Reverb connection info (see "Realtime") and `modules`, which maps every module key (the `MODULE_<KEY>` names, e.g. `game_servers`, `catering`, `voting`, `files`, `tournaments`, `schedule`, `lfg`) to whether this deployment runs it, so the client can hide what the event does not use. `client` is the version block described under "Versions and the client download". No secrets. |

| `GET avatars/{user}/{hash}` | The avatar of a person, see "Avatars" below. |

A room the caller may not see answers `404`, exactly like a room that does not exist (no
enumeration, no 403). An archived room answers `410` for admins and `404` for everyone else. `503`
means the LiveKit server is not configured or not reachable.

## Avatars

LANoMAT keeps its own copy of each person's Discord avatar (a 256 x 256 WebP, metadata stripped, on the
private disk, fetched by the server and refreshed daily), so neither the browser nor this client ever
talks to the Discord CDN: no one learns who looks at whom, and it works at a LAN without internet.

**DTO field.** Every DTO that has a person carries the avatar in `avatar_url` (friends, requests,
suggestions, blocked users, the own profile, chat message authors) or `avatarUrl` (people). The name is
kept for compatibility, but the **value changed its meaning**: it is no longer a Discord address but the
**bearer path of the local copy**, `/api/voice-client/v1/avatars/<user id>/<content hash>`, or `null`
when the person has no avatar (or it has not been fetched yet). Never fall back to anything else on
`null`: show a monogram. The realtime chat event is shared with the browser and carries the browser
path `/avatars/<user id>/<content hash>`; the client maps both forms to the bearer route.

**Route.** `GET /api/voice-client/v1/avatars/{user}/{hash}`, bearer-authenticated like the rest, answers the
image bytes (`image/webp`) with `Cache-Control: private, max-age=31536000, immutable`, `ETag: "<hash>"`
and `X-Content-Type-Options: nosniff`; a matching `If-None-Match` gets `304`. It is visible to every logged-in
user (ability `view-avatar`, like names). `404` for a wrong or outdated hash, an unknown user, a user without
an avatar or a missing file; `401` without a valid token. The hash is part of the URL: a changed avatar is
a new URL, so the bytes behind one URL never change and can be cached forever (the desktop client keeps
them on disk by user id and hash). It has its own limiter (600 per minute and token), because a people list
loads dozens of avatars at once.

## Versions and the client download

The client a person downloads is always the build for the server they download it from. Version flow:
`git describe --tags --always --dirty` (`scripts/ops/lib/version.sh`, one helper for both sides) gives the
version string, for example `m12-463-g0a3b6ff`. `scripts/deploy/deploy.sh` exports it as `APP_VERSION` (and the
12-character commit as `APP_COMMIT`), compose passes both as build args to every application image, the
Dockerfile bakes them into `ENV`, and `config('app.version')` / `config('app.commit')` read them (`dev` outside a
built image). `scripts/ops/build-client.sh` computes the same string and compiles it into the Rust binaries
(`LANOMAT_BUILD_VERSION`, `LANOMAT_BUILD_COMMIT`, read by `voice_core::build_info()`).

The build script publishes `clients-dist/` with a `manifest.json` (see `clients/voice/README.md`); the app mounts
it read-only and serves it on `GET /talk/client` (page) and `GET /talk/client/download/{file}` (any logged-in
user; only names listed in the validated manifest, otherwise 404; the response carries
`X-Checksum-Sha256`). Preflight check `client_build` warns when the published build does not match the running
version or does not exist.

`GET config` carries, additively:

```json
"client": {
  "server_version": "m12-463-g0a3b6ff",
  "commit": "<12 hex characters>",
  "min_client_version": null,
  "latest_client_version": "m12-463-g0a3b6ff",
  "download_path": "/talk/client"
}
```

`min_client_version` is `webvoice.native.min_client_version` (`VOICE_CLIENT_MIN_VERSION`, default `null`, no
floor); `latest_client_version` is the version of the published build, `null` when there is none. The client
decides with `voice_core::update::assess` (up to date, update available, incompatible) and exposes it as the
`get_update_status` command; versions it cannot order (`dev`, other schemes) never block.

Headers: every client API call sends `X-Client-Version: <version>` and `User-Agent: lanomat-voice/<version>`
(nothing about the person or the machine); every response of the client API, errors included, carries
`X-Server-Version: <version>`.

## Play API: now feed, tournaments, matches, game servers

Same authentication, rate limit and error shape as above (`401` without a valid bearer token; JSON errors
even without an `Accept` header). Events are addressed by **slug**, as on the website; an event in `draft`
is a `404`. Everything here reuses the participant web pages' own Actions, Policies and read helpers, so a
rule changed on the website changes here too. Code: `app/Modules/WebVoice/NativeClient/` (`Http/*ApiController`,
`Support/Native*`), routes in `routes/voice-client-play.php`.

| Route | Result |
|-------|--------|
| `GET events/{event}/now` | the prioritised "Jetzt" cards: `{cards, more}` (see below) |
| `GET events/{event}/tournaments` | `{tournaments: [...]}` visible tournaments (no drafts for participants) with my enrollment state |
| `GET events/{event}/my-matches` | `{matches: [...]}` my active, upcoming and recently finished matches |
| `POST tournaments/{tournament}/checkin` | check in my entry; returns `{tournament}` |
| `POST matches/{match}/report` | body `score1`, `score2` (own : opponent, no tie); returns `{match}` |
| `POST matches/{match}/confirm` | body `lock_version`; the opponent confirms; returns `{match}` |
| `POST matches/{match}/dispute` | the opponent disputes; returns `{match}` |
| `POST matches/{match}/report/withdraw` | body `lock_version`; the reporting side takes its claim back; returns `{match}` |
| `GET events/{event}/servers` | `{servers: [...]}` event servers, see "Game servers" (only while the GameServers module is on) |
| `GET servers` | `{servers: [...]}` permanent servers, every logged-in user (only while the GameServers module is on) |

Where people are (`GET events/{event}/people`, `.../people/{user}/follow`) has its own section, "People", below.

The write routes are the website's actions: the same `TournamentPolicy` checks (a refusal is `403`), the same
Actions and domain rules, the same optimistic `lock_version`. A match or tournament the user may not see, or a
match they do not play in, is `404`. Errors:

| Status | Body | When |
|--------|------|------|
| `422` | `{"message": "...", "error": "tournaments.errors.checkin_closed"}` | a domain rule (`TournamentException`): window closed, match not ready, already reported, registration required, ... |
| `422` | `{"message": "...", "errors": {"score2": ["..."]}}` | validation, e.g. a tied score or a missing `lock_version` |
| `409` | `{"message": "...", "error": "tournaments.errors.stale_match"}` | the match changed under the client (stale `lock_version`): reload and retry. Also `report_not_pending` / `report_withdrawn` for a stale click on a claim that is gone |

### Tournament DTO

```json
{
  "id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "format": "single_elimination", "status": "check_in",
  "startsAt": "2026-11-07T18:00:00+00:00",
  "checkinOpensAt": "2026-11-07T17:30:00+00:00", "checkinClosesAt": "2026-11-07T17:55:00+00:00",
  "entryCount": 12, "maxEntries": 16, "teamSize": 1,
  "myEntry": {"id": 31, "status": "registered", "canWithdraw": true, "isOwner": true},
  "can": {"checkIn": true, "withdraw": true},
  "registration": {"required": false, "open": false, "url": "<app>/events/<slug>/register"}
}
```

`myEntry` is null when the user is not enrolled; a plain team member sees the team's entry with `isOwner: false`
and nothing to act on.

### Match DTO

```json
{
  "id": 88, "phase": "active", "status": "ready",
  "tournament": {"id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "status": "live"},
  "round": 2, "bracket": "winners",
  "scheduledAt": null, "warmupStartedAt": null, "updatedAt": "2026-11-07T18:20:00+00:00",
  "me": {"entryId": 31, "name": "Team Rot"}, "opponent": {"entryId": 35, "name": "Team Blau"},
  "score": null, "won": null, "lockVersion": 0,
  "pendingReport": null,
  "voiceRoomId": 14,
  "server": null,
  "can": {"report": true, "confirm": false, "dispute": false, "withdrawReport": false, "checkIn": false}
}
```

`phase` is `active` (ready, warmup, live, reported, disputed), `upcoming` (pending; the opponent may still be
unknown, so `opponent` can be null) or `finished` (completed in the last three hours, at most five). The list
is ordered active (result to confirm first, then ready, then live), upcoming, finished. `score` and
`pendingReport` are from my side ("own : opponent"); `pendingReport` is `{reportedByMe, ownScore,
opponentScore}`. `voiceRoomId` is the id of my team's room for this match, the same id `GET rooms` lists and
`POST rooms/{room}/token` accepts, or null when there is no room or the room policy would not let me in.
`server` is the match's own server in the game server DTO below, or null. `can.*` are the policy answers for the
moment: `report` while the match is ready or live, `confirm` and `dispute` for the opponent of a pending claim,
`withdrawReport` for the reporter, `checkIn` while my tournament's check-in window is open and I have not
checked in. A plain team member sees the match with every flag false.

### Game servers

Both lists use the website's projections (`ServerListProjection`), so the listed servers, their status and
players/map are the same as on `/events/{event}/servers` and `/servers`. Two deliberate differences from the
public event page, which lists every match server to every logged-in user: the event list here drops a server
bound to a single match unless the user plays in that match (either roster) or is orga, and the DTO is a fixed
whitelist (no RAM estimate, never the stored password on its own).

```json
{
  "id": 21, "title": "CS2 Cup Runde 2", "game": "Counter-Strike 2", "matchLabel": "CS2 Cup, Runde 2",
  "description": null, "status": "ready",
  "players": 4, "maxPlayers": 10, "map": "de_mirage", "checkedAt": "2026-11-07T18:21:00+00:00",
  "address": "203.0.113.5", "port": 27015,
  "connectString": "steam://connect/203.0.113.5:27015", "connectIsLink": true,
  "installHint": {"steamUrl": "steam://store/730", "shareUrl": null, "versionNote": null},
  "joinUrl": "steam://connect/203.0.113.5:27015",
  "copyText": "203.0.113.5:27015"
}
```

`players`, `maxPlayers`, `map` and `checkedAt` are null while the server was never queried (or is offline).
`status` is `provisioning`, `ready`, `not_responding` or `offline` (the permanent list also shows offline ones).
`joinUrl` is the `steam://connect/...` link, only while the server is `ready`, else null. `copyText` is what the
client copies, only while `ready` or `not_responding`: the connect string when it is plain text (it carries the
password exactly where the website's connect string does), otherwise `address:port`. A server with a plain
connect string has `connectIsLink: false` and `joinUrl: null`. An offline or starting server has neither, so
the client shows no join button.

### The "now" feed

`GET events/{event}/now` returns `{cards: [...], more: [...]}`: at most five cards, most urgent first, the rest
in `more`. Every card is `{kind, id, state, title, body, at, data}` (`title` is German, ready to show; `id` is
stable per object, e.g. `match:88`). The voice card of the UX concept is client side and not part of the feed.

| kind (state) | Priority | Source | `data` |
|--------------|----------|--------|--------|
| `match` (`checkin_open`) | 5 if the window closes within 10 min, else 12 | my tournaments with `can.checkIn` | `{tournament}` (Tournament DTO); `at` is when check-in closes |
| `match` (`confirm_result`) | 8 | the opponent's claim is waiting for me | `{match}` |
| `match` (`ready`) | 10 | my match is ready or in warmup | `{match}` with `voiceRoomId` and `server` |
| `match` (`live`) | 15 | my match is live | `{match}` |
| `vote` (`open`) | 30 if it closes within 10 min, else 40 | open poll of the event I have not voted in | `{pollId, question, closesAt, options: [{id, label}]}` |
| `order` (`closing_soon`) | 45 | an open ordering round closing within 45 min that I have not ordered in (needs a confirmed registration) | `{orderId, title, closesAt, menu: [{key, name, priceCents}]}` |
| `notification` (`unread`) | 60, 61, 62 | my latest three unread in-app notifications | `{notificationId, category}` |
| `schedule` (`next` or `running`) | 90 | next programme item; only when there is no match, vote or order card | `{item}` |

A match I reported myself, a disputed match and an upcoming one give no card (they are in `my-matches`).
"Order ready for pick-up" has no state on an order: the "Essen ist da" message is the `FoodOrderReady` in-app
notification and therefore arrives as a `notification` card.

**How the optional kinds are wired.** `match` and `notification` read core modules directly (Tournaments,
Notifications). `vote`, `order` and `schedule` each go through one contract in the WebVoice module
(`VoteCardSource`, `OrderCardSource`, `ScheduleCardSource`); the owning module implements it
(`Voting\Support\NativeVoteCards`, `Catering\Support\NativeOrderCards`, `Schedule\Support\NativeScheduleCards`) and
`AppServiceProvider` binds a null source (`NativeClient\Support\Now\Null*CardSource`) while that module is switched
off, so the feed never reaches into a module that is not running. All three kinds are implemented against the
existing models and read helpers and need no other branch's endpoints. Casting a vote, ordering and reading
notifications from the client are not part of this API block.

## People: where is everybody, and follow them

The product idea: voice rooms exist in parallel on Discord, LANoMAT (web voice), TeamSpeak and Mumble, and you
see where a teammate is right now, in voice and in a game, and can follow them there. Same bearer
authentication, rate limit and JSON errors as the play API; events are addressed by slug and a draft event is
`404`. Present only while the Presence module is on. Routes in `routes/voice-client-presence.php`, code in
`app/Modules/Presence/` (`Http/PeopleApiController`, `Support/PeopleDirectory`, `Support/LocationSnapshotBuilder`).

| Route | Result |
|-------|--------|
| `GET events/{event}/people` | `{me, data: [...], meta}`: the participants of the event who share their location, and my own entry under `me` (always, even when I opted out). Query: `team=1` only my teammates (people sharing one of my teams), `friends=1` only my friends (while the Friends module is on), `q=` name contains, `page`, `per_page` (1 to 100, default 50). Sorted: my teammates first, then people who are in voice or a game, then the rest, each by name. |
| `GET events/{event}/people/{user}/follow` | the join targets for **me** to go where that person is (below) |

A **participant** is a registration that is not cancelled. Everybody else gets `403` (`EventPolicy::viewPeople`);
orga may always look. A person row:

```json
{"id": 12, "name": "Ada", "avatarUrl": null, "team": "Alpha Crew", "isMe": false, "isTeammate": true, "isFriend": false,
 "voice": [{"provider": "webvoice", "roomLabel": "Team Alpha", "roomRef": "41", "kind": "match", "since": "2026-11-04T20:01:00+01:00"}],
 "game": {"source": "steam", "game": "Counter-Strike 2", "serverTitle": "Match 12", "address": "203.0.113.5", "port": 27015,
          "joinUrl": "steam://connect/203.0.113.5:27015", "linkId": 7, "since": "2026-11-04T20:03:00+01:00"},
 "lastSeenAt": "2026-11-04T20:03:30+01:00"}
```

`voice` has at most one entry per provider (`discord`, `mumble`, `teamspeak`, `webvoice`); `kind` is `match`,
`team`, `general` or `other`; `roomRef` is the provider's room id and is `null` for a room only its roster may
join (a match or team room) when I am not on that roster. `game` is `null` when nobody knows; `source` is
`steam` or `query`; `joinUrl`, `address`, `port`, `serverTitle` and `linkId` are only set for a server the
viewer may see (the same rule as `GET events/{event}/servers`: another match's server shows only the game
name). `since` is when the previous refresh first saw the person there; `lastSeenAt` when they were last in
any room or game.

### Follow

`GET events/{event}/people/{user}/follow` returns

```json
{"user": {"id": 12, "name": "Ada"}, "preferredProvider": "discord",
 "voice": [
   {"provider": "discord", "roomLabel": "Allgemein", "kind": "other", "joinable": true, "join": {"url": "https://discord.com/channels/<guild>/<channel>"}},
   {"provider": "webvoice", "roomLabel": "Lounge", "kind": "general", "joinable": true, "join": {"roomId": 41, "tokenPath": "/api/voice-client/v1/rooms/41/token"}},
   {"provider": "mumble", "roomLabel": "Lobby", "kind": "other", "joinable": true, "join": {"url": "mumble://<name>@voice.example/Lobby"}},
   {"provider": "teamspeak", "roomLabel": "Lobby", "kind": "other", "joinable": false, "join": null}],
 "game": {"source": "steam", "game": "Counter-Strike 2", "serverTitle": "Match 12", "address": "203.0.113.5", "port": 27015,
          "joinUrl": "steam://connect/203.0.113.5:27015", "linkId": 7, "since": "...", "copyText": "203.0.113.5:27015"}}
```

For web voice the client calls the existing `POST rooms/{room}/token` with `roomId` (the join rules stay
`WebVoiceChannelPolicy`'s). Mumble and TeamSpeak links carry the caller's own name (`VoiceJoinLink`), Discord
is the channel URL (Discord decides who may connect). `preferredProvider` is a provider the person is on **and**
I am already connected to, which also comes first in `voice`; otherwise `null`. A room that only a roster may
join (a match or team room) is `joinable: false` unless I am on that roster or orga. `joinUrl` and `copyText`
are built exactly as the servers API builds them (`NativeServerDto`); for a Steam player on a server that is
not LANoMAT's the address Steam shows and `steam://connect/<address>` are used.

**No oracle.** An unknown user, a non-numeric id, somebody who is not a participant, somebody who switched
`share_location` off, somebody blocked in either direction with the caller, and the caller themselves are all the
same `404` (same message). Opted-out and blocked people are not in the list either.

### Where the locations come from

All of it is gathered by `php artisan presence:refresh-locations` (scheduled every 30 seconds while an event is
live; one fan-out to the voice sidecars, LiveKit and Steam) into one cached snapshot (5 minutes). Requests only
read the snapshot; a cold cache queues a refresh and shows nobody located until it is built. Nothing is
broadcast on a channel and nothing reaches the beamer; the presence board and its scene are built without any
location data.

| Source | How a person is found | Limits |
|--------|----------------------|--------|
| Discord voice | the Gateway's voice states, already mapped to a LANoMAT user by Discord id | Discord accounts LANoMAT does not know are never named or stored. Needs the Gateway sidecar. |
| LANoMAT web voice | one LiveKit participant list per live room; the identity `u<user id>` the server put into the token | exact; one HTTP call per room per refresh |
| Mumble / TeamSpeak | the sidecars list the names of the people in each channel; the name is compared with the name the join link gives that user | a name is only accepted when exactly one user can have it (case insensitive, all users counted, also opted-out ones); an ambiguous name, a TeamSpeak collision suffix or somebody who changed their name is unknown. Needs the updated sidecars (`occupant_names`); an old sidecar means no names. |
| Steam | `ISteamUser/GetPlayerSummaries` for users with a linked Steam account (`gameid`, `gameextrainfo`, `gameserverip`), batches of 100, 30 s cache, the Steam login's API key | only for profiles that show their game; a failure, a 429/5xx (pauses calls for 60 s) or a timeout is "unknown", never an error |
| Player lists of our servers | the player names of the last query, matched to a user by exact display name or linked Steam persona name | Minecraft's status sample (vanilla shows at most 12 names, servers may hide them); A2S_INFO carries no names and A2S_PLAYER is not queried, so Source servers are found through Steam only. Event-level servers are not queried at all today (only match servers of type `a2s` and permanent servers are). |

When both Steam and a player list know a person, the server known to LANoMAT wins (it has the exact join info).

### Privacy: `share_location`

A profile setting, default on, in the web settings (checkbox "Meinen Standort teilen") and in `PUT|PATCH profile`
(`share_location`, boolean). It is read live on every request: switching it off hides the person at once. Opted-out
people see their own location under `me`.

## Event features (v1)

The same bearer token also drives the event functions of the participant site: events, polls,
catering and shared files. Every rule (who sees what, voting windows, ordering windows, item limit,
upload quota, moderation) is enforced by the **same Actions and Policies as the browser**; the API only
reshapes the data to JSON. Differences from the browser are noted explicitly below. A route group exists
only while its module is routable (`Voting`, `Catering`, `Files`), like the web routes.

Conventions for this block:

* Event routes take the event **slug** (`{event}`); a draft event answers `404` everywhere, exactly like
  the browser. Poll, order, item and file ids are numeric.
* JSON keys are `snake_case`; money is **integer cents** (`price_cents`, `total_cents`), never a float;
  times are ISO 8601. The only exception is the upload ticket, which is the browser payload verbatim
  (camelCase).
* A refused business rule is `422` with `{"code": "<stable code>", "message": "<German browser text>"}`.
  Validation errors are the usual Laravel `422` with `errors`. A policy refusal is `403`, an unknown id
  `404`. Responses are always JSON, with or without an `Accept` header.
* State changing routes are throttled (30 per minute, ticket route `files.tus.tickets_per_minute`).

### Events

`GET events` lists every non-draft event (newest start first) with the caller's own registration.
`current_event_slug` (and `current: true` on exactly one row) is the event a client should preselect: a
**live** event (one the caller is registered for wins, otherwise the most recently started), else the
nearest upcoming (`announced` or `registration`) event the caller is registered for, else `null`.

```json
{
  "current_event_slug": "lan-party-2026",
  "events": [{
    "id": 7, "slug": "lan-party-2026", "name": "LAN Party 2026", "status": "live",
    "starts_at": "2026-11-06T16:00:00+00:00", "ends_at": "2026-11-08T12:00:00+00:00",
    "location": "Stadthalle", "current": true,
    "registration": {"status": "confirmed", "ticket_type": "standard", "paid": true, "checked_in": false}
  }]
}
```

`registration` is `null` without an active registration (a cancelled one counts as none). No QR payload
is ever exposed.

### Polls (module Voting)

| Route | Result |
|-------|--------|
| `GET events/{event}/polls` | every poll of the event, newest first, open and closed: `{event_slug, polls: [{id, question, kind, status, is_open, closes_at, total_votes, my_vote_option_id}]}` |
| `GET polls/{poll}` | the detail as the web page shows it |
| `POST polls/{poll}/vote` | body `{"option_id": 12}`; `201` with the updated detail |

```json
{
  "id": 3, "event_slug": "lan-party-2026", "question": "Which map?", "kind": "standard",
  "status": "open", "is_open": true, "closes_at": null, "total_votes": 3,
  "options": [{"id": 12, "label": "Dust", "count": 2, "percent": 66.7}, {"id": 13, "label": "Mirage", "count": 1, "percent": 33.3}],
  "my_vote_option_id": 12
}
```

`is_open` is the live state (an `open` poll past `closes_at` is not open). Results are always visible, as
on the web page (there is no hidden-results mode). Voting goes through `CastVote`: one vote per user,
**no changing a vote** (a second vote is `422 already_voted`), `422 not_open` for draft, closed or expired
polls (`kind: "mvp"` polls follow the same rules), an option of another poll is `404`.

### Catering (module Catering)

| Route | Result |
|-------|--------|
| `GET events/{event}/catering` | open and closed orders (never drafts) with menu, window and the caller's own items |
| `GET events/{event}/catering/mine` | the caller's items across all orders with the order's status |
| `POST food-orders/{foodOrder}/items` | body `{"option_key": "pizza_margherita", "note": "optional, max 200"}`; `201` with the item |
| `DELETE food-order-items/{foodOrderItem}` | cancel an item while its order window is open; `204` |

```json
{
  "event_slug": "lan-party-2026",
  "registration_required": false,
  "limits": {"items_per_user": 10, "my_open_items": 1},
  "orders": [{
    "id": 4, "title": "Pizza", "status": "open", "is_open": true,
    "opens_at": "2026-11-06T17:00:00+00:00", "closes_at": "2026-11-06T19:00:00+00:00",
    "menu": [{"key": "pizza_margherita", "name": "Pizza Margherita", "price_cents": 850}],
    "my_items": [{"id": 31, "option_key": "pizza_margherita", "option_name": "Pizza Margherita", "note": null, "price_cents": 850, "paid": false}],
    "my_total_cents": 850
  }]
}
```

`mine` returns `{items: [{id, option_key, option_name, note, price_cents, paid, order_id, order_title,
order_status, order_is_open, can_cancel}], total_cents, paid_cents}`. The price is always taken from the
menu on the server (a `price_cents` in the body is ignored). Placing goes through `PlaceFoodOrderItem`:
`422` with `not_open` (outside the window or draft/closed), `unknown_option`,
`registration_required` (a confirmed registration is required, no check-in needed) or `item_limit` (the
per-user limit counts across the open orders of the event). There is no "edit" in the browser either:
change an order by cancelling the item (`CancelFoodOrderItem`, `422 not_open` once the window is closed;
only the owner or orga may cancel) and placing a new one. `paid` is read only.

### Files (module Files)

| Route | Result |
|-------|--------|
| `GET events/{event}/files` | approved files plus the caller's own pending ones (never rejected or foreign pending ones), newest first, with quota and upload info |
| `GET events/{event}/files/quota` | only `quota` and `upload` (cheap to poll during an upload) |
| `GET files/{sharedFile}/download` | the signed file-host URL (below) |
| `DELETE files/{sharedFile}` | delete a file (owner or orga); `204` |
| `POST events/{event}/files/uploads/ticket` | step 1 of a tus upload, same payload as the browser |

```json
{
  "event_slug": "lan-party-2026",
  "files": [{
    "id": 21, "name": "maps.zip", "size_bytes": 1048576, "uploader_name": "Ada", "visibility": "approved",
    "created_at": "2026-11-06T18:00:00+00:00", "mine": false, "can_download": true, "can_delete": false
  }],
  "quota": {"used_bytes": 4194304, "cap_bytes": 10485760, "remaining_bytes": 6291456},
  "upload": {"resumable": true, "max_file_bytes": 107374182400}
}
```

`quota.used_bytes` counts the caller's files (every visibility) plus open upload reservations, like the
browser. Raw storage paths are never returned.

**Download.** The policy decides (`approved`: everyone; `pending`: owner and orga; `rejected`: nobody,
`403`). The answer is the short-lived signed file-host link; the bytes never pass through the app. The link
is a credential: do not log it or persist it.

```json
{"url": "<file-host>/dl/<path>?expires=1788000000&md5=<signature>&n=maps.zip", "name": "maps.zip", "size_bytes": 1048576, "expires_at": "2026-11-06T18:05:00+00:00"}
```

Only the legacy local store (no file host configured) streams the file as an attachment instead.
`503` with a `message` means the store cannot sign right now.

**Upload.** Uploading is the tus path only: ask for a ticket, then upload to `endpoint` with a tus
client (`Upload-Metadata` `filename`, `filetype`, `ticket`) in `chunkSize` chunks, exactly like the
browser (see [file-host.md](file-host.md)). Body `{"name": "game.zip", "size": 1234, "type": "optional mime"}`:

```json
{"endpoint": "<file-host>/upload/", "ticket": "<single-use ticket>", "chunkSize": 67108864, "expiresIn": 900, "name": "game.zip", "reservationId": 17}
```

`422` with a `code`: `too_large` (over `upload.max_file_bytes`), `quota_exceeded`, `blocked_type`,
`uploads_unavailable` (`upload.resumable` is `false`: the deployment has no file host, there is no form
upload in this API). The quota is reserved when the ticket is issued. A file the app finalises after the
upload starts as `pending` and goes through the same moderation as every browser upload.

## Text chat

Same rules as the browser (`/talk`), because the endpoints call the browser's own query and actions
(`ChannelMessages`, `PostMessage`, `DeleteMessage`). A room the caller may not see is `404` (never
403), an archived room `410` for admins and `404` for everyone else.

| Route | Result |
|-------|--------|
| `GET rooms/{room}/messages?before=<id>` | `{messages: [...], next_before}`. Newest page first: without `before` you get the latest page (size `webvoice.chat.history`, default 50), with `before` the page of older messages. Inside a page messages are oldest first, ready to append. `next_before` is the id to pass as `before` for the next older page, `null` when the history is exhausted. |
| `POST rooms/{room}/messages` | body `{body}` (plain text, trimmed, 1 to 500 characters). `201 {message}`; `422` for empty or too long; `429` when the per-user limit is hit (10 messages per 10 s, the budget is shared with the browser). The author is always the token's user. |
| `DELETE messages/{message}` | moderation, admin and orga only (policy `moderate`): `200 {ok: true}`; `403` for everyone else who can see the room, `404` when the room is not visible. |

Message DTO (identical to the browser's payload and to the realtime event, except that `author.avatar_url` is the bearer avatar path in the API answers and the browser path in the realtime event, see "Avatars"):

```json
{
  "id": 412,
  "channel_id": 3,
  "body": "gl hf",
  "created_at": "2026-11-02T12:00:00+00:00",
  "author": {"id": 7, "name": "Ada", "avatar_url": null}
}
```

## Realtime (Reverb, Pusher protocol)

The browser subscribes to private Reverb channels authorized by its session. A native client uses
any Pusher-protocol client (for example `pusher-js` semantics) against the same Reverb server, but
authorizes through its bearer token.

`GET config` returns `realtime` (or `null` when Reverb is not configured; poll the REST endpoints
then):

```json
{"driver": "reverb", "key": "<public app key>", "host": "ws.example.org", "port": 443, "scheme": "https",
 "auth_endpoint": "/api/voice-client/v1/broadcasting/auth"}
```

Connect to `<scheme>://<host>:<port>/app/<key>` (`wss` when `scheme` is `https`). The app secret is
never exposed.

`POST broadcasting/auth` takes the Pusher body `{socket_id, channel_name}` and answers
`{"auth": "<key>:<signature>"}`. It runs the same channel callbacks as the browser
(`routes/channels.php`) for the bearer user. `403` for a channel the user may not see, an unknown
channel, a name without the `private-` prefix, or a presence channel. Only the three families below
are ever signed.

| Channel (subscribe as) | Authorization | Events |
|------------------------|---------------|--------|
| `private-web-voice.channel.{roomId}` | WebVoiceChannelPolicy `view` (general room, own match room, admin everywhere, archived nowhere) | `message.posted` with `{message: <DTO>}`; `message.deleted` with `{channel_id, message_id}` |
| `private-web-voice.directory` | any authenticated user | `channels.changed` (empty payload): the room list changed, refetch `GET rooms` |
| `private-App.Models.User.{userId}` | only the user's own id | none today (see below) |

Event names are the `broadcastAs()` names; with a Pusher client bind them exactly as written (they
are not namespaced).

Notifications are stored in the database only and the app does not broadcast them yet, so the
`App.Models.User.{id}` channel is authorized (ready for it) but silent. Clients should poll
`GET notifications/unread-count` (for example every 30 to 60 s) and refetch the list when it
changes.

## Notifications

Own notifications only: every query goes through the authenticated user's notifications relation,
so another user's id is `404`, exactly like an unknown id.

| Route | Result |
|-------|--------|
| `GET notifications?unread=1&per_page=20&page=1` | `{notifications: [...], page, per_page, total, has_more}`. Unread first, then newest first. `unread=1` filters to unread. `per_page` 1 to 50 (default 20). |
| `GET notifications/unread-count` | `{unread: 3}` |
| `POST notifications/{id}/read` | `{notification: {...}}`, now read. |
| `POST notifications/read-all` | `{marked: <count>}` |
| `GET notification-preferences` | `{preferences: {registration: true, ...}}` for the categories a user can switch: `registration`, `checkin`, `match`, `teams`, `friends`, `schedule`, `catering`, `orga_ping`, `discord`. Default `true`. (`account` and `system` are not optional.) |
| `PUT notification-preferences` | body `{preferences: {match: false}}`: partial update, other categories keep their value; unknown categories or non-boolean values are `422`. Returns the effective preferences. The same switches gate the bell and the Discord DM channel. |

Notification DTO:

```json
{"id": "b2c7c9a6-1f5e-4a7e-9d5b-0a1e2c3d4e5f", "category": "match", "title": "Dein Match startet", "body": "...",
 "read": false, "read_at": null, "created_at": "2026-11-02T12:00:00+00:00"}
```

## Account: profile, linked accounts, friends

Same rules as the browser pages (`/settings/profile`, `/settings/connections`, `/friends`): the
controllers (in the Identity and Friends modules) call the browser's Actions, FormRequest rules and
Policies; the acting user is always the token owner, ids in a body never select the actor. Routes
live in `routes/voice-client-account.php`; the friends routes exist only while the Friends module is routable.

### Profile

| Route | Result |
|-------|--------|
| `GET profile` | `{profile: {...}}`, the own profile (below). |
| `PUT profile` | The browser's form: `name` and `email` required; `bio`, `steam_url`, `stream_url`, `profile_color` optional (omitted keeps the value, `null` clears it); `share_location` (boolean, see "People") optional. Returns `{profile}`. |
| `PATCH profile` | Same rules, but `name`/`email` are only validated when sent: change a single field. |

Validation is the browser's (`ProfileUpdateRequest`): name up to 255, unique valid email (a changed
email resets verification), bio up to 1000, `steam_url` must start with `https://steamcommunity.com/`,
`stream_url` must be https, `profile_color` is `#rrggbb`. Failures are `422` JSON
(`{message, errors: {field: [...]}}`). `role`, `discord_id`, `avatar_url` and every other key are
ignored. There is no avatar upload: the browser has none either, the avatar is the Discord one
(read-only); `avatar_url` here is the bearer path of the local copy or `null` (see "Avatars"). There is also no stored "context display name" preference to expose; `external_name`
of each connection is the per-provider name.

```json
{"profile": {"id": 7, "name": "Ada", "email": "ada@example.test", "email_verified": true,
 "avatar_url": "https://cdn.example.test/a.png", "bio": "hi", "steam_url": null, "stream_url": null,
 "profile_color": "#aabbcc", "has_verified_steam_link": true, "verified_steam_nickname": "ada_s",
 "share_location": true,
 "editable": ["name", "email", "bio", "steam_url", "stream_url", "profile_color", "share_location"]}}
```

### Linked accounts

| Route | Result |
|-------|--------|
| `GET connections` | `{connections: [...]}`: `discord` (login identity, `read_only: true`, never linkable/removable), then `steam` and `twitch`. |
| `DELETE connections/{provider}` | Unlinks the own account (`LinkedAccountPolicy`), idempotent. `{connection}` with the new state. `discord` and unknown providers are `404`. |
| `POST connections/{provider}/link-url` | `{provider, url, expires_at}`: a single-use link into the browser (below). `404` when the provider is not configured. 10 per minute. |

```json
{"provider": "twitch", "label": "Twitch", "status": "needs_reauth", "external_name": "streamer1",
 "available": true, "can_link": true, "can_unlink": true, "read_only": false}
```

`status` is `connected`, `not_connected` or `needs_reauth` (link again to renew). `can_link` is false
for a connected account and for a provider the deployment has no credentials for (`available: false`).
Tokens are never returned.

**Linking from the client.** OAuth is not reimplemented. The client calls `POST connections/{provider}/link-url`,
opens the returned `url` in the system browser and polls `GET connections` (every few seconds, up to the
`expires_at` plus the provider round trip) until the provider's `status` becomes `connected`. The URL is
`<app>/settings/connections/link/<random token>`:

* It sits behind the normal web login: a browser without a session is sent through the regular login and
  returns to the URL, then the normal logged-in flow (`/settings/connections/{provider}/redirect` and the
  provider callback) continues. A guest visit does not use up the token.
* The token is bound to the issuing user (a different logged-in user gets `403` and does not burn it), is
  valid for 10 minutes and works once (a replay or an expired/unknown token is `410`). Only a sha256 digest
  of it is stored (cache).
* Opening it is a `GET` with a side effect (it consumes the token), so do not let link previews fetch it.

### Friends

The lists carry display fields only (`id`, `name`, `avatar_url`, the bearer avatar path or `null`): never email or discord id. The browser's
`/friends` page shows no presence, so neither does this API; the `isFriend` presence flag stays on the
(web) presence board. The browser has no user search either, so there is none here: people are found through
`friends/suggestions` (shared events, teams, tournaments, Steam friends) and the ids the other
endpoints return (the public `/users/{id}` page's id).

| Route | Result |
|-------|--------|
| `GET friends` | `{friends: [{id, name, avatar_url}]}` |
| `GET friends/requests` | `{incoming: [{id, user}], outgoing: [{id, user}]}`; `id` is the request id used below. |
| `GET friends/suggestions` | `{suggestions: [{id, name, avatar_url, shared, reasons, reason_labels}]}`; `reasons` are `shared_event`, `shared_team`, `shared_tournament`, `shared_steam_friend`, `reason_labels` the German copy of the web page. Never lists friends, pending, or users blocked in either direction. |
| `GET friends/blocked` | `{blocked: [{id, name, avatar_url}]}` |
| `POST friends/requests` | body `{user_id}`, answers `201 {status}`: `pending`, or `accepted` when the other side had already asked (auto-accept). No request id is returned: re-read `friends/requests`. 20 per minute. |
| `POST friends/requests/{id}/accept` and `.../decline` | Addressee only (`FriendshipPolicy::respond`). `{status: accepted|declined}`. |
| `DELETE friends/requests/{id}` | Cancel an own outgoing request (`204`). |
| `DELETE friends/{user}` | Remove a friend (`204`, also when there was none). |
| `POST users/{user}/block` | `{blocked: true}`; tears down the friendship and pending requests in both directions. |
| `DELETE users/{user}/block` | Unblock (`204`). |

**Blocked-by is never revealed.** If the addressee blocked the caller, `POST friends/requests` answers
exactly like a normal pending request (same status and body) and creates nothing and notifies nobody; the
caller's own block is reported (`blocked`). Blocking is identical whether or not the target blocked the
caller. A request id of two other users is `404` (not `403`), so ids cannot be probed. Suggestions never
contain someone who blocked the caller.

### Errors

| Status | Body | Meaning |
|--------|------|---------|
| `401` | `{message}` | missing, garbage, revoked or expired token |
| `403` | `{message}` | policy refusal (e.g. answering a request you sent) |
| `404` | `{message}` | unknown provider/user, request of two other users, unconfigured provider on `link-url` |
| `410` | `{message}` | link token expired, used or unknown (browser URL only) |
| `422` | `{message, errors}` | validation failure (profile fields, `user_id`) |
| `422` | `{code, message}` | refused friend rule: `cannot_friend_self`, `already_friends`, `request_pending`, `blocked` (the caller's own block), `cannot_block_self`; `message` is the German browser copy |
| `429` | `{message}` | rate limit |

## Security properties

* **Hashes only.** Device codes, user codes and access tokens are stored as sha256 digests. The plain
  access token exists only in the single successful token response; it is never logged. Lookups are
  by digest followed by a constant-time compare.
* **Bearer tokens work on the client API routes only.** The middleware sets the request user for
  these routes alone and does not touch the session guard, so the same token on any web or Inertia
  route is a plain "not logged in". The API routes carry no session, no cookies and no CSRF (they
  never act on a browser session).
* **The approval step is a normal logged-in, CSRF-protected form** and is throttled per user (10 per
  minute) because it guesses an 8 character code. Each code works once and only while pending.
* **Bearer check first.** `AuthenticateVoiceClient` is prioritized before the throttles and route-model binding (`bootstrap/app.php`), so throttles key on the resolved user and an unauthenticated caller gets `401` for every id, existing or not.
* **Throttles:** device requests 5 per minute per IP; token polling 30 per minute per IP and per
  device code; the approval form per user; the API per token.
* **Short codes are not secrets worth guessing:** 31 symbols over 8 characters (about 8.5e11), 10
  minutes to live, approval throttled.
* Phishing risk of any device flow (someone talks a user into entering their code) is reduced by
  showing the device name and the explicit warning on the approval page.

## Files

`app/Modules/WebVoice/NativeClient/` (actions, policy, models, controllers, bearer middleware; the event feature controllers live in `Http/Features/`),
`routes/voice-client.php`, `routes/voice-client-play.php`, `routes/voice-client-presence.php` (people/follow; controllers in `app/Modules/Presence/Http/`) and `routes/voice-client-account.php` (profile/connections/friends; controllers in `app/Modules/Identity/Http/Native*` and `app/Modules/Friends/Http/NativeFriendsController.php`) (loaded from `bootstrap/app.php` without the `web` group), config in
`config/webvoice.php` under `native`, page `resources/js/pages/WebVoice/Connect.vue`.
