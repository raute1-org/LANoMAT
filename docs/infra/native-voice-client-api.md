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
minute). Besides voice it carries text chat, realtime channel auth and notifications (sections
below). A missing, malformed, unknown,
revoked or expired token is `401` with a `WWW-Authenticate: Bearer` header.

| Route | Result |
|-------|--------|
| `GET me` | `{id, name, role, identity}`; `identity` is the LiveKit identity (`u<id>`) the client will have in a room |
| `GET rooms` | `{rooms: [{id, name, description, kind}]}`: exactly the rooms the browser page lists for that user (`VisibleChannels`: general rooms, the user's own match rooms, orga and admins see every live room; archived never) |
| `POST rooms/{room}/token` | the same payload as the browser, from the same action (`IssueJoinToken`): `{url, token, identity, muted, audio}`. A moderator mute arrives as `muted: true` and a token without the publish right. Never takes identity or room name from the client. |
| `GET config` | `{api_version, livekit_url, voice_ready, audio, realtime}`: the LiveKit URL the client must use, the audio tuning defaults (bitrate, DTX, RED, `jitter_buffer_target_ms`) and the Reverb connection info (see "Realtime"). No secrets. |

A room the caller may not see answers `404`, exactly like a room that does not exist (no
enumeration, no 403). An archived room answers `410` for admins and `404` for everyone else. `503`
means the LiveKit server is not configured or not reachable.

## Text chat

Same rules as the browser (`/talk`), because the endpoints call the browser's own query and actions
(`ChannelMessages`, `PostMessage`, `DeleteMessage`). A room the caller may not see is `404` (never
403), an archived room `410` for admins and `404` for everyone else.

| Route | Result |
|-------|--------|
| `GET rooms/{room}/messages?before=<id>` | `{messages: [...], next_before}`. Newest page first: without `before` you get the latest page (size `webvoice.chat.history`, default 50), with `before` the page of older messages. Inside a page messages are oldest first, ready to append. `next_before` is the id to pass as `before` for the next older page, `null` when the history is exhausted. |
| `POST rooms/{room}/messages` | body `{body}` (plain text, trimmed, 1 to 500 characters). `201 {message}`; `422` for empty or too long; `429` when the per-user limit is hit (10 messages per 10 s, the budget is shared with the browser). The author is always the token's user. |
| `DELETE messages/{message}` | moderation, admin and orga only (policy `moderate`): `200 {ok: true}`; `403` for everyone else who can see the room, `404` when the room is not visible. |

Message DTO (identical to the browser's payload and to the realtime event):

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

`app/Modules/WebVoice/NativeClient/` (actions, policy, models, controllers, bearer middleware),
`routes/voice-client.php` (loaded from `bootstrap/app.php` without the `web` group), config in
`config/webvoice.php` under `native`, page `resources/js/pages/WebVoice/Connect.vue`.
