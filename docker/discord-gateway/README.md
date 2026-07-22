# discord-gateway

A thin discord.js sidecar that holds the single persistent Discord Gateway
connection for LANoMAT and forwards every event to the Laravel ingress
(`POST /internal/discord/gateway`, header `X-Gateway-Secret`). It is **pure
transport**: no database, no domain logic, no state beyond what discord.js
itself keeps for the socket (heartbeat, resume, reconnect, rate-limits —
all handled by the library per Discord's own recommendation). All decisions
about what an event *means* live in the PHP ingress and the rest of the
`Discord` module.

> **// M-later, real-infra:** operational verification (bot showing online,
> a live slash command round-trip, a voice-channel join reflected in
> `GET /discord/voice`) is **deferred** to the acceptance checklist
> (`docs/pre-lan-acceptance-checklist.md` §1) — same posture as
> `docker/mumble-admin` and `docker/teamspeak-admin`: it needs a real bot
> token and a real guild, so it isn't run in CI or against fake credentials.
> What *is* verified in this repo: `bot.mjs` parses (`node --check`) and the
> compose service is valid YAML (`docker compose --profile prod config`).

## Why a sidecar instead of a PHP gateway client

The Gateway is a long-lived WebSocket with its own heartbeat/resume/backoff
state machine; Laravel's request/worker lifecycle isn't a good fit for
holding that connection directly ("no bot process" was true before this
task only because nothing needed a live Gateway session — see
`docs/superpowers/specs/2026-07-21-discord-gateway-bot-design.md`). discord.js
is Discord's own recommended, actively maintained client for exactly this
job, so the sidecar is intentionally minimal: receive an event, shape it
into the ingress contract, POST it, repeat.

## Events forwarded

| Gateway event                              | Ingress `type`    | `data` shape                                                              |
| ------------------------------------------- | ------------------ | --------------------------------------------------------------------------- |
| `InteractionCreate` (chat input commands)   | `interaction`       | raw-shaped interaction: `{type:2, token, application_id, member.user.id, user.id, data:{name, options}}` |
| `VoiceStateUpdate`                          | `voice_state`       | `{guild_id, user_id, channel_id\|null, channel_name\|null}`                  |
| `GuildMemberAdd`                            | `member_add`        | `{guild_id, user_id}`                                                        |
| `GuildMemberRemove`                         | `member_remove`     | `{guild_id, user_id}`                                                        |
| `MessageCreate` (non-bot authors only)      | `message_create`    | `{channel_id, author_id, message_id}`                                        |
| `MessageReactionAdd` / `MessageReactionRemove` | `reaction`       | `{message_id, channel_id, user_id, emoji, added}`                            |

Every POST carries `X-Gateway-Secret: <DISCORD_GATEWAY_BRIDGE_SECRET>` and is
retried up to 3 times with linear backoff (500ms, 1000ms, 1500ms) on network
error or non-2xx; a still-failing event is logged and dropped rather than
blocking the event loop.

Slash-command interactions are `deferReply()`'d immediately in the sidecar
(Discord requires an ack within 3 seconds) — the actual follow-up content is
sent later by the PHP ingress via the interaction webhook, exactly as the
existing HTTP Interactions endpoint's deferred-job flow already does.

## Environment variables

| Variable                          | Default                                       | Meaning                                                        |
| ----------------------------------- | ------------------------------------------------ | ------------------------------------------------------------------ |
| `DISCORD_BOT_TOKEN`                 | *(required)*                                     | Bot token used to log in to the Gateway                            |
| `DISCORD_GATEWAY_BRIDGE_SECRET`     | *(required)*                                     | Shared secret sent as `X-Gateway-Secret` to the PHP ingress         |
| `DISCORD_GATEWAY_INGRESS_URL`       | `http://app/internal/discord/gateway`            | Ingress URL (compose service name `app` on the shared network)     |
| `DISCORD_PRESENCE_STATUS`           | `online`                                         | Bot presence status                                                 |
| `DISCORD_PRESENCE_ACTIVITY_TYPE`    | `Watching`                                        | discord.js `ActivityType` key (`Playing`, `Watching`, `Listening`, `Competing`, ...) |
| `DISCORD_PRESENCE_ACTIVITY_NAME`    | `LANoMAT`                                         | Presence activity text                                              |

## Manual portal prerequisites (real-infra, do once against the real bot)

1. **Enable the Server Members Intent** in the Discord Developer Portal
   (Bot page → Privileged Gateway Intents). `GuildMemberAdd`/`GuildMemberRemove`
   will not fire without it, and the Gateway login itself will be rejected if
   the sidecar requests `GuildMembers` without the portal toggle on.
2. **Clear the Interactions Endpoint URL** (General Information page) so
   Discord delivers interactions over the Gateway (`InteractionCreate`)
   instead of the HTTP endpoint. To fall back to the existing Ed25519-verified
   HTTP Interactions endpoint, re-set that URL — the two delivery modes are
   mutually exclusive per Discord, and this sidecar only receives events when
   the field is empty.

## Running

`profiles: [prod]` in `compose.yml`, deliberately: exactly one Gateway
session may exist per bot token, so this must not be part of the default
dev `docker compose up -d`.

```bash
docker compose --profile prod up -d --build discord-gateway
docker compose logs discord-gateway | grep "logged in as"
```

Expected: `discord-gateway: logged in as <bot#tag>`.
