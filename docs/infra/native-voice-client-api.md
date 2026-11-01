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
minute). The v1 API is voice only: **there is no chat API in v1**. A missing, malformed, unknown,
revoked or expired token is `401` with a `WWW-Authenticate: Bearer` header.

| Route | Result |
|-------|--------|
| `GET me` | `{id, name, role, identity}`; `identity` is the LiveKit identity (`u<id>`) the client will have in a room |
| `GET rooms` | `{rooms: [{id, name, description, kind}]}`: exactly the rooms the browser page lists for that user (`VisibleChannels`: general rooms, the user's own match rooms, orga and admins see every live room; archived never) |
| `POST rooms/{room}/token` | the same payload as the browser, from the same action (`IssueJoinToken`): `{url, token, identity, muted, audio}`. A moderator mute arrives as `muted: true` and a token without the publish right. Never takes identity or room name from the client. |
| `GET config` | `{api_version, livekit_url, voice_ready, audio}`: the LiveKit URL the client must use and the audio tuning defaults (bitrate, DTX, RED, `jitter_buffer_target_ms`). No secrets. |

A room the caller may not see answers `404`, exactly like a room that does not exist (no
enumeration, no 403). An archived room answers `410` for admins and `404` for everyone else. `503`
means the LiveKit server is not configured or not reachable.

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
`routes/voice-client.php` (loaded from `bootstrap/app.php` without the `web` group), config in
`config/webvoice.php` under `native`, page `resources/js/pages/WebVoice/Connect.vue`.
