# discord-gateway

A thin discord.js sidecar that holds the single persistent Discord Gateway
connection for LANoMAT and forwards every event to the Laravel ingress
(`POST /internal/discord/gateway`, header `X-Gateway-Secret`). Transport and
domain decisions stay in PHP.

The one exception is the opt-in **guest assistant** (`/ask`, @mentions, DMs):
it runs here because it needs the discord.js reply path, a short-lived `pi`
process and the LANoMAT MCP bearer token. See
[`docs/ops/discord-agent.md`](../../docs/ops/discord-agent.md) for the operator
runbook. The assistant never invents facts: with `pi` it may call any MCP tool;
without a model it falls back to a deterministic JSON-RPC client for
`recipes_list`, `recipe_get`, `recipe_install_plan` and `servers_list`.

> **// M-later, real-infra:** operational verification (bot showing online,
> a live slash command round-trip, a voice-channel join reflected in
> `GET /discord/voice`, a real `/ask` answer) is **deferred** to the acceptance
> checklist (`docs/pre-lan-acceptance-checklist.md` §1) — same posture as
> `docker/mumble-admin` and `docker/teamspeak-admin`: it needs a real bot
> token and a real guild, so it isn't run in CI or against fake credentials.
> What *is* verified in this repo: `bot.mjs`/`agent.mjs` parse (`node --check`),
> `agent.test.mjs` runs under `node --test`, and the compose service is valid
> YAML (`docker compose --profile prod config`).

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
| `MessageCreate` (non-bot authors only)      | `message_create`    | `{channel_id, author_id, message_id}`; plus `{content, author, channel, guild, mentions, isDM}` only for assistant-addressed messages |
| `MessageReactionAdd` / `MessageReactionRemove` | `reaction`       | `{message_id, channel_id, user_id, emoji, added}`                            |

`/ask` is **not** forwarded: the sidecar defers the interaction and answers it
locally (chunked follow-ups). Every other slash command goes through the PHP
`CommandRouter` exactly as before.

Every POST carries `X-Gateway-Secret: <DISCORD_GATEWAY_BRIDGE_SECRET>` and is
retried up to 3 times with linear backoff (500ms, 1000ms, 1500ms) on network
error or non-2xx; a still-failing event is logged and dropped rather than
blocking the event loop.

**Message content is privileged.** The client requests
`GatewayIntentBits.MessageContent` and `DirectMessages`, plus
`Partials.Channel` (DMs arrive as partial channels). The body is only forwarded
for messages addressed to the assistant; ordinary chatter stays ids-only.
A bounded `seenMessages` set drops Gateway redeliveries.

**Reaction caveat.** `MessageReactionAdd`/`Remove` can arrive partial when the
target message is not cached; the partials above keep the fields usable.

## The assistant (`agent.mjs`)

`createAgent()` builds `~/.pi/agent/mcp.json` (same shape as
`clients/agent/src/mcp.ts`: `url: ${LANOMAT_URL}/mcp`, `Authorization: Bearer
${LANOMAT_TOKEN}`, `exposure: deferred`) and runs:

```
pi --print --no-session --no-context-files --no-builtin-tools \
   [--provider …] [--model …] [--skill …] \
   --system-prompt "<German system prompt>" "<question>"
```

Built-in tools are disabled so the model cannot read files or run shell
commands; it can only call LANoMAT MCP tools. The `pi` run has a hard timeout
(`DISCORD_AGENT_TIMEOUT_MS`, default 90s); a missing binary, a timeout or a
non-zero exit falls back to `McpJsonRpcClient`.

The fallback:
- `recipes_list` (query from the question, then individual meaningful words),
- `recipe_get`,
- `recipe_install_plan` with `dry_run: true` (no audit rows from chat),
- `servers_list`.

Answers are built only from tool output and always name
`lanomat://install/<slug>` and `${LANOMAT_URL}/games/<slug>`. Answers are split
at 2000 characters (line/word boundaries, surrogate-safe). Mentions and `/ask`
share a per-user sliding-window rate limit (`DISCORD_AGENT_RATE_LIMIT` per
`DISCORD_AGENT_RATE_WINDOW_MS`). Replies use `allowed_mentions.parse = []`, so
model output can never ping `@everyone`.

`/ask` is registered **guild-only** at `ClientReady` (create or edit), so it
exists exactly in `DISCORD_AGENT_GUILD_ID` (fallback `DISCORD_GUILD_ID`) and
disappears when the agent is off.

## Environment variables

| Variable                          | Default                                       | Meaning                                                        |
| ----------------------------------- | ------------------------------------------------ | ------------------------------------------------------------------ |
| `DISCORD_BOT_TOKEN`                 | *(required)*                                     | Bot token used to log in to the Gateway                            |
| `DISCORD_GATEWAY_BRIDGE_SECRET`     | *(required)*                                     | Shared secret sent as `X-Gateway-Secret` to the PHP ingress         |
| `DISCORD_GATEWAY_INGRESS_URL`       | `http://app/internal/discord/gateway`            | Ingress URL (compose service name `app` on the shared network)     |
| `DISCORD_PRESENCE_STATUS`           | `online`                                         | Bot presence status                                                 |
| `DISCORD_PRESENCE_ACTIVITY_TYPE`    | `Watching`                                        | discord.js `ActivityType` key (`Playing`, `Watching`, `Listening`, `Competing`, ...) |
| `DISCORD_PRESENCE_ACTIVITY_NAME`    | `LANoMAT`                                         | Presence activity text                                              |
| `DISCORD_AGENT_ENABLED`             | `false`                                          | Opt-in guest assistant                                              |
| `DISCORD_AGENT_GUILD_ID`            | `DISCORD_GUILD_ID`                                | Guild for `/ask` and mentions; DMs are always allowed               |
| `DISCORD_AGENT_CHANNEL_ID`          | *(empty = all channels)*                          | Restrict mentions and `/ask` to one channel                         |
| `DISCORD_AGENT_RATE_LIMIT`          | `5`                                               | Questions per user per window                                       |
| `DISCORD_AGENT_RATE_WINDOW_MS`      | `600000` (10 min)                                 | Rate-limit window                                                   |
| `DISCORD_AGENT_TIMEOUT_MS`          | `90000`                                           | Hard timeout for one `pi --print` run                               |
| `DISCORD_AGENT_MCP_TIMEOUT_MS`      | `15000`                                           | Per-request timeout of the fallback MCP client                      |
| `DISCORD_AGENT_MAX_CHARS`           | `2000`                                            | Chunk size (capped at Discord's 2000)                               |
| `LANOMAT_URL`                       | `https://lan.raute1.org`                          | Base for `/mcp`, `/games/<slug>` and `/servers`                     |
| `LANOMAT_TOKEN`                     | *(required for the assistant)*                    | MCP bearer token (dedicated user, read-only tools)                  |
| `PI_MODEL` / `PI_PROVIDER`          | *(pi default)*                                    | Model selection; provider credentials stay in env                   |
| `PI_SKILLS_DIR`                     | *(unset)*                                         | Optional synced recipe skills (`--skill <dir>`)                     |
| `PI_BIN`                            | `pi`                                              | Override the pi binary                                              |

## Manual portal prerequisites (real-infra, do once against the real bot)

1. **Enable the Server Members Intent** in the Discord Developer Portal
   (Bot page → Privileged Gateway Intents). `GuildMemberAdd`/`GuildMemberRemove`
   will not fire without it, and the Gateway login itself will be rejected if
   the sidecar requests `GuildMembers` without the portal toggle on.
2. **Enable the Message Content Intent** for the assistant. Without it Discord
   still delivers `<@mention>` content, but DMs and `!ask` prefixes arrive empty.
3. **Clear the Interactions Endpoint URL** (General Information page) so
   Discord delivers interactions over the Gateway (`InteractionCreate`)
   instead of the HTTP endpoint. To fall back to the existing Ed25519-verified
   HTTP Interactions endpoint, re-set that URL — the two delivery modes are
   mutually exclusive per Discord, and this sidecar only receives events when
   the field is empty.
4. The bot needs `applications.commands` in the guild for the guild-only
   `/ask` registration (normally covered by the `bot` + `applications.commands`
   scopes used for the invite).

## Running

`profiles: [prod]` in `compose.yml`, deliberately: exactly one Gateway
session may exist per bot token, so this must not be part of the default
dev `docker compose up -d`.

```bash
docker compose --profile prod up -d --build discord-gateway
docker compose logs discord-gateway | grep -E "logged in as|agent enabled|/ask registered"
```

Expected: `discord-gateway: logged in as <bot#tag>` and, with the agent on,
`discord-gateway: agent enabled (…)` plus `/ask registered in guild …`.

Checks that need no token:

```bash
cd docker/discord-gateway
node --check bot.mjs && node --check agent.mjs
node --test agent.test.mjs
```
