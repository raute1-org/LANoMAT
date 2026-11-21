---
title: Games library
description: The participant catalogue at /games — install, launch, join a LAN server and announce a round.
sidebar_icon: gamepad-2
---

The games library is the participant-facing front door to the recipe catalogue. It answers three
questions in one place: *what can I install, what is running right now, and who wants to play?*

Open it at `/games`; a game has its own page at `/games/<slug>`, for example
`https://lan.raute1.org/games/ut2k4`.

<div class="live">LIVE 17:42 · UT2004 server up · 6 players · CS 1.6 tournament in round 2</div>

<div class="callout-live">
  <strong>Live example.</strong> The operator runs the UT2004VR/VRM instance for this catalogue at
  <a href="https://ut.raute1.org/">ut.raute1.org</a>. Recipes carry a deployed instance as
  <code>metadata.live_url</code>; a game page that reads the recipe metadata can link it next to
  Install and Start.
</div>

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
| Search | Title, alias, genre and tags ("ut2k4", "racing", "vr") |
| Nur LAN-Spiele | Hide everything that would need a download from the internet |

| Dark | Light |
|---|---|
| ![Games library, dark](/screens/games-dark.png) | ![Games library, light](/screens/games-light.png) |
| `Spiele · DARK` — published catalogue, LAN dot on UT2004, search and filter | `Spiele · LIGHT` |

## The game page at `/games/<slug>`

| Dark | Light |
|---|---|
| ![UT2004 game page, dark](/screens/game-ut2004-dark.png) | ![UT2004 game page, light](/screens/game-ut2004-light.png) |
| `Spiel · DARK` — Install/Start, join string, "Runde ankündigen", install steps | `Spiel · LIGHT` |

### Install

The primary action is **Installieren**. Its target depends on whether the launcher can install the
recipe and whether a launcher build is published for your OS:

- Both yes: **Installieren** opens the launcher download page on a fresh PC, and a quiet „Launcher
  schon da? **Im Launcher öffnen**“ uses the deep link `lanomat://install/<slug>`.
- Otherwise: **Installieren** fires the deep link itself, which opens the pi agent.

A browser game with a `play_url` shows **Spielen** instead; there is nothing to install.

The launcher unpacks a portable recipe's archive, checks the start file and starts the game — it
never runs the recipe's step commands. The agent path (`lanomat-agent install <slug>`, for a
terminal or a script) is still there and reads the skill, including the questions about optional
content. The game page's hint says the same in German: „Noch kein Launcher auf diesem PC?
„Installieren“ führt zum Launcher-Download“.

### Launch

When the game has a launch path, a start action appears:

- `lanomat://launch/<slug>` for a local install, or
- **Spielen** with a `play_url` when the game runs in the browser (the Ridge Racer cabinet, for
  example).

A game that is not installed shows the install action only. A singleplayer game shows install and
start, with no server section and a **Solo** badge.

### Live servers

If LANoMAT knows a running server for the game, the page lists it with map and player count, and one
honest action per row, in this order:

1. A launcher join link (`lanomat://join/<slug>?address=<host>:<port>`, or `?url=<urlencoded url>`)
   makes **Beitreten** the primary action, with the sentence „Der Launcher installiert das Spiel bei
   Bedarf und startet direkt auf diesem Server.“
2. **Anders verbinden** keeps the direct ways for a PC that already has the game: the game's own
   join options (a `ut2004://` or `steam://` form), an allowlisted connect link, or the address as
   copyable mono text.
3. Without a launcher link the row shows exactly what it can prove — join options, an allowlisted
   link, or **Adresse kopieren** — never a guessed `steam://connect/…`.

The same recipe actions appear wherever a server row knows its game. On `/servers` and
`/servers/all` a card offers **Im Launcher beitreten** with the hint „Der Launcher installiert das
Spiel, falls es fehlt, und startet es auf diesem Server.“, **Spiel installieren** (or **Zum Spiel**
in a narrow row) and **Mitspieler suchen**, which opens the page's „Zusammen spielen“ card. On
`/launcher`, each live server has **Beitreten**; a server with nothing to join keeps **Server
ansehen** and a quiet **Zum Spiel**.

### Running tournaments

Tournaments for the game are listed with status, round and a link to the bracket. **CS 1.6** is the
canonical example: it is a draft recipe in the catalogue, and once it is published and the event has
a CS 1.6 tournament, its game page surfaces the tournament and the provisioned servers in the same
view. A draft recipe is not listed to participants until it is published.

### Runde ankündigen

One click creates an LFG post for this game and links straight to the event voice chat at `/talk`.
It is the shortest path from "we should play" to "everyone is in the room":

1. The post names the game, the event and the open slots.
2. The voice link opens the event's general room.
3. The post expires like every LFG post; the orga can remove it.

The action requires a non-cancelled event registration, like every LFG post. It does not create a
tournament, start a server or reserve a seat.

### Spiel teilen

Anyone can add a game. The entry points are deliberate and all lead to `/games/share`:

- **Spiel teilen** in the page header of `/games`;
- the same link at the bottom of the games sidebar;
- the empty states — „Noch keine Spiele in der Bibliothek“ and „Kein Spiel passt“ each carry „Dein
  Spiel fehlt? Teile es mit der LAN.“;
- **Als Spiel teilen** on your own `.zip` or `.tar.bz2` row on the Files page, which preselects that
  file;
- **Spiel teilen** in the launcher, which opens the page in your browser.

A share becomes a draft recipe. The library only shows it after the orga approves the archive,
publishes the game and sets `game_recipes.in_lineup`; then it appears here, in the launcher and
next to its servers like any other game. Read [Share a game](/docs/guides/share-a-game).

## Same list, same words, in the launcher

The desktop launcher opens on **Spiele** with the same published recipes, the same **LAN** badge and
the same action words (**Installieren**, **Starten**) as this page — and the start-page chat at `/`
renders its game and server cards from the same read models, so the three doors never disagree. The
launcher's **Zuletzt gespielt** entry (the newest verified install, with target folder and verified
files) is the equivalent of the first row here; a game is only "installed" because the launcher
verified it, never because a recipe exists — and only on that terminal, since every machine pairs
its own launcher. Read
[One-click launcher](/docs/guides/launcher) for the download, the one-time pairing and the phase
list with percent, speed and ETA, and
[Start-page chat](https://github.com/raute1-org/LANoMAT/blob/main/docs/design.md#start-page-chat-the-front-door-after-login--pilot)
for the card anatomy and the typewriter rules.

## What the page never does

- It never installs without confirmation; the deep link only opens the agent.
- It never invents a server: the list comes from `ServerLink` read-models.
- It never shows a draft recipe or a draft tournament to a participant.
- It never puts e-mail, Discord ids or network data into the page.
- It never claims a game is "LAN" because a recipe exists; the dot means content or a live server.

## For operators

Publish the recipe, put the required file on the LAN file host, and the LAN dot appears. For a game
a participant shared, approve the archive, publish the draft recipe and set `game_recipes.in_lineup`
so it joins the lineup. Create the tournament and let the match-server preset provision a server;
the game page picks it up through the same read-models as the match page. Nothing on `/games` needs
a separate configuration screen.

The server browser's recipe actions come from the same catalogue: a published, lineup-allowed
recipe is matched to the server's game, and the launcher join link is only built when the launcher
can actually install that recipe. A server whose game has no recipe keeps its own join string and
install hint.

<div class="callout-live">
  <strong>The library is a view, not a new module.</strong> It reads the recipe catalogue, the
  server links, the tournaments and the LFG module. If one of those is off, the page still renders
  what is left — a catalogue without a server section is a valid state.
</div>
