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
minute). The room endpoints below are voice only; event functions follow in [Event features](#event-features-v1). A missing, malformed, unknown,
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

`app/Modules/WebVoice/NativeClient/` (actions, policy, models, controllers, bearer middleware; the event feature controllers live in `Http/Features/`),
`routes/voice-client.php` (loaded from `bootstrap/app.php` without the `web` group), config in
`config/webvoice.php` under `native`, page `resources/js/pages/WebVoice/Connect.vue`.
