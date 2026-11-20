---
title: Games library
description: The participant catalogue at /games — install, launch, join a LAN server and announce a round.
sidebar_icon: gamepad-2
---

The games library is the participant-facing front door to the recipe catalogue. It answers three
questions in one place: *what can I install, what is running right now, and who wants to play?*

Open it at `/games`; a game has its own page at `/games/<slug>`, for example
`https://lan.example/games/ut2k4`.

<div class="live">LIVE 17:42 · UT2004 server up · 6 players · CS 1.6 tournament in round 2</div>

## The list at `/games`

The left sidebar lists every **published** recipe, with a LAN dot when the game is ready to play on
this LAN. The main area has a search field and one filter:

- **Nur LAN-Spiele** — shows only games whose required files are already on the LAN file host, or
  that have a live LAN server. This is the "what can we actually play tonight?" switch.

The list is intentionally not a store. A recipe without LAN content is still installable from a
supplied disc, but the LAN dot tells you whether the fast path exists.

| Element | Meaning |
|---|---|
| LAN dot | Required content is present on the file host and/or a server is live |
| Search | Title, summary and slug; a recipe alias such as `ut2k4` works in the URL (`/games/ut2k4`) but not in the search box |
| Nur LAN-Spiele | Hide everything that would need a download from the internet |

## The game page at `/games/<slug>`

### Install

The primary action is **Install**. It offers two equivalent paths:

- **Deep link:** `lanomat://install/<slug>` — opens the pi agent with the recipe selected.
- **Copyable command:** `lanomat-agent install <slug>` — for a terminal, a script or a machine
  without the deep-link handler.

The agent still reads the recipe skill and asks about optional content. The button does not bypass
the plan; it only saves typing the slug.

### Launch

When the game has a launch path, a **Start** button appears:

- `lanomat://launch/<slug>` for a local install, or
- a `play_url` when the game runs in the browser (the Ridge Racer cabinet, for example).

A game that is not installed shows Install only. A singleplayer game shows Install and Start, with
no server section.

### Live servers

If LANoMAT knows a running server for the game, the page lists it with the join string, map and
player count. The join string is the same one the match page and the native client use — a
`steam://` URL, a plain `address:port` for UT2004, or a web URL. A plain address is a copyable mono
value, not a dead button.

### Running tournaments

Tournaments for the game are listed with status, start time and a link to the bracket. Matching is
by the game's name and slug against the recipe's search terms, so a tournament created for the
catalogue entry "Unreal Tournament 2004" shows up on the UT2004 game page. Only tournaments in
`announced`, `enrollment`, `check_in` or `live` are listed; a finished one drops off. A draft recipe
is not listed to participants until it is published.

### Runde ankündigen

One click creates an LFG post for this game and links straight to the event voice chat at `/talk`.
It is the shortest path from "we should play" to "everyone is in the room":

1. The post names the game, the event and the open slots.
2. The voice link opens the event's general room.
3. The post expires like every LFG post; the orga can remove it.

The action requires a logged-in account and a publicly visible event; it does not require a
registration (the board is deliberately low-friction). It does not create a tournament, start a
server or reserve a seat.

## What the page never does

- It never installs without confirmation; the deep link only opens the agent.
- It never invents a server: the list comes from `ServerLink` read-models.
- It never shows a draft recipe or a draft tournament to a participant.
- It never puts e-mail, Discord ids or network data into the page.
- It never claims a game is "LAN" because a recipe exists; the dot means content or a live server.

## For operators

Publish the recipe, put the required file on the LAN file host, and the LAN dot appears. Create the
tournament and let the match-server preset provision a server; the game page picks it up through the
same read-models as the match page. Nothing on `/games` needs a separate configuration screen.

<div class="callout-live">
  <strong>The library is a view, not a new module.</strong> It reads the recipe catalogue, the
  server links, the tournaments and the LFG module. If one of those is off, the page still renders
  what is left — a catalogue without a server section is a valid state.
</div>
