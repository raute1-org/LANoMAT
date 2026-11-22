---
title: Game servers
description: Pelican provisioning, permanent servers, port pool, A2S/Minecraft readiness, install block and join links.
sidebar_icon: server
---

`GameServers` turns a match into a running server. It talks to Pelican Panel through `PelicanClient`,
allocates from a port pool, waits for the server to answer a query, and publishes the connect string
to the match page, Discord embed and native client.

## Who uses it

- **Orga** configures nodes, presets, the port pool and permanent servers.
- **Teilnehmer** click a join link on the match page; the server is already warm.
- **Agents** read the same list through `servers_list` (MCP) or `GET /api/v1/servers`.

## Key screens

- Match page — server state, connect string, join button.
- `/servers` — the LAN's match, tournament and event servers plus the permanent ones, with status and players; `/servers/all` is the cross-event browser.
- Filament **Server links** — inspect, stop, reprovision.

The participant view is one row per link: game, address, port, estimated demand and the join
actions; the detail panel opens inline. When the row's game has a matching recipe, the launcher
action comes first in the panel and the game's own join follows as the outline alternative; without a
recipe the row keeps its own connect action. A plain `address:port` gets a copy button next to it.

A row whose game has a matching recipe also carries the recipe way in: the launcher action,
**Spiel installieren** („Zum Spiel“ in a narrow row) and **Mitspieler suchen**. The launcher action is
labelled by what its link really does:

- **Im Launcher beitreten**, with the hint „Der Launcher installiert das Spiel, falls es fehlt, und
  startet es auf diesem Server.“ — the link names the server (`?address=` or `?url=`).
- **Im Launcher starten**, with the hint „Der Launcher installiert das Spiel, falls es fehlt, und
  startet es. Den Server wählst du dann im Spiel; seine Adresse steht in dieser Zeile.“ — the bare
  `lanomat://join/<slug>`, which installs and starts the game but cannot put the player on the server.

When the game's files are not on the LAN right now (`install.lanReady` false) one calm line adds
„Für dieses Spiel liegen gerade nicht alle Dateien auf dem LAN-Server; eine Neuinstallation kann
deshalb scheitern. Wer das Spiel schon hat, kann trotzdem beitreten — die Orga hilft, wenn etwas
fehlt.“ The game page's server rows carry the same two launcher actions first, with the game's own
ways under **Anders verbinden**.

When a `lanomat://` click takes nobody over, the notice under the action answers it: the question
„Kein Launcher-Fenster aufgegangen?“, the likely reason (not installed, or never started), where to
look if it did open, and the download and retry actions.

| Dark | Light |
|---|---|
| ![The launcher notice: the question "Kein Launcher-Fenster aufgegangen?", the explanation with the taskbar hint, the download action and "Nochmal versuchen"](/screens/launcher-notice-dark.png) | ![The same notice in light mode](/screens/launcher-notice-light.png) |
| `LAUNCHER NOTICE · DARK` — question, reason, download, retry | `LAUNCHER NOTICE · LIGHT` — same notice |

A row also says **„Hier spielen“** when the Presence read model places LANoMAT accounts on it: up to
five avatars with names and a `+N` for the rest, at most eight accounts from the server and `more`
for the further visible ones. The audience is the event's own people list (`EventPolicy::viewPeople`):
a registered participant of that event or orga — an account alone learns nothing, which is what the
`share_location` setting promises. Somebody who switched `share_location` off, or who blocked the
viewer (either way), is in neither the list nor the count, and the viewer always sees themselves when
they are a participant of that event. The event that scopes "who" is the list's own event; the
cross-event browser and the permanent servers use the current LAN. The bearer APIs carry no people:
`GET /api/v1/servers` sends `occupants: null` on every row, and the native client's own server DTO has
no occupants key at all.

| Dark | Light |
|---|---|
| ![Server overview in dark theme with the UT2004 row expanded: map and player count, the in-game player list, the "Hier spielen" line with LANoMAT accounts, "Im Launcher beitreten", "Spiel installieren", "Mitspieler suchen", the join options and the estimated footprint](/screens/servers-dark.png) | ![Server overview in light theme with the same expanded UT2004 row](/screens/servers-light.png) |
| `SERVER OVERVIEW · DARK` — expanded row: "Hier spielen", launcher join, install, find players | `SERVER OVERVIEW · LIGHT` — same row |

`/servers` is the one overview: the current LAN (`?event=` for another) plus the permanent servers.
It starts empty, so an empty state is a valid one. An event list shows the servers that are Ready or
NotResponding, plus an event-level preset server that is still `provisioning`; a Pending, Failed or
Stopped match server has nothing to join and is left out. The permanent list also keeps an `offline`
server, because "is it up?" is the question there. The cross-event browser lives at `/servers/all`:
one card per server of every public, non-archived event plus the permanent ones, grouped by game, with
a game filter and a search over name, map, event and address.

| Dark | Light |
|---|---|
| ![Server browser in dark theme, grouped by game, with the game filter, the search field and a ready Bunker Bash Deluxe card carrying "Hier spielen", the launcher join, install and find-players actions](/screens/servers-all-dark.png) | ![Server browser in light theme with the same grouped cards and filters](/screens/servers-all-light.png) |
| `SERVER BROWSER · DARK` — grouped by game, filter, search, "Hier spielen" | `SERVER BROWSER · LIGHT` — same cards |

| Dark | Light |
|---|---|
| ![Server browser in dark theme filtered to a NotResponding server: the "answers no queries" warning, the launcher actions and the line that the files are not on the LAN right now](/screens/servers-all-lan-missing-dark.png) | ![Server browser in light theme with the same NotResponding card and files line](/screens/servers-all-lan-missing-light.png) |
| `SERVER BROWSER · LAN MISSING · DARK` — NotResponding plus the missing-files line | `SERVER BROWSER · LAN MISSING · LIGHT` — same card |

The Filament **Server links** table is the orga view: match, players, Pelican id, status, address and
the start/stop/restart/deprovision actions, plus the start-from-preset action in the header.

| Dark | Light |
|---|---|
| ![Server links admin table in dark theme with match, players, status, address and deprovision](/screens/admin-server-links-dark.png) | ![Server links admin table in light theme with match, players, status, address and deprovision](/screens/admin-server-links-light.png) |
| `SERVER LINKS · DARK` — match, status, address, deprovision, start from preset | `SERVER LINKS · LIGHT` |

## Guards and rules

- A server is listed to participants in the states above; `pending`, `failed` and `install_failed`
  match servers stay orga-only.
- `provisioning` and `not_responding` are `warn`, not alarms; the UI explains what is happening.
- A plain `address:port` without a URL scheme is a copyable mono value, not a dead button.
- The `install` block on a server row comes from the `GameInstallLinks` contract. `GameRecipes`
  answers with the published recipe the lineup allows (`LauncherLineup::allowsSlug()`: the configured
  `LAUNCHER_LINEUP`, the flag `game_recipes.in_lineup`, or "unrestricted" when the list is empty)
  whose slug equals the game's slug first, then the recipe whose search terms match the game's name or
  slug. No match means `install: null`, and the row keeps its own connect string and install hint.
  With the module off, a null implementation answers.
- `lanReady` says whether a fresh install can work right now: every required base archive or disc
  image of the recipe is an approved upload of a non-draft event (pinned, or matched by file name) or
  names an external download. It never depends on who looks, and `launcherJoinUrl` /
  `launcherSupported` do not depend on it: a PC that already has the game still joins with one click.
  The answer is cautious about platforms — one missing base makes it false although another
  platform's base may be there — which is why the website says a fresh install *can* fail. That is a
  different question from the game page's **● AUF DER LAN** / **NOCH NICHT AUF DER LAN** state, which
  means "playable here": a running server of the game counts there even while the install file is
  missing.
- `launcherJoinUrl` is `lanomat://join/<slug>`, built only when the launcher can install the recipe.
  UT2004 passes the server as `?address=<host>:<port>`; every other recipe passes the server's own
  join URL as `?url=…`, and a server with an address but no accepted join URL gets the bare link.
  The website labels the bare link **Im Launcher starten** (it installs and starts the game, the
  player picks the server in the game) and only a link that names the server **Im Launcher
  beitreten**. Neither an address+port nor an accepted join URL means no launcher link at all.
- A server gets free allocations from the Pelican node whose ports lie in the configured pool
  (lowest first), plus its preset's fixed ports; Pelican refuses a port another server already
  holds, so two matches cannot get the same port.
- Readiness uses A2S (Source) or a Minecraft TCP query; the protocol is part of the server link.
- Permanent servers (`permanent=true`) have no event/match and are not cleaned up with an event.
- `ServerLinkUpdated` drives warmup and surface updates; the module never calls Tournaments directly.

## Docs for agents

`servers_list` with an optional `event_slug`; without it, the permanent servers are returned. Its
row is the smaller one: id, title, game, status, `permanent`, `manual` and the join info — no install
block and no accounts. The REST answer is `GET /api/v1/servers`: every active event's servers plus
the permanent ones, with the website's row shape (including `install`) but no player names and
`occupants: null`.

<div class="callout-live">
  <strong>Server down is not LAN down.</strong> A failed provision degrades one match; the bracket,
  Discord and voice keep working.
</div>
