---
title: Integrations
description: Discord, Mumble, web voice over LiveKit, the MCP server for agents, and the orga assistant.
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
| [Assistant](/docs/system/modules/assistant) | Orga-only AI chat; read tools, approval cards for tournament and server changes | Orga |

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

## Accounts on other platforms

Discord is the way in. Steam and Twitch are optional links a participant adds in settings, so orga
can match a nickname to a person on another platform. The same page offers the desktop client.

| Dark | Light |
|---|---|
| ![Settings, connections tab with Steam, Twitch and the desktop client, dark](/screens/connections-dark.png) | ![Settings, connections tab with Steam, Twitch and the desktop client, light](/screens/connections-light.png) |
| `CONNECTIONS · DARK` — Steam and Twitch links; Discord is the login | `CONNECTIONS · LIGHT` |

## Machines behind a contract

The same rule — a contract plus a fake in tests — covers the machines at the other end. `Hosts`
keeps the address, SSH user, write-only key and pinned fingerprint; `Files` can put bytes on the
LAN file host instead of the app's disk.

| Dark | Light |
|---|---|
| ![Remote hosts, empty registry with address, key and role columns, dark](/screens/admin-remote-hosts-dark.png) | ![Remote hosts, empty registry with address, key and role columns, light](/screens/admin-remote-hosts-light.png) |
| `REMOTE HOSTS · DARK` — empty registry: address, SSH user, key, role, last probe | `REMOTE HOSTS · LIGHT` |

| Dark | Light |
|---|---|
| ![Shared files, event files with size, status and store, dark](/screens/admin-shared-files-dark.png) | ![Shared files, event files with size, status and store, light](/screens/admin-shared-files-light.png) |
| `SHARED FILES · DARK` — uploader, size, moderation status, store per row | `SHARED FILES · LIGHT` |

<div class="callout-live">
  <strong>Agents use MCP, not Discord.</strong> The MCP server at <code>/mcp</code> is a separate
  interface with its own bearer tokens and device pairing. See <a href="/mcp/overview">MCP</a>.
</div>
