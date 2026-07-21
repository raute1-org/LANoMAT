# Discord Gateway Bot — Design

**Date:** 2026-07-21
**Status:** Draft (awaiting review)
**Supersedes:** the "No bot process / No gateway connection" rule in `CLAUDE.md`
(amended, not removed — see §12).

## 1. Goal

Give the LANoMAT Discord app a **persistent Gateway connection** so it:

1. shows as **online** (green status) with a configurable activity, and
2. receives **Gateway events** that the HTTP Interactions endpoint cannot
   deliver — slash-command interactions, guild member join/leave, voice-state
   updates, and message/reaction events.

The business logic stays in **PHP/Laravel** (testable, single source of
truth). A thin Node **discord.js sidecar** owns only the Gateway transport and
bridges events to the app over the internal Docker network.

## 2. Scope

**In scope (this phase):**

- A `docker/discord-gateway/` Node sidecar (discord.js v14) that connects to
  the Gateway, sets presence, and forwards events to the app.
- An **internal PHP ingress** (`/internal/discord/gateway`) protected by a
  shared secret, that dispatches forwarded events.
- **Interaction handling over the Gateway**, reusing the existing
  `CommandRouter` and command handlers at full parity; retiring the public
  HTTP Interactions endpoint.
- **Voice presence:** a read-model of who (mapped LANoMAT users) is in which
  Discord voice channel, updated from `VOICE_STATE_UPDATE`, exposed via a
  No-PII projection and an empty broadcast (mirrors the M10/M11 pattern).
- **Member join/leave** and **message/reaction** events surfaced as typed
  Laravel events with logging listeners (extension points).
- Intents wiring, config/env, compose service, docs, and the CLAUDE.md
  amendment.

**Out of scope (explicit non-goals, deferred to follow-ups):**

- Concrete product reactions for member events (role-sync, welcome DM) and for
  messages/reactions (reaction-to-register). Only the typed events + logging
  listeners ship now.
- Reading **message content** (`MESSAGE_CONTENT` privileged intent) — not
  requested by any shipped behavior, so it is deliberately not enabled yet.
- Moving the LANoMAT→Discord **outbound** REST path (`DiscordClient` /
  `HttpDiscordClient`, announcements, match channels, DMs). That stays exactly
  as-is; this phase only adds an **inbound** path.

## 3. Current state (what changes, what doesn't)

- **Unchanged:** `DiscordClient` contract + `HttpDiscordClient` (outbound
  REST), the domain event listeners that call it (`AnnounceRegistrationOpen`,
  `CreateMatchChannelOnReady`, `AnnounceAndCleanupOnCompleted`), the outbox,
  DM notification channel, and `RegisterCommandsCommand` (slash commands are
  still registered via REST regardless of transport).
- **Reused:** `CommandRouter::dispatch(array $payload): array`,
  `InteractionPayload`, `InteractionResponse`, `SendFollowupJob`. These are
  already transport-agnostic (they take a raw interaction payload array), so
  the Gateway path feeds them the same shape the HTTP path did.
- **Retired:** `Http/InteractionsController`, `Http/Middleware/
  VerifyDiscordSignature`, and the public `POST /discord/interactions` route.
  Discord delivers interactions over the Gateway once the portal's
  "Interactions Endpoint URL" is cleared (a manual step, see §9).

## 4. Architecture (X: thin sidecar + PHP brain)

```
Discord Gateway (WSS)
   │  identify + heartbeat + presence, INTERACTION_CREATE, GUILD_MEMBER_ADD/
   │  REMOVE, VOICE_STATE_UPDATE, MESSAGE_CREATE, MESSAGE_REACTION_ADD/REMOVE
   ▼
┌─────────────────────────────┐        internal HTTP (compose net,
│  discord-gateway (Node)      │  ───▶  X-Gateway-Secret)
│  discord.js v14              │        POST http://app/internal/discord/gateway
│  - holds the connection      │  ◀───  200 { response } (interactions only)
│  - sets presence             │
│  - defers interactions       │
│  - forwards every event      │
└─────────────────────────────┘
   ▲                                   ┌──────────────────────────────────┐
   │ Discord REST (follow-ups,         │  Laravel app (existing container) │
   │ role ops later) — via PHP         │  GatewayIngressController         │
   └───────────────────────────────── │   ├─ interaction → CommandRouter   │
     (unchanged HttpDiscordClient)     │   ├─ voice       → VoicePresence   │
                                       │   ├─ member      → typed event     │
                                       │   └─ message/rx  → typed event     │
                                       └──────────────────────────────────┘
```

Two processes, one internal HTTP hop. The sidecar is **stateless and dumb**:
it never contains domain logic, never touches the database. All decisions
happen in PHP.

## 5. Component: the discord.js sidecar (`docker/discord-gateway/`)

Mirrors the existing sidecar pattern (`docker/mumble-admin`,
`docker/teamspeak-admin`): a tiny, self-contained service behind a clear
boundary, never imported by tests.

**Files:**

- `bot.mjs` — the whole sidecar (~120 lines):
  - Construct a `Client` with intents:
    `[Guilds, GuildVoiceStates, GuildMembers, GuildMessages,
    GuildMessageReactions]` (see §8 for the intent rationale;
    `MessageContent` is intentionally omitted).
  - `presence` set in the client options: status + one activity, from env.
  - `on(ClientReady)` → log `Logged in as <tag>`; discord.js keeps the
    connection alive (heartbeat) and handles resume/reconnect/back-off
    itself — this is the whole reason for using the library (Discord's own
    guidance, see the spec's references).
  - `on(InteractionCreate)`: `if (!isChatInputCommand) return;`
    `await interaction.deferReply()` **immediately** (guaranteed ACK inside
    Discord's 3s window, independent of PHP latency), then `POST` the raw
    interaction JSON to the ingress. PHP delivers the actual content as a
    follow-up edit (see §6). The sidecar does not wait on domain work.
  - `on(GuildMemberAdd | GuildMemberRemove | VoiceStateUpdate |
    MessageCreate | MessageReactionAdd | MessageReactionRemove)`: forward a
    small normalized JSON envelope to the ingress, fire-and-forget (retry
    with back-off on network error; drop after N retries + log).
  - A single `postToIngress(type, data)` helper: `fetch` with the
    `X-Gateway-Secret` header, a short timeout, and bounded retries.
- `package.json` — one dependency, `discord.js` (pinned to the current v14.x).
- `Dockerfile` — `node:22-alpine`, `npm ci --omit=dev`, `CMD ["node","bot.mjs"]`.
- `README.md` — run book + boundary note (like the other sidecars).

**Config (env):**

- `DISCORD_BOT_TOKEN` (exists)
- `DISCORD_GUILD_ID` (exists) — used to scope forwarded events
- `DISCORD_PRESENCE_STATUS` (default `online`)
- `DISCORD_PRESENCE_ACTIVITY_TYPE` (default `Watching`)
- `DISCORD_PRESENCE_ACTIVITY_NAME` (default `LANoMAT`)
- `DISCORD_GATEWAY_BRIDGE_SECRET` (new shared secret, see §11)
- `DISCORD_GATEWAY_INGRESS_URL` (default `http://app/internal/discord/gateway`)

**Compose service `discord-gateway`:** `profiles: [prod]` (exactly **one**
Gateway session per bot token — no parallel dev instance flapping presence),
`env_file: .env`, `restart: unless-stopped`, no `depends_on` on the DB (it
only needs Discord + the `app` service reachable on the compose network).

## 6. Interaction handling (Gateway path)

**Always-defer model.** The sidecar `deferReply()`s every chat-input command
the instant it arrives — this ACKs within Discord's 3-second deadline no
matter how slow the PHP round-trip is, which removes the single biggest
failure mode of a bridge. PHP then computes the response and **delivers it as
a follow-up edit** to the deferred reply, via the existing follow-up webhook
(`PATCH /webhooks/{appId}/{token}/messages/@original`).

Flow:

1. Sidecar: `deferReply()` → `POST /internal/discord/gateway` with
   `{type: "interaction", payload: <raw interaction>}`.
2. `GatewayIngressController` → `CommandRouter::dispatch($payload)` (unchanged)
   → response array `{type, data}`.
3. The controller normalizes both response shapes to a **follow-up**: the
   content from a type-4 (`ChannelMessageWithSource`) response, or the
   already-deferred type-5 path, is delivered via a generalized
   `SendFollowupJob` (it already PATCHes the follow-up webhook using the
   interaction token). Handlers keep returning what they return today; the
   ingress adapts it to the deferred delivery.
4. On unknown command / mapping miss → the existing
   `unknownCommandResponse()` content, delivered the same way.

Consequence: the type-4 vs type-5 distinction collapses to "content is always
a follow-up." Commands show a brief "thinking…" then the result — standard bot
UX, and it keeps handler code essentially unchanged. Non-command interaction
types (components, autocomplete, modals) are logged and ignored this phase
(same as today's "not wired up yet").

## 7. Voice presence (concrete behavior)

`VOICE_STATE_UPDATE` gives `{guild_id, channel_id|null, user_id, ...}`.

- **Read-model:** a `discord_voice_states` table keyed by `discord_user_id`
  with `channel_id` (+ `channel_name` resolved by the sidecar and forwarded,
  since the app has no channel cache) and `updated_at`. Join = upsert;
  channel change = upsert; leave (`channel_id: null`) = delete. Guild-scoped
  to `DISCORD_GUILD_ID`; rows for unknown/irrelevant guilds are ignored.
- **Projection:** `VoicePresenceProjection::current(): array` — a pure,
  No-PII read-model returning, per channel, the count and the **display names
  of mapped LANoMAT users only** (Discord users with no linked LANoMAT
  account are counted but not named). Mirrors `PresenceProjection` discipline.
- **Broadcast:** a `DiscordVoicePresenceUpdated` event broadcast with an empty
  payload (`broadcastWith(): []`) on a **public `discord-voice` channel**;
  consumers pull the projection over HTTP. This follows the M10/M11
  "empty broadcast + read-model pull" no-PII pattern. Discord voice is
  guild-wide, not scoped to a single LANoMAT `Event`, so this uses its own
  channel rather than `event.{id}`.
- **Consumer:** a minimal read surface is enough for this phase — a
  `GET /discord/voice` JSON/Inertia endpoint returning the projection. A
  dedicated beamer `SceneType::DiscordVoice` is noted as an easy follow-up
  (the scene framework already exists) but is **not** required here; confirm
  in review whether to fold the scene in now.

## 8. Member + message/reaction events (surface only)

Delivered to PHP and dispatched as typed Laravel events, each with a listener
that logs at `info` and does nothing else — the concrete extension points for
future product behavior:

- `GUILD_MEMBER_ADD`  → `DiscordGuildMemberJoined(discordUserId)`
- `GUILD_MEMBER_REMOVE` → `DiscordGuildMemberLeft(discordUserId)`
- `MESSAGE_CREATE` → `DiscordMessageCreated(channelId, authorId)` (metadata
  only; no content — see intents below)
- `MESSAGE_REACTION_ADD/REMOVE` →
  `DiscordMessageReactionChanged(messageId, channelId, userId, emoji, added)`

**Intents rationale:**

| Intent | Privileged? | Needed for |
| --- | --- | --- |
| `Guilds` | no | base guild/channel context |
| `GuildVoiceStates` | no | voice presence (§7) |
| `GuildMembers` | **yes** | member join/leave events |
| `GuildMessages` | no | message-created metadata |
| `GuildMessageReactions` | no | reaction events |
| `MessageContent` | **yes** | *not enabled* — deferred until a
  content-based behavior (e.g. reaction-to-register) ships |

Only **one** privileged intent (`GuildMembers`) must be toggled on now.

## 9. Manual prerequisites (outside code)

1. **Discord Developer Portal → Bot → Privileged Gateway Intents:** enable
   **Server Members Intent** (`GUILD_MEMBERS`). (`MESSAGE_CONTENT` stays off.)
   For a bot in <100 guilds this is a toggle; ≥100 requires approval.
2. **Discord Developer Portal → General Information → Interactions Endpoint
   URL:** **clear it.** While an endpoint URL is set, Discord uses HTTP and
   will *not* deliver `INTERACTION_CREATE` over the Gateway. Clearing it
   switches interaction delivery to the Gateway.

Both are documented in the sidecar README and called out in the rollout notes.

## 10. Configuration & env

Add to `.env.example` (and the prod `.env`): the presence + bridge vars from
§5. `DISCORD_PUBLIC_KEY` becomes unused once the HTTP endpoint is retired but
is kept (harmless, and Discord still shows it).

## 11. Security

- The ingress is an **internal** route: the sidecar reaches it as
  `http://app/...` over the compose network; Traefik routes by public Host and
  never exposes it. It is **not** under the public `web` routing that
  participant pages use.
- Every ingress request must carry `X-Gateway-Secret` equal to
  `DISCORD_GATEWAY_BRIDGE_SECRET`, checked with a constant-time compare in a
  dedicated middleware (`VerifyGatewaySecret`); missing/mismatch → 401. This
  replaces Ed25519 as the trust boundary (Ed25519 verified *Discord*; now the
  boundary is *our sidecar → our app*).
- The sidecar holds the bot token (same secret the app already holds); no new
  credential surface beyond the bridge secret.

## 12. Docs & CLAUDE.md amendment

- **CLAUDE.md:** amend the architecture rule. New wording: *"Discord uses a
  Gateway connection via a thin discord.js sidecar (`docker/discord-gateway`)
  for presence + inbound events (interactions, member, voice, reactions),
  bridged to the app over an internal secret-authenticated endpoint. All
  domain logic stays in PHP; the sidecar is pure transport. Outbound calls
  still go through the `DiscordClient` contract."* Note the retirement of the
  Ed25519 HTTP interactions endpoint.
- **docs/architecture.md:** add the sidecar + ingress to the module/data-model
  sketch.
- **Sidecar README:** run book, intents, portal prerequisites, boundary note.

## 13. Testing strategy

- **PHP (Pest, the real coverage):**
  - `GatewayIngressController` auth: 401 without/with wrong secret, 200 with
    correct secret.
  - Interaction dispatch: a forwarded `/help` (and one `/tournament`)
    interaction payload routes through `CommandRouter` and enqueues the
    follow-up job (assert the job, using `FakeDiscordClient` / `Bus::fake`) —
    never calling Discord.
  - Voice presence: forwarded join/move/leave envelopes drive
    `discord_voice_states` correctly; `VoicePresenceProjection` returns
    No-PII output (mapped names only) and the query is bounded; the empty
    broadcast fires.
  - Member/message/reaction: forwarded envelopes dispatch the typed events
    (assert with `Event::fake`).
- **Node sidecar:** not unit-tested, exactly like `mumble-admin`/
  `teamspeak-admin` — it is a thin transport wrapper around a maintained
  library and is verified operationally (below).
- **Operational verification:** run the sidecar against the real token, assert
  `ClientReady` fires (proves the connection = bot online), invoke a slash
  command end-to-end in the guild, and confirm a voice join updates the
  projection. Reuses the local prod stack.

## 14. Rollout / backward-compatibility

Order matters (interactions must not go dark mid-switch):

1. Ship the sidecar + ingress + intents, deploy the sidecar (bot goes online,
   events start flowing; interactions still arrive over HTTP because the
   endpoint URL is still set).
2. Clear the Interactions Endpoint URL in the portal → interactions switch to
   the Gateway. Verify a command.
3. Remove the HTTP `InteractionsController`, `VerifyDiscordSignature`, and the
   route. (Steps 2–3 can be one deploy since the code retirement and the
   portal switch are coordinated.)

## 15. Open decisions for review

1. **Voice consumer:** ship just the `GET /discord/voice` read endpoint, or
   also add a `SceneType::DiscordVoice` beamer scene now?
2. **Voice scope:** guild-wide (proposed) is simplest; is any event-scoping
   wanted, or is guild-wide correct given Discord voice isn't per-LAN-event?
3. **Keep HTTP endpoint as fallback?** Proposal: no — retire it (one clear
   path). Confirm you don't want to keep Ed25519 HTTP as a dormant fallback.
4. **Presence activity text:** default `Watching LANoMAT`; want something
   dynamic (e.g. the current live event name) — which would add a small
   sidecar→app pull — or is the static configurable text fine for now?
