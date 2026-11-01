# Command contract of the desktop client

The single contract between the Rust side (`clients/voice/app/src`) and the UI. Every command below
is a Tauri command, called with `window.__TAURI__.core.invoke(name, args)` (the app sets
`withGlobalTauri`), and every event is received with `window.__TAURI__.event.listen(name, cb)`
(`cb` gets `{payload}`). A test (`commands_and_events_match_this_document`) fails when a command or
event exists in the code but not here, or the other way round; the headless IPC self-test
(`app/tests/selftest.js`, run by `app/tests/smoke-features.sh`) calls most of them through the real
IPC with exactly the argument names written here.

## Conventions

- **Arguments** are a single JSON object with **camelCase** keys (`{ roomId: 3 }`); Tauri maps them
  to the snake_case Rust parameters. A command without arguments is called without a second
  argument. Optional arguments may be left out.
- **Results** are JSON. Objects defined by the client (this document) use `snake_case` keys. The
  objects that are the server's own DTOs are passed through with the keys the server documents in
  [`docs/infra/native-voice-client-api.md`](../../docs/infra/native-voice-client-api.md): the play API
  (now cards, tournaments, matches, game servers) is **camelCase**, the event features (events,
  polls, catering, files, chat, notifications) are **snake_case**. Unknown fields are ignored and
  missing ones default (`null`, `false`, `0`, `[]`), so an older or newer server never breaks the UI.
- **Money** is integer cents, **times** are ISO 8601 strings, **ids** are numbers (notification ids
  are UUID strings, ids of "now" cards are strings like `match:88`).
- **Events** are named in kebab-case and carry the payloads listed under "Events".
- A command that is not listed here does not exist (invoking it rejects with a string).
- Event slugs (`event`) come from `list_events` / `current_event`. Hide what the event does not use
  with `get_config().modules` (a module key that is missing means "unknown": show the feature).

## The error object

Every command that can fail rejects with one shape:

```json
{
    "kind": "validation",
    "message": "Du hast schon abgestimmt.",
    "retryable": false,
    "code": "already_voted",
    "retry_after_secs": null,
    "fields": { "option_id": ["..."] }
}
```

`kind`, `message` and `retryable` are always present; `code`, `retry_after_secs` and `fields` only
when there is something to say (the key is then absent).

| `kind`              | Meaning                                                                                                                                                                                                                       | `retryable`                              | What the UI does                              |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- | --------------------------------------------- |
| `auth`              | token rejected (revoked or expired) or not connected (HTTP 401). `auth-expired` is sent.                                                                                                                                      | no                                       | go to "Verbinden"                             |
| `forbidden`         | the server refuses this action (403)                                                                                                                                                                                          | no                                       | show `message`                                |
| `not_found`         | unknown or not visible (404); also a module that is switched off                                                                                                                                                              | no                                       | show `message`, refresh the list              |
| `conflict`          | the object changed (409, stale `lock_version`); `code` e.g. `tournaments.errors.stale_match`                                                                                                                                  | no                                       | reload, then let the person retry             |
| `gone`              | not there any more (410, archived room)                                                                                                                                                                                       | no                                       | show `message`                                |
| `validation`        | a rule or input error (422); `message` is the server's German text, `code` its stable code (`already_voted`, `not_open`, `item_limit`, `quota_exceeded`, `tournaments.errors.checkin_closed`, ...), `fields` the input errors | no                                       | show `message`; mark `fields`                 |
| `rate_limited`      | too many requests (429); `retry_after_secs` when the server says                                                                                                                                                              | yes                                      | calm hint, retry after the delay              |
| `unavailable`       | the server cannot do it right now (503)                                                                                                                                                                                       | yes                                      | "Erneut versuchen"                            |
| `server`            | any other server error; `retryable` is true for 5xx                                                                                                                                                                           | 5xx only                                 | "Erneut versuchen" when retryable             |
| `network`           | server unreachable, timeout, or an answer that is not LANoMAT                                                                                                                                                                 | yes (not for a bad answer)               | offline state, keep trying in the background  |
| `transfer`          | a file transfer failed; `code` is `ticket_expired`, `too_large`, `blocked_type`, `quota_exceeded`, `uploads_unavailable`, `host_full`, `upload_lost`, `link_expired`, `empty_file`, `network`, `forbidden`                    | `network`, `upload_lost`, `link_expired` | show `message`; offer "Erneut" when retryable |
| `local`             | a local problem (files, audio device, no URL handler, no clipboard)                                                                                                                                                           | no                                       | show `message`                                |
| `usage`             | bad input (an invalid address or file, a refused link)                                                                                                                                                                        | no                                       | show `message`                                |
| `denied`, `expired` | the connect flow only (pairing refused / code expired)                                                                                                                                                                        | no                                       | see `connect_poll`                            |

The `message` is always German and ready to show; it never contains the access token or a signed
URL.

## Events

| Event              | Payload                                                                                 |
| ------------------ | --------------------------------------------------------------------------------------- |
| `room-updated`     | a `RoomView` (see `get_room_view`)                                                      |
| `shortcut-status`  | `{active: bool, message: string \| null}` after the global shortcut was (re)registered  |
| `settings-changed` | the `Settings` object after the tray changed a setting                                  |
| `chat-event`       | a chat event, tagged by `type` (below)                                                  |
| `transfer-updated` | a `TransferView` (below), sent on every state change and about every 150 ms of progress |
| `notify-event`     | a notification engine event, tagged by `type` (below)                                   |
| `quiet-changed`    | `{quiet: bool}`: the "Ruhe" toggle changed (UI or tray)                                 |
| `auth-expired`     | `{}`: the server rejected the token; background work stopped; show "Verbinden"          |
| `audio-level`      | `{level: number}` from 0 to 1 while `audio_check_start` runs (about 16 per second)      |

### `chat-event` payloads

```json
{ "type": "message_posted", "room_id": 3, "message": { "id": 412, "channel_id": 3, "body": "gl hf", "created_at": "...", "author": { "id": 7, "name": "Ada", "avatar_url": null } } }
{ "type": "message_deleted", "room_id": 3, "message_id": 412 }
{ "type": "channels_changed" }
{ "type": "status", "state": "live", "detail": null }
```

- `channels_changed`: the room list changed, call `list_rooms` again.
- `status.state` is `connecting`, `live` (WebSocket up), `reconnecting`, `polling` (the REST
  fallback is active; `detail` says why) or `offline` (the token was rejected, `auth-expired` follows).
  Show it as a calm hint, never as an error wall; messages keep arriving in `polling`.
- After a reconnect the client fetches what it missed, so a message can arrive twice: **de-duplicate
  by `message.id`**. The history you fetched yourself (`chat_history`) is never replayed.

### `notify-event` payloads

```json
{ "type": "unread", "count": 3 }
{ "type": "delivered", "notification": { "category": "match", "title": "Dein Match startet", "body": "...", "target": { "screen": "spielen", "card": "match" }, "count": 1 } }
{ "type": "auth_expired" }
```

- `unread`: the bell badge number, sent when it changes (and once at the start).
- `delivered`: a system notification was raised; `count` is 1, or the number of grouped ones in a
  "N weitere Benachrichtigungen" summary. `target.screen` is one of the five tabs `jetzt`, `sprache`,
  `spielen`, `essen`, `mehr`; `target.card` a hint for the card or section to focus (`match`,
  `checkin`, `tournaments`, `orders`, `chat`, `vote`, `schedule`, `notifications`).
- The desktop notification plugin has **no click callback**. Call `take_notification_target` when the
  window comes to the front (the `tauri://focus` window event, or the tray "Anzeigen") and navigate
  to the returned target.

### `TransferView` (events and `list_transfers`)

```json
{
    "id": 1,
    "kind": "download",
    "name": "maps.zip",
    "status": "active",
    "bytes_done": 1048576,
    "total": 6291456,
    "speed_bps": 5242880,
    "eta_secs": 1,
    "resumed": false,
    "path": null,
    "error": null
}
```

`kind` is `download` or `upload`; `status` is `queued`, `preparing`, `active`, `reconnecting`
(connection lost, retrying with backoff), `paused`, `done`, `error` or `cancelled`. `total` is 0 while
unknown. `path` is the final file of a finished download. `error` is
`{code, message, retryable}` (same codes as the `transfer` error kind). `resumed` is true when an
interrupted transfer continued where it stopped (after a connection loss, a pause or an app restart).
At most two uploads and three downloads run at once; the rest wait in `queued`.

## Voice and connection (existing)

### `get_status`

Result `{state, server_url, user_name, message}`; `state` is `signed_out`, `connected`, `expired` or
`unreachable`. Also starts notifications when connected.

### `connect_start`

Args `{serverUrl}`. Result `{user_code, verification_url, verification_url_complete, expires_in, device_name, next_in_ms}`.

### `connect_poll`

Result `{status: "pending", next_in_ms}`, `{status: "connected", user_name}` or `{status: "failed", kind, message}` (`kind` `denied`, `expired`, `network`, `no_flow`).

### `connect_cancel`

Abandons a pending connect flow.

### `list_rooms`

Result `Room[]`: `{id, name, description, kind}` (`kind` `general` or `match`).

### `join_room`

Args `{roomId}`. Result a `RoomView`.

### `leave_room`

Leaves the current room.

### `audio_check_start`

Args `{inputDevice?}` (a device name or part of one, like the settings; `null` is the system default). Starts the microphone level meter of the first-run guide: `audio-level` events follow until `audio_check_stop`. A second start replaces the first. Refused with `local` while a room is joined. In the headless smoke (synthetic audio) a 440 Hz tone drives the meter.

### `audio_check_stop`

Stops the meter (sends a final `audio-level` of 0 when it was running).

### `play_test_tone`

Args `{outputDevice?}`. Plays one second of 440 Hz on the device and returns when it is over. Refused with `local` while a room is joined.

### `set_talking`

Args `{talking}`. Result `bool` (the new state). Rejects with kind `ptt` when there is no room.

### `set_muted`

Args `{muted}`. The hard mute. Result a `RoomView`.

### `get_room_view`

Result `{phase, room, talking, muted, listen_only, open_mic, participants, error}`; `phase` is `idle`, `connecting`, `connected`, `reconnecting` or `failed`; a participant is `{identity, name, speaking, muted, is_self}`.

### `list_devices`

Result `{inputs: string[], outputs: string[], synthetic: bool}`.

### `load_settings`

Result the `Settings` object: `{server_url, input_device, output_device, open_mic, headset_mode, close_to_tray, overlay, ptt_shortcut, download_dir, notify_categories, notify_seen}`.
`download_dir` is a path or `null` (the platform's download folder). `notify_categories` maps a
notification category (`match`, `catering`, `direct`, `vote`, `registration`, `checkin`, `teams`,
`friends`, `schedule`, `orga_ping`, `discord`, `account`, `system`, `other`) to `true` or `false` and
overrides the defaults (on: `match`, `catering`, `direct`, `vote`; off: everything else).
`notify_seen` is bookkeeping owned by the app: send it back unchanged, it is ignored on save.

### `save_settings`

Args `{settings}` (the whole `Settings` object). Result `{settings, shortcut: {active, message}}`.

### `get_environment`

Result `{wayland, synthetic_audio, settings_path, socket_path, socket_error, shortcut, version}`.

### `logout`

Forgets the token locally and stops notifications and chat. Revoking it on the server is done on the website.

### `open_url`

Args `{url}`. Opens the pairing page (or `/talk/connect` of the connected server) in the browser; any
other address is refused (`usage`). The allow-list is deliberately strict.

### `quit`

Ends the app.

## Overview and play

### `get_config`

Result `{api_version, voice_ready, modules, realtime}`. `modules` maps a module key
(`game_servers`, `catering`, `voting`, `files`, `tournaments`, `schedule`, `lfg`, ...) to whether the
deployment runs it. `realtime` is a bool: whether live chat is configured (else the client polls).
The connection details are never handed to the UI.

### `list_events`

Result `{current_event_slug, events: [{id, slug, name, status, starts_at, ends_at, location, current, registration}]}`; `registration` is `null` or `{status, ticket_type, paid, checked_in}`.

### `current_event`

Result the event to preselect (an object like the entries of `list_events`) or `null`.

### `now_feed`

Args `{event}`. Result `{cards, more}`; a card is `{kind, id, state, title, body, at, data}` (see "The now feed" in the server doc: `kind` `match`, `vote`, `order`, `notification`, `schedule`).
A vote of mine that closes within ten minutes also raises a (grouped, quiet-aware) system
notification, once per poll: call this regularly while the "Jetzt" tab is shown (every 30 to 60 s).

### `list_tournaments`

Args `{event}`. Result `Tournament[]` (server DTO, camelCase: `id, name, game, format, status, startsAt, checkinOpensAt, checkinClosesAt, entryCount, maxEntries, teamSize, myEntry, can: {checkIn, withdraw}, registration`).

### `list_my_matches`

Args `{event}`. Result `Match[]` (server DTO, camelCase: `id, phase, status, tournament, round, bracket, me, opponent, score, won, lockVersion, pendingReport, voiceRoomId, server, can: {report, confirm, dispute, withdrawReport, checkIn}`). `voiceRoomId` is a room id for `join_room`; `server` is a `GameServer`.

### `tournament_checkin`

Args `{tournamentId}`. Result the updated `Tournament`.

### `match_report`

Args `{matchId, score1, score2}` (own : opponent, no tie). Result the updated `Match`. A tie is `validation` with `fields.score2`.

### `match_confirm`

Args `{matchId, lockVersion}`. Result the updated `Match`. A stale `lockVersion` is `conflict`: reload `list_my_matches` and retry.

### `match_dispute`

Args `{matchId}`. Result the updated `Match`.

### `match_withdraw_report`

Args `{matchId, lockVersion}`. Result the updated `Match`.

### `list_event_servers`

Args `{event}`. Result `GameServer[]` (camelCase: `id, title, game, matchLabel, description, status, players, maxPlayers, map, checkedAt, address, port, connectString, connectIsLink, installHint: {steamUrl, shareUrl, versionNote}, joinUrl, copyText`).
Show a join button only when `joinUrl` is set (`open_join_url`), a copy button when `copyText` is set
(`copy_text`); an offline or starting server has neither.

### `list_permanent_servers`

Result `GameServer[]` of the permanent servers (Minecraft, UT2004, ...).

### `open_join_url`

Args `{url}`. Opens a game server's join link with the system handler. **Only
`steam://connect/<host>:<port>` and `steam://connect/<host>:<port>/<password>`** are accepted (host
name or IPv4, numeric port, password of letters, digits, `.`, `_`, `~`, `-`); anything else (other
schemes, other steam commands, queries, encodings, whitespace) is `usage`. A machine without a handler
answers `local`: fall back to `copy_text`.

### `copy_text`

Args `{text}` (at most 4096 characters). Puts the text on the clipboard. Rejects with `local` when
there is no clipboard.

## Polls, catering

### `list_polls`

Args `{event}`. Result `{event_slug, polls: [{id, question, kind, status, is_open, closes_at, total_votes, my_vote_option_id}]}`.

### `get_poll`

Args `{pollId}`. Result `{id, event_slug, question, kind, status, is_open, closes_at, total_votes, options: [{id, label, count, percent}], my_vote_option_id}`.

### `vote_poll`

Args `{pollId, optionId}`. Result the updated poll detail. One vote, no changing it: `validation` with `code` `already_voted`; `not_open` for a closed poll.

### `get_catering`

Args `{event}`. Result `{event_slug, registration_required, limits: {items_per_user, my_open_items}, orders: [{id, title, status, is_open, opens_at, closes_at, menu: [{key, name, price_cents}], my_items: [...], my_total_cents}]}`.

### `my_catering_items`

Args `{event}`. Result `{items: [{id, option_key, option_name, note, price_cents, paid, order_id, order_title, order_status, order_is_open, can_cancel}], total_cents, paid_cents}`.

### `place_food_item`

Args `{orderId, optionKey, note?}` (note at most 200 characters). Result the placed item
`{id, option_key, option_name, note, price_cents, paid}`. Refusals are `validation` with `code`
`not_open`, `unknown_option`, `registration_required` or `item_limit`.

### `cancel_food_item`

Args `{itemId}`. Cancels an item while its order window is open (`validation` `not_open` afterwards).

## Profile, linked accounts, friends

The own profile, the linked accounts (Steam, Twitch; Discord is the read-only login identity) and
friends, as in the browser (`/settings/profile`, `/settings/connections`, `/friends`). The acting
person is always the token owner. Results are the server's DTOs with **snake_case** keys. The
friends commands answer `not_found` when the server has the Friends module switched off (hide the
screen with `get_config().modules`). The lists show display fields only (`id, name, avatar_url`),
never an e-mail or Discord id, and there is no presence and no user search: people are found in
`list_friend_suggestions` or by the user ids other screens already carry.

### `get_profile`

Result `{id, name, email, email_verified, avatar_url, bio, steam_url, stream_url, profile_color, has_verified_steam_link, verified_steam_nickname, editable: string[]}` (`email`, `avatar_url`, `bio`, `steam_url`, `stream_url`, `profile_color`, `verified_steam_nickname` may be `null`). `editable` lists the fields the person may change; the avatar is the Discord one and read-only.

### `update_profile`

Args `{changes}`, an object with any of `name`, `email` (text, cannot be cleared) and `bio`, `steam_url`, `stream_url`, `profile_color` (text, or `null` to clear). **A key that is left out keeps its value**; unknown keys are ignored (so is `role` and everything else); an empty object is `usage`. Server rules: name up to 255, unique valid e-mail (a changed e-mail resets its verification), bio up to 1000, `steam_url` starts with `https://steamcommunity.com/`, `stream_url` is https, `profile_color` is `#rrggbb`. Result the updated profile (as `get_profile`). A rule violation is `validation` with `fields` (`{"email": ["..."]}`).

```json
{ "changes": { "bio": "Hallo!", "stream_url": null } }
```

### `list_connections`

Result `Connection[]`: `{provider, label, status, external_name, available, can_link, can_unlink, read_only}`. `provider` is `discord` (always first, `read_only: true`, never linkable or removable), `steam` or `twitch`. `status` is `connected`, `not_connected` or `needs_reauth` (link again to renew). `can_link` is false for a connected account and for a provider the deployment has no credentials for (`available: false`: show it greyed out). `external_name` is the name at the provider (or `null`). Tokens are never returned.

### `unlink_connection`

Args `{provider}` (`steam` or `twitch`). Result the new `Connection` state. Idempotent; `discord` or an unknown provider is `not_found`.

### `connection_link_url`

Args `{provider}`. Result `{provider, url, expires_at}`: a single-use link into the browser (valid 10 minutes, works once). `not_found` when the provider is not configured, `rate_limited` after 10 per minute. **Linking flow:** call this, pass the `url` to `open_link_url`, then poll `list_connections` every few seconds (until `expires_at` plus about a minute for the provider round trip) until that provider's `status` is `connected`. Do not show or log the URL.

### `open_link_url`

Args `{url}`. Opens the link of `connection_link_url` with the system browser. **Only** an `https` URL whose origin equals the logged-in server's and whose path starts with `/settings/connections/link/` followed by a token is accepted (no credentials, no fragment, no further path segments, no encoded tricks); anything else is `usage`. A machine without a browser handler answers `local`.

### `list_friends`

Result `[{id, name, avatar_url}]`.

### `list_friend_requests`

Result `{incoming: [{id, user}], outgoing: [{id, user}]}` with `user` = `{id, name, avatar_url}`. The request `id` (not the user id) is what `accept_friend_request`, `decline_friend_request` and `cancel_friend_request` take.

### `list_friend_suggestions`

Result `[{id, name, avatar_url, shared, reasons, reason_labels}]`: `shared` is a count, `reasons` are `shared_event`, `shared_team`, `shared_tournament`, `shared_steam_friend`, `reason_labels` the German copy to show. Never lists friends, pending requests or blocked people.

### `list_blocked_users`

Result `[{id, name, avatar_url}]`.

### `send_friend_request`

Args `{userId}`. Result `{status}`: `pending`, or `accepted` when the other side had already asked. No request id comes back: re-read `list_friend_requests`. Refusals are `validation` with `code` `cannot_friend_self`, `already_friends`, `request_pending` or `blocked` (the person's own block) and the German `message`. Someone who blocked you is never revealed: you just see `pending`. `rate_limited` after 20 per minute.

### `accept_friend_request`

Args `{requestId}`. Result `{status: "accepted"}`. Only the addressee may answer (`forbidden` otherwise; a request of two other people is `not_found`).

### `decline_friend_request`

Args `{requestId}`. Result `{status: "declined"}`. Same rules as accept.

### `cancel_friend_request`

Args `{requestId}`. Cancels an own outgoing request. Result `null`.

### `remove_friend`

Args `{userId}`. Removes a friend (also fine when there was none). Result `null`.

### `block_user`

Args `{userId}`. Blocks, which also removes the friendship and pending requests in both directions. Result `null`. Blocking yourself is `validation` with `code` `cannot_block_self`.

### `unblock_user`

Args `{userId}`. Result `null`.

## Files and transfers

### `list_files`

Args `{event}`. Result `{event_slug, files: [{id, name, size_bytes, uploader_name, visibility, created_at, mine, can_download, can_delete}], quota: {used_bytes, cap_bytes, remaining_bytes}, upload: {resumable, max_file_bytes}}`.
`visibility` `pending` is the person's own file waiting for moderation ("Wird von der Orga geprüft").
Without `upload.resumable` there is no upload.

### `files_quota`

Args `{event}`. Result `{quota, upload}` (cheap to poll during an upload).

### `delete_file`

Args `{fileId}`. Owner or orga only (`forbidden` otherwise).

### `download_file`

Args `{fileId, name?}`. Result `{transfer_id}`. Starts a download into the download folder
(`get_download_dir`); progress arrives as `transfer-updated`. Streams to a `.part` file, resumes with
HTTP Range after a connection loss, renames at the end, never overwrites an existing file (adds
` (1)`), and uses a sanitised file name. The signed link is requested when the job starts and again
on every resume; it is never shown or stored.

### `upload_files`

Args `{event, paths: string[]}` (absolute paths, from `pick_files` or a drag and drop). Result
`[{path, transfer_id, error}]`, one entry per path: `transfer_id` is set when the upload started,
`error` (an error object) when that path was refused (not a regular file, or one of the app's own
files such as the credentials). Uploads use the tus protocol with the server's ticket, send the file
in the ticket's chunks, continue from the server's offset after a connection loss (retries with
backoff, never after a refusal such as `quota_exceeded` or `too_large`), and survive an app restart
(a fingerprint file; start the same file again and it continues).

### `list_transfers`

Result `TransferView[]`.

### `transfer_pause`

Args `{transferId}`. Pauses; partial data is kept.

### `transfer_resume`

Args `{transferId}`. Continues a paused transfer, or retries one that failed with `retryable: true`.

### `transfer_cancel`

Args `{transferId}`. A download's partial file is deleted; an upload is terminated on the file host.

### `transfer_forget`

Args `{transferId}`. Removes a finished (`done`, `error`, `cancelled`) transfer from the list.

### `pick_files`

System file picker. Result `string[]` of absolute paths (empty when cancelled).

### `pick_directory`

System folder picker. Result the path or `null` when cancelled. Store it with `save_settings`
(`download_dir`).

### `get_download_dir`

Result the effective download folder (the setting, else the platform's download folder).

## Notifications

### `list_notifications`

Args `{unreadOnly?, page?, perPage?}` (defaults `false`, 1, 20; `perPage` 1 to 50). Result
`{notifications: [{id, category, title, body, read, read_at, created_at}], page, per_page, total, has_more}`, unread first.

### `unread_count`

Result a number. The app polls this itself (every 15 s with the window focused, every 45 s otherwise, with
backoff) and sends `notify-event` `unread`; call it only for an immediate refresh.

### `mark_notification_read`

Args `{id}` (UUID string). Result the notification.

### `mark_all_notifications_read`

Result the number of notifications marked.

### `get_notification_preferences`

Result `{match: true, ...}`: the **server-side** switches (they gate the bell list and the Discord DMs): `registration`, `checkin`, `match`, `teams`, `friends`, `schedule`, `catering`, `orga_ping`, `discord`.

### `set_notification_preferences`

Args `{preferences: {match: false}}` (partial). Result the effective server-side preferences.
Do not confuse them with `Settings.notify_categories`, which only decides which items become
**system notifications** on this machine (the engine's policy: one system notification per category
and minute, the rest a count; quiet while push-to-talk is held (with an open microphone: while speaking) or "Ruhe" is on; every id is announced
at most once, also across restarts; the first run only records what is already unread).

### `set_quiet`

Args `{quiet}`. The "Ruhe" toggle (do-not-disturb, also in the tray). Result the new value; sends `quiet-changed`. Not persisted across restarts.

### `get_quiet`

Result `bool`.

### `take_notification_target`

Result `{screen, card}` or `null`: where the latest system notification leads, claimable once and
only within five minutes of the notification. Call it when the window comes to the front.

## Chat

### `chat_history`

Args `{roomId, before?}`. Result `{messages, next_before}`: the newest page without `before`, an older
page with it; oldest first inside a page. `next_before` is the value for the next older page, `null`
when the history is exhausted.

### `chat_post`

Args `{roomId, body}`. Result the message. An empty or over-long (500 characters) body is `validation`
without a request; the server's limit (10 per 10 s) is `rate_limited` with `retry_after_secs`: show a calm hint.

### `chat_delete`

Args `{messageId}`. Moderation (orga and admin); `forbidden` for everyone else.

### `chat_subscribe`

Args `{roomIds: number[]}`. Follows the live chat of these rooms (replaces the previous set; an empty
list follows only the room directory). Starts the realtime link on first use; everything arrives as
`chat-event`. A message that mentions me (`@name`, someone else's) also raises a system notification
(category `direct`, target `sprache`/`chat`).

### `chat_unsubscribe`

Stops the realtime link.
