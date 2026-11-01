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
minute). The voice routes come first; the "play" routes (now feed, tournaments, matches, game servers) are described
further down. **There is no chat API in v1.** A missing, malformed, unknown,
revoked or expired token is `401` with a `WWW-Authenticate: Bearer` header.

| Route | Result |
|-------|--------|
| `GET me` | `{id, name, role, identity}`; `identity` is the LiveKit identity (`u<id>`) the client will have in a room |
| `GET rooms` | `{rooms: [{id, name, description, kind}]}`: exactly the rooms the browser page lists for that user (`VisibleChannels`: general rooms, the user's own match rooms, orga and admins see every live room; archived never) |
| `POST rooms/{room}/token` | the same payload as the browser, from the same action (`IssueJoinToken`): `{url, token, identity, muted, audio}`. A moderator mute arrives as `muted: true` and a token without the publish right. Never takes identity or room name from the client. |
| `GET config` | `{api_version, livekit_url, voice_ready, audio, modules}`: the LiveKit URL the client must use and the audio tuning defaults (bitrate, DTX, RED, `jitter_buffer_target_ms`). `modules` maps every module key (the `MODULE_<KEY>` names, e.g. `game_servers`, `catering`, `voting`, `files`, `tournaments`, `schedule`, `lfg`) to whether this deployment runs it, so the client can hide what the event does not use. No secrets. |

A room the caller may not see answers `404`, exactly like a room that does not exist (no
enumeration, no 403). An archived room answers `410` for admins and `404` for everyone else. `503`
means the LiveKit server is not configured or not reachable.

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
* **Throttles:** device requests 5 per minute per IP; token polling 30 per minute per IP and per
  device code; the approval form per user; the API per token.
* **Short codes are not secrets worth guessing:** 31 symbols over 8 characters (about 8.5e11), 10
  minutes to live, approval throttled.
* Phishing risk of any device flow (someone talks a user into entering their code) is reduced by
  showing the device name and the explicit warning on the approval page.

## Files

`app/Modules/WebVoice/NativeClient/` (actions, policy, models, controllers, bearer middleware),
`routes/voice-client.php` and `routes/voice-client-play.php` (loaded from `bootstrap/app.php` without the `web` group), config in
`config/webvoice.php` under `native`, page `resources/js/pages/WebVoice/Connect.vue`.
