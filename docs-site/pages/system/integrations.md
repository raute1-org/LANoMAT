---
title: Integrations
description: Discord, Mumble, web voice over LiveKit, and the MCP server for agents.
sidebar_icon: plug
---

Integrations connect LANoMAT to the outside world, always through a contract with a fake in tests.
The app never calls a real Discord, Mumble, Pelican, LiveKit or Music Assistant API in CI.

## Modules in this group

| Module | What it does | Main users |
|---|---|---|
| [Discord](/docs/system/modules/discord) | REST client, Gateway sidecar, slash commands, DMs, match channels, categories | Everyone |
| [Voice](/docs/system/modules/voice) | Mumble and TeamSpeak channel orchestration via admin sidecars, join links | Teilnehmer, Orga |
| [WebVoice](/docs/system/modules/webvoice) | Browser voice + chat on self-hosted LiveKit, native client bearer API | Teilnehmer |
| [Mcp](/docs/system/modules/mcp) | Streamable HTTP MCP server, device pairing, tools/resources/prompts | Agents, developers |

## Inbound and outbound are separate

- **Outbound** (messages, channels, DMs) goes through `DiscordClient` → `HttpDiscordClient`.
- **Inbound** (interactions, member events, voice states) arrives over a persistent Discord Gateway
  connection held by a small discord.js sidecar. The sidecar is pure transport: it POSTs a JSON
  envelope to `internal/discord/gateway`, authenticated by a shared secret. All meaning is decided
  in PHP.
- The Ed25519 HTTP interactions endpoint is kept as a dormant fallback. Re-setting the portal's
  Interactions Endpoint URL switches back immediately.

## Voice providers

Mumble and TeamSpeak each have a purpose-built REST sidecar (Ice for Mumble, ServerQuery for
TeamSpeak). Web voice uses a self-hosted LiveKit SFU. LANoMAT issues room tokens and join links; audio
bytes never pass through the app. Rooms are cleaned up when the last person leaves, not on a blind
timer.

<div class="callout-live">
  <strong>Agents use MCP, not Discord.</strong> The MCP server at <code>/mcp</code> is a separate
  interface with its own bearer tokens and device pairing. See <a href="/mcp/overview">MCP</a>.
</div>
