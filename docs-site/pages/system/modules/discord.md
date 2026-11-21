---
title: Discord
description: The bot, the Gateway sidecar, slash commands, DMs and per-event match channels.
sidebar_icon: message-circle
---

`Discord` is the app's mirror into the community server: announcements, DMs, slash commands and
temporary match channels. Outbound calls go through `DiscordClient`; inbound events arrive over a
persistent Gateway held by a small discord.js sidecar.

## Who uses it

- **Teilnehmer** get DMs for time-sensitive events and use `/tournament` commands.
- **Orga** uses announcements, match channels and the archive categories.
- **Admins** configure the bot, guild and channel ids.

## Key screens

- Discord — announcements, DMs, `/tournament list|info|checkin|bracket`, `/help`.
- Filament — Discord settings (channel ids, guild gate).
- Preflight — Gateway health (200 only while connected).

| Dark | Light |
|---|---|
| ![Admin, dark](/screens/admin-dark.png) | ![Admin, light](/screens/admin-light.png) |
| `ADMIN · DARK` — settings live in Filament | `ADMIN · LIGHT` |
| ![Neuigkeiten admin list with retract and edit actions, dark](/screens/admin-news-dark.png) | ![Neuigkeiten admin list with retract and edit actions, light](/screens/admin-news-light.png) |
| `DISCORD · NEUIGKEITEN` — announcements that become Discord posts | `DISCORD · LIGHT` — the same announcement queue |
| ![Sprachchat page with the team-room hint and no channels yet, dark](/screens/talk-dark.png) | ![Sprachchat page with the team-room hint and no channels yet, light](/screens/talk-light.png) |
| `DISCORD · SPRACHCHAT` — one room per team, created when a match is ready | `DISCORD · LIGHT` — the empty voice lobby |

## Guards and rules

- The sidecar is pure transport: it shapes a Gateway event into `{type, data}` and POSTs it to
  `internal/discord/gateway`, authenticated by `X-Gateway-Secret`. All meaning lives in PHP.
- Slash-command interactions are deferred immediately by the sidecar; PHP delivers the content as a
  follow-up. The Ed25519 HTTP endpoint stays as a dormant fallback.
- One Gateway session per bot token; run exactly one sidecar.
- Announcements and reminders are deduplicated through `discord_outbox.dedup_key`; a retry cannot
  post twice.
- Match channels are private: a text channel plus one voice room per team, visible to everyone but
  joinable only by that team and admins.
- A finished match's text channel moves into the event archive, read-only; empty voice rooms are
  deleted, occupied ones wait.
- `DISCORD_LOGIN_GUILD_ID` gates login without a bot; admins exempt, fail-closed.
- The bot must have the Server Members intent for join/leave events; the login is rejected if the
  portal toggle is off.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Amplify, never replace.</strong> Discord mirrors the in-app bell. If Discord is down, the
  LAN still works and the bell still tells the truth.
</div>
