---
title: Game servers
description: Pelican provisioning, permanent servers, port pool, A2S/Minecraft readiness and join links.
sidebar_icon: server
---

`GameServers` turns a match into a running server. It talks to Pelican Panel through `PelicanClient`,
allocates from a port pool, waits for the server to answer a query, and publishes the connect string
to the match page, Discord embed and native client.

## Who uses it

- **Orga** configures nodes, presets, the port pool and permanent servers.
- **Teilnehmer** click a join link on the match page; the server is already warm.
- **Agents** read the server list with `servers_list` and print the same join string the match page
  shows.

## Key screens

- Match page — server state, connect string, join button.
- `/servers` — permanent servers across events, with status and players.
- Filament **Server links** — inspect, stop, reprovision.

## Guards and rules

- A server is only listed to participants when it is ready; `failed`/`install_failed` stay orga-only.
- `provisioning` and `not_responding` are `warn`, not alarms; the UI explains what is happening.
- A plain `address:port` without a URL scheme is a copyable mono value, not a dead button.
- The port pool is transactional: two matches cannot get the same port.
- Readiness uses A2S (Source) or a Minecraft TCP query; the protocol is part of the server link.
- Permanent servers (`permanent=true`) have no event/match and are not cleaned up with an event.
- `ServerLinkUpdated` drives warmup and surface updates; the module never calls Tournaments directly.

## Docs for agents

`servers_list` returns the permanent LAN servers (no argument) or the servers of one event
(`event_slug`), with game, status, permanent/manual flags and the join info.

<div class="callout-live">
  <strong>Server down is not LAN down.</strong> A failed provision degrades one match; the bracket,
  Discord and voice keep working.
</div>
