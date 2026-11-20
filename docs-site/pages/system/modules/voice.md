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
- `/voice/setup` — connection help and the server password (Mumble / TeamSpeak).
- Filament **Voice client installers** and provider settings.

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
