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
- **Agents** can read the server list through the API (no dedicated MCP tool yet).

## Key screens

- Match page — server state, connect string, join button.
- `/servers` — the LAN's match, tournament and event servers plus the permanent ones, with status and players; `/servers/all` is the cross-event browser.
- Filament **Server links** — inspect, stop, reprovision.

The participant view is one card per link: game, address, port, estimated demand and the join
actions. When the card's game has a matching recipe, **Im Launcher beitreten** comes first (it
installs the game when needed and starts it on this server) and the game's own join follows as the
outline alternative; without a recipe the card keeps its own connect action. A plain `address:port`
gets a copy button next to it.

A card whose game has a matching recipe also carries the recipe way in: **Im Launcher beitreten**
with the hint „Der Launcher installiert das Spiel, falls es fehlt, und startet es auf diesem
Server.“, **Spiel installieren** („Zum Spiel“ in a narrow row) and **Mitspieler suchen**. The game
page's server rows carry the same launcher join first, with the game's own ways under **Anders
verbinden**.

| Dark | Light |
|---|---|
| ![Event servers page in dark theme with a ready UT2004 server, address, port and join and copy actions](/screens/event-servers-dark.png) | ![Event servers page in light theme with a ready UT2004 server, address, port and join and copy actions](/screens/event-servers-light.png) |
| `EVENT SERVERS · DARK` — ready server, address, port, join and copy | `EVENT SERVERS · LIGHT` |

`/servers` is the one overview: the current LAN (`?event=` for another) plus the permanent servers.
It starts empty and only shows servers that are ready, so an empty state is a valid one. The
cross-event browser lives at `/servers/all`.

| Dark | Light |
|---|---|
| ![Server overview in dark theme showing the empty state](/screens/servers-dark.png) | ![Server overview in light theme showing the empty state](/screens/servers-light.png) |
| `SERVER OVERVIEW · DARK` — empty state before the first server | `SERVER OVERVIEW · LIGHT` |

The Filament **Server links** table is the orga view: match, players, Pelican id, status, address
and deprovision, plus the start-from-preset action.

| Dark | Light |
|---|---|
| ![Server links admin table in dark theme with match, players, status, address and deprovision](/screens/admin-server-links-dark.png) | ![Server links admin table in light theme with match, players, status, address and deprovision](/screens/admin-server-links-light.png) |
| `SERVER LINKS · DARK` — match, status, address, deprovision, start from preset | `SERVER LINKS · LIGHT` |

## Guards and rules

- A server is only listed to participants when it is ready; `failed`/`install_failed` stay orga-only.
- `provisioning` and `not_responding` are `warn`, not alarms; the UI explains what is happening.
- A plain `address:port` without a URL scheme is a copyable mono value, not a dead button.
- The `install` block on a server row comes from the `GameInstallLinks` contract. `GameRecipes`
  answers with the published, lineup-allowed recipe whose slug equals the game's slug first, then
  the recipe whose search terms match the game's name or slug. No match means `install: null`, and
  the row keeps its own connect string and install hint. With the module off, a null implementation
  answers.
- `launcherJoinUrl` is `lanomat://join/<slug>`, built only when the launcher can install the recipe.
  UT2004 passes the server as `?address=<host>:<port>`; every other recipe passes the server's own
  join URL as `?url=…`. Neither an address+port nor an accepted join URL means no launcher link.
- The port pool is transactional: two matches cannot get the same port.
- Readiness uses A2S (Source) or a Minecraft TCP query; the protocol is part of the server link.
- Permanent servers (`permanent=true`) have no event/match and are not cleaned up with an event.
- `ServerLinkUpdated` drives warmup and surface updates; the module never calls Tournaments directly.

## Docs for agents

`servers_list` with an optional `event_slug`; without it, the permanent servers are returned.

<div class="callout-live">
  <strong>Server down is not LAN down.</strong> A failed provision degrades one match; the bracket,
  Discord and voice keep working.
</div>
