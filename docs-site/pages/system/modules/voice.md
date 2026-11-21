---
title: Voice
description: Mumble 1.5 and TeamSpeak rooms orchestrated through purpose-built admin sidecars.
sidebar_icon: mic
---

`Voice` creates and removes voice rooms for tournaments, teams and matches on Mumble and TeamSpeak.
LANoMAT never speaks the voice protocol: a small sidecar per provider exposes a REST API, and the
app calls it through `MumbleClient`/`TeamSpeakClient` contracts.

## Who uses it

- **Teilnehmer** join a room from the match page, team page or the native client, with their own
  name in the join link.
- **Orga** configures the providers and watches occupancy.

## Key screens

- Match page and team page — join links (`mumble://…`, `ts3server://…`).
- `/talk/connect` — connection help and the server password (Mumble).
- Filament **Voice client installers** and provider settings.

## Native client

The desktop client is the second way into a room next to the join links. It connects once, then
shows the room a match or a team opens.

![Native client connect screen with the server address field and a Verbinden button](/screens/voice-app/01-connect.png)

`CONNECT · server address and device-code pairing — the app never sees a password`

![Native client Jetzt tab with a team strip listing the Lobby, Discord and the current match room](/screens/voice-app/10-jetzt.png)

`JETZT · the team strip links straight into the room and the match`

![Native client Sprache tab connected to the match room, with push-to-talk, mute and the room chat](/screens/voice-app/20-sprache.png)

`SPRACHE · in the match room: push-to-talk, mute, participants, chat`

![Native client match chat where a mention is flagged as meint dich](/screens/voice-app/21-chat-mention.png)

`CHAT · a mention in the match chat is marked "meint dich"`

## Guards and rules

- One sidecar per provider. `docker/mumble-admin` (Ice REST) and `docker/teamspeak-admin`
  (ServerQuery REST) are transport, not domain.
- Providers are handled independently: a TeamSpeak failure does not block Mumble rooms.
- Empty rooms are removed at once; rooms with people wait until the last person leaves. A sync
  queue never re-queues itself with a delay (that would loop).
- Match rooms are created on `MatchReady` and cleaned up after `MatchCompleted`.
- A join link carries the user's display name, never their Discord id.
- The Mumble server password is shown on the setup page for orga, not in public props.
- `OccupiedChannels` is fed by the sidecar's occupant count, not guessed.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Two providers, one idea.</strong> The room lifecycle is shared; only the transport
  differs. That is why the cleanup rules live in the module, not in each sidecar.
</div>
