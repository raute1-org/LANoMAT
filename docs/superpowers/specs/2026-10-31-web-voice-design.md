# Web voice chat — design note

Status: implemented in PR "feat(web-voice)". Placeholders only: `<LAN_DOMAIN>`, `<VENUE_SERVER_IP>`,
`<LIVEKIT_API_KEY>`, `<LIVEKIT_API_SECRET>`.

## Goal

People talk to each other in the browser, with no separate client. There are **general channels** (admin-managed, for
example a lobby) and **match channels** (one room per team of a tournament match). Every channel also has a text chat.
Top priority: the lowest possible voice latency.

## Module layout

New optional module `WebVoice` (`Module::WebVoice`, key `web_voice`, `MODULE_WEB_VOICE=true`, off in the `core`
profile). It does **not** replace or change the `Voice` module (Mumble/TeamSpeak): those stay as they are and a LAN can run
both.

```
app/Modules/WebVoice/
  Contracts/WebVoiceClient.php          token + room operations (the only door to LiveKit)
  Domain/ChannelKind.php, RoomParticipant.php, PublishDefaults.php
  Support/LiveKitClient.php             HTTP/JWT implementation (HS256 token, Twirp server API)
  Testing/FakeWebVoiceClient.php        records calls, scripted occupancy
  Models/WebVoiceChannel, WebVoiceMessage, WebVoiceMute
  Policies/WebVoiceChannelPolicy.php
  Actions/                              CreateGeneralChannel (Filament), ProvisionMatchChannels, IssueJoinToken,
                                        PostMessage, DeleteMessage, MuteParticipant, UnmuteParticipant,
                                        RemoveParticipant, CloseChannels
  Queries/VisibleChannels.php           read model: what a viewer may see
  Events/                               MessagePosted, MessageDeleted, ChannelsChanged (broadcast)
  Jobs/ProvisionMatchChannelsJob, CloseChannelsJob
  Listeners/                            ProvisionOnMatchReady, CloseOnMatchCompleted, CloseOnTournamentCompleted
  Http/WebVoiceController.php           page, token, messages, moderation (thin)
  Filament/Resources/WebVoiceChannels   admin: general channels + live rooms
  Console/PruneArchivedCommand.php      deletes old archived match channels with their messages
```

Cross-module edges (added to the architecture baseline on purpose): `WebVoice -> Tournaments` (listens to
`MatchReady`/`MatchCompleted`/`TournamentCompleted`, reads `GameMatch`/`TournamentEntry`/`EntryRoster`) and nothing else.
No other module imports `WebVoice`; the Preflight probe is self-contained (config only), as for the other probes.
`requires()` is empty: with `tournaments` core, the module has no hard optional dependency.

## Data model (additive migrations, reversible)

- `web_voice_channels`: `id`, `kind` (`general`|`match`), `name`, `description` nullable, `game_match_id` nullable
  (cascade), `tournament_entry_id` nullable (cascade), `archived_at` nullable, timestamps. Unique
  (`game_match_id`, `tournament_entry_id`) makes provisioning idempotent. General channels are **installation-wide** (no
  event column): a lobby is useful across LANs, and the admin archives what is not needed. The LiveKit room name is
  derived (`<prefix><id>`), never client-supplied.
- `web_voice_channel_user` (`channel_id`, `user_id`, unique): the **access list of a match channel**, a snapshot of the
  team roster taken when the room is created (same moment as `TournamentEntry::roster_snapshot`). It makes the
  visibility query one indexed lookup and keeps authorization independent of the Tournaments tables at read time.
- `web_voice_messages`: `id`, `channel_id` (cascade), `user_id` (cascade), `body` (text, max 500 enforced in the form
  request), `created_at`. Index (`channel_id`, `id`). Plain text only; the UI renders it as text, never HTML.
- `web_voice_mutes`: (`channel_id`, `user_id`, `muted_by`, timestamps), unique pair. A moderator mute survives a
  re-join because new tokens are minted with `canPublish=false` while a row exists.

## Authorization matrix

| Actor                                   | See channel in list | Join voice | Read / post text | Delete message | Mute / remove | Manage general channels |
| --------------------------------------- | ------------------- | ---------- | ---------------- | -------------- | ------------- | ----------------------- |
| Guest (not logged in)                   | no (route needs `auth`) | no     | no               | no             | no            | no                      |
| Participant, general channel            | yes                 | yes        | yes              | own never      | no            | no                      |
| Participant on the roster of that entry | its match room only | yes        | yes              | no             | no            | no                      |
| Participant of the opposing entry       | **not** that room   | no         | no               | no             | no            | no                      |
| Orga / Helper                           | as participant      | as participant | as participant | no          | no            | no                      |
| Admin                                   | everything          | everything | everything       | yes            | yes           | yes                     |

Everything goes through `WebVoiceChannelPolicy` (`view`, `join`, `post`, `moderate`, `manage`); the admin result comes
from the existing `Gate::before`. Archived channels are refused for everyone, admins included, inside the actions. The
subject of every request is `auth()->user()`: the LiveKit identity is `u<user id>` derived server side, and no
user id is ever read from the request except as the *target* of a moderation call (a moderation call is admin only, and the action only turns that id into the `u<id>` identity for the contract). Broadcast
channel `web-voice.channel.{id}` (private) authorizes through the same `view` ability; the `web-voice.directory`
channel carries an empty payload ("reload your list") and is open to any logged-in user.

## Token flow

1. `POST /talk/channels/{channel}/token` (auth, `can:join`, throttled).
2. `IssueJoinToken` loads the viewer's mute state and asks `WebVoiceClient::issueToken(room, identity, name, canPublish)`.
3. `LiveKitClient` signs an HS256 JWT (`firebase/php-jwt`): `iss` = API key, `sub` = identity, `name`, `nbf`, `exp` =
   now + **10 minutes**, `video` = `{roomJoin: true, room, canSubscribe: true, canPublish: !muted, canPublishData: false,
   canPublishSources: ["microphone"], canUpdateOwnMetadata: false}`. The API secret never leaves the server.
4. The response is `{url, token, identity, audio}`: the public `wss://` signaling URL, the token and the audio publish
   defaults from config. The browser connects with `livekit-client` and the SFU forwards media; Laravel is not in the
   media path.

Server API calls (`ListParticipants`, `RemoveParticipant`, `UpdateParticipant`) use a second short-lived JWT with
`roomAdmin` for that room, sent as `Authorization: Bearer` to `POST <api_url>/twirp/livekit.RoomService/<Method>`.
Muting a live participant uses `UpdateParticipant` with `permission.canPublish=false` (enforced by the SFU) plus a
`web_voice_mutes` row; unmuting reverses both. Removing a participant calls `RemoveParticipant`.

## Latency choices

Voice is audio only, so the budget is capture (about 20 ms frame) + encode + network + jitter buffer + decode + playout.

| Choice | Value | Why it lowers or protects latency |
| ------ | ----- | --------------------------------- |
| Topology | SFU (LiveKit), no mixing or transcoding | The server only forwards RTP: no decode/encode stage (an MCU adds a full codec hop plus mixing buffers) |
| Media path | direct UDP (`rtc.udp_port: 7882`, one mux port) to host candidates, no TURN, no relay | No extra hop. On a LAN the host candidate is the shortest path |
| TCP fallback | `rtc.tcp_port: 7881` only as last resort | TCP retransmission and head-of-line blocking add 100 ms and more; it is a fallback, not the default |
| Signaling | WSS through Traefik, only at join / mute / reconnect | Never in the media path; its extra proxy hop costs nothing at runtime |
| Codec | Opus, mono, 48 kHz, `AudioPresets.speech` = **24 kbps** max | Opus has the lowest algorithmic delay (5 ms look-ahead) of the WebRTC codecs; 24 kbps speech is transparent and keeps packets small (about 60 bytes per 20 ms frame) so they never queue behind each other |
| Frame size (ptime) | **20 ms** (browser default) | Lower values only raise packet rate and overhead; livekit-client does not expose ptime. 10 ms is possible only by SDP munging, which was rejected as not worth the fragility |
| DTX | **on** | Silence is not sent: less load and fewer packets competing with speech; no added delay |
| RED | **on** (default) | Redundant audio in the next packet hides single losses without a NACK round trip; no added delay, about 2x payload (still < 100 kbps per speaker) |
| Capture processing | `echoCancellation`, `noiseSuppression`, `autoGainControl` **on**, `channelCount: 1` | Browsers run these on the 10 ms capture frames, not on a separate buffer. On laptops without headsets echo cancellation is needed or people cannot use open speakers. Switching them off saves a few ms at most and costs usability |
| Mic start | Join first (listen only), microphone on demand; publish once, then **mute/unmute the same track** | Un-muting an existing track is instant (no renegotiation, no `getUserMedia`); push-to-talk uses this, so keydown to audio is one frame |
| Subscriptions | `adaptiveStream` and `dynacast` off (audio only) | Both are video features; off avoids needless signaling |
| Jitter buffer | browser default (adaptive, a few tens of ms on a LAN) | Not tunable from JS in a supported way; LiveKit's `playoutDelay` stays at its default |
| Host | `network_mode` bridge with published UDP is fine; `host` network is the lowest-overhead alternative (runbook) | NAT in the kernel costs microseconds; the userland proxy is not used for the published UDP range |

Expected one-way mouth-to-ear on a wired LAN: about 40 to 70 ms (20 ms capture frame + 20 ms packetization already
inside it, a few ms network and SFU, 20 to 40 ms adaptive jitter buffer and playout). **This was not measured** (no
real LiveKit server or browser in this environment); the manual verification steps are in the runbook.

## Text chat

Persisted in PostgreSQL, delivered over Reverb on the private channel `web-voice.channel.{id}` using
`ShouldBroadcastNow` (no queue hop, so chat is as quick as the websocket). Limits: 500 characters, trimmed, non-empty,
per-user rate limiter `web-voice-chat` (10 messages per 10 seconds, by user id, in `AppServiceProvider`). Admins delete a
message (`MessageDeleted` event removes it live). History: the last 50 messages are loaded on selecting a channel;
older ones page with `?before=<id>`. Plain text only, no uploads.

## Match-room lifecycle

- `MatchReady` -> `ProvisionMatchChannelsJob`: for each of the two entries, `firstOrCreate` a channel (name = entry
  display name, description = tournament, round), then snapshot the roster user ids into the access list. Idempotent.
  A `ChannelsChanged` broadcast tells open pages to reload their list. No LiveKit call is needed: LiveKit creates a room
  on first join and drops it after its empty timeout, so provisioning cannot fail because the SFU is down.
- `MatchCompleted` -> `CloseChannelsJob` for that match; `TournamentCompleted` -> `CloseChannelsJob` for every
  remaining channel of that tournament (matches that never completed).
- `CloseChannelsJob`: for each channel ask the contract for the participants. **Empty -> archive at once**
  (`archived_at`, removed from every list). **Occupied -> keep**, look again after 2 minutes (not queued on the `sync`
  queue), the same rule as `CleanupTournamentVoiceJob`. If LiveKit cannot be reached the channel is treated as occupied
  (never remove a room under people on a guess).
- `webvoice:prune` (scheduled daily) deletes archived match channels older than 7 days with their messages; archived
  general channels stay until an admin deletes them.

## Admin UI

Filament resource `WebVoiceChannelResource` (admin only, `manage` ability; the policy's `viewAny` is true for everyone because
it backs the participant page, so the resource overrides `canViewAny`): create / rename / archive / restore general
channels; match channels are listed read-only. The table has a live "Anwesend" column from
`WebVoiceClient::participants()` that polls every 10 seconds (an unreachable server shows a note instead of failing the
page). Removing a participant, muting and deleting a message are done in the room on the participant page, where the admin
sees who is talking; there is no separate remove action in Filament.

## Participant UI

Page `WebVoice/Index` at `/talk` (sidebar entry "Sprachchat"): channel list (only what the viewer may see), per room
join/leave, microphone toggle, push-to-talk option, speaking indicator, participant list and text chat. `livekit-client`
is dynamically imported on first join so the bundle of every other page is unchanged. Empty, loading, error and normal
states, German copy in `lang/de/web_voice.php`.

## Risks and open points

- **Not verified against a real server or browser** here. Token claim names and the Twirp calls follow the official docs
  (checked with ctx7); the manual checklist is in `docs/infra/livekit-web-voice.md`.
- LiveKit needs UDP reachability from every participant device; client isolation on venue Wi-Fi (AP isolation) blocks it
  even on the same subnet. TURN is deliberately off.
- A mute row blocks a banned speaker from re-joining with publish rights, but a determined moderator-evader cannot get
  around it because the token is issued by the server; a *remove* is not a ban (they can re-join).
- Occupancy numbers in the participant list of channels one has not joined are not shown (would need LiveKit webhooks);
  listed as a follow-up.
- Roster changes after the room was created do not change who may enter it (snapshot, like `roster_snapshot`).
- Production prerequisites (not done by this change): a `livekit` container, the UDP port open, a trusted certificate
  for the signaling name, `MODULE_WEB_VOICE=true`.

## Notes from implementation

- Realtime events use `ShouldBroadcastNow` and the repository's `RetriesBroadcast` trait (a repository test requires it on every
  broadcast event); a Reverb outage is caught and reported, the saved message is never lost.
- `/broadcasting/auth` needs a CSRF token. The app layout had no `csrf-token` meta tag (no page used a private channel before),
  so one was added to `app.blade.php`. Known edge: after a logout and a new login in the same tab without a reload, Echo still
  holds the old token and chat realtime auth fails until a reload (HTTP history and posting keep working).
- The Traefik `Permissions-Policy` said `microphone=()`, which would have blocked `getUserMedia` everywhere. It is now
  `microphone=(self)`.
- Orga and helpers get **no** extra rights in this module: every moderation and visibility privilege is admin only, as
  in the brief. Say so if orga should moderate the rooms of their event.

## Provisional defaults (to be confirmed by the owner)

Only "moderation is admin only" is an owner decision. These are provisional defaults, not built around any further:
general channels are installation-wide (not per event); archived match chat is deleted after 7 days; LiveKit
webhooks (live occupancy in the list) are a follow-up, not part of this change.
