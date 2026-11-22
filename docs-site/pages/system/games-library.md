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

<div class="callout-live">
  <strong>Live example.</strong> The operator runs the UT2004VR/VRM instance for this catalogue at
  <a href="https://ut.raute1.org/">ut.raute1.org</a>. Recipes carry a deployed instance as
  <code>metadata.live_url</code>; a game page that reads the recipe metadata can link it next to
  Install and Start.
</div>

## The list at `/games`

The left sidebar lists every **published** recipe the lineup allows, with a LAN dot when the game is
ready to play on this LAN. The main area has a search field and one filter:

- **Nur LAN-Spiele** — shows only games with a required file already on the LAN file host (one is
  enough) or with a listed LAN server. This is the "what can we actually play tonight?" switch.

The list is intentionally not a store. A recipe without LAN content is still installable from a
supplied disc, but the LAN dot tells you whether the fast path exists. Above the grid the page names
the event the rest of the app treats as current; it prefixes **● LIVE** only while that event is live.

| Element | Meaning |
|---|---|
| LAN dot | Required content is present on the file host and/or a server is live |
| Search | Title, summary and slug — "unreal" and "tournament" both match UT2004; aliases, genre and tags are not searched |
| Nur LAN-Spiele | Hide games with neither a required file on the LAN file host nor a listed server |

The LAN dot is the "playable here" question. A server's own install block asks a narrower one —
whether a fresh install can work right now (`lanReady`) — so a running server can light the dot while
the row next to it says a fresh install may fail.

| Dark | Light |
|---|---|
| ![Games library, dark](/screens/games-dark.png) | ![Games library, light](/screens/games-light.png) |
| `Spiele · DARK` — published catalogue, LAN dot on UT2004, search and filter | `Spiele · LIGHT` |

## The game page at `/games/<slug>`

| Dark | Light |
|---|---|
| ![UT2004 game page, dark](/screens/game-ut2004-dark.png) | ![UT2004 game page, light](/screens/game-ut2004-light.png) |
| `Spiel · DARK` — Install/Start, join string, "Runde ankündigen", install steps | `Spiel · LIGHT` |

A game a participant shared looks the same once the orga has published it: the recipe's **● AUF DER
LAN** badge, **Installieren** and **Im Launcher öffnen**, **Starten**, the install and start commands,
the server card with the launcher action and **Runde ankündigen** / **Sprachchat** beside it. A game
whose required file is not on the LAN and has no listed server says **NOCH NICHT AUF DER LAN**.

| Dark | Light |
|---|---|
| ![Published shared game page in dark theme: "AUF DER LAN" badge, "Installieren", "Im Launcher öffnen", "Starten", both agent commands, the server card with the launcher join, and the "Runde ankündigen" and "Sprachchat" buttons](/screens/game-shared-dark.png) | ![The same shared game page in light mode](/screens/game-shared-light.png) |
| `SHARED GAME · DARK` — published share, launcher actions, server card | `SHARED GAME · LIGHT` — same page |

### Install

The primary action is **Installieren**. Its target depends on whether a launcher build is published
and whether the launcher can install the recipe:

- Both yes: **Installieren** opens the `/app` download page on a fresh PC, and a quiet
  **Im Launcher öffnen** beside it uses the deep link `lanomat://install/<slug>`; a line underneath
  says „Noch kein Launcher auf diesem PC? „Installieren“ führt zur Seite App herunterladen. …“.
- Otherwise: **Installieren** fires the deep link itself, which opens an installed launcher, and the
  notice under the buttons says „Ist der LANoMAT Launcher schon auf diesem PC, öffnet der Klick die
  Installation. Sonst gelten die manuellen Schritte unten — Installationsbefehl und Dateien stehen
  direkt darunter.“ There is no download link in that state, because no build is published.

A browser game with a `play_url` shows **Spielen** instead; there is nothing to install.

The launcher unpacks a portable recipe's archive, checks the start file and starts the game — it
never runs the recipe's step commands. The agent path (`lanomat-agent install <slug>`, for a
terminal or a script) is still there and reads the skill, including the questions about optional
content.

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

1. A launcher link is the first action: **Im Launcher beitreten** when the link names the server
   (`lanomat://join/<slug>?address=<host>:<port>`, or `?url=<urlencoded url>`), with the sentence
   „Der Launcher installiert das Spiel bei Bedarf und startet direkt auf diesem Server.“; **Im
   Launcher starten** for the bare `lanomat://join/<slug>`, with „Der Launcher installiert das Spiel
   bei Bedarf und startet es. Den Server wählst du dann im Spiel; seine Adresse steht oben.“
2. **Anders verbinden** keeps the direct ways for a PC that already has the game: the game's own
   join options (a `ut2004://` or `steam://` form), an allowlisted connect link, or the address as
   copyable mono text.
3. Without a launcher link the row shows exactly what it can prove — join options, an allowlisted
   link, or **Adresse kopieren** — never a guessed `steam://connect/…`.

The same recipe actions appear wherever a server row knows its game. On `/servers` and
`/servers/all` a row offers the launcher action (**Im Launcher beitreten** with the hint „Der
Launcher installiert das Spiel, falls es fehlt, und startet es auf diesem Server.“, or **Im Launcher
starten** with its own hint), **Spiel installieren** (or **Zum Spiel** in a narrow row) and
**Mitspieler suchen**, which opens the page's „Zusammen spielen“ card.

### Running tournaments

Tournaments for the game are listed with status, round and a link to the bracket. **CS 1.6** is the
canonical example: it is a draft recipe in the catalogue, and once it is published and the event has
a CS 1.6 tournament, its game page surfaces the tournament and the provisioned servers in the same
view. A draft recipe is not listed to participants until it is published.

### Runde ankündigen

One click creates an LFG post for this game, titled „Runde <Spiel>“, with the recipe's
`players_max` (or 5) as the open slots. A toast answers „Runde angekündigt. Schau in den
Sprachchat.“, and the **Sprachchat** button beside it opens `/talk` — the page stays where it is, so
the announcement never loses the install or server actions. The post expires like every LFG post
(three hours by default) and the orga can remove it.

Any logged-in user can use it while the LFG module is on and an event is current — an LFG post needs
no registration (unlike a tournament entry). It does not create a tournament, start a server or
reserve a seat.

### Spiel teilen

Anyone can add a game. The entry points are deliberate and all lead to `/games/share`:

- **Spiel teilen** in the page header of `/games`;
- the same link at the bottom of the games sidebar — only on a game's own page `/games/<slug>`, and
  only from the `lg` breakpoint;
- the empty states — „Noch keine Spiele in der Bibliothek“ and „Kein Spiel passt“ each carry „Dein
  Spiel fehlt? Teile es mit der LAN.“;
- **Als Spiel teilen** on your own `.zip` or `.tar.bz2` row on the Files page, which preselects that
  file;
- **Spiel teilen** in the launcher, which opens the page in your browser.

A share becomes a draft recipe. The library only shows it after the orga approves the archive and
publishes the game; publishing a contributed recipe sets `game_recipes.in_lineup`, so it appears
here, in the launcher and next to its servers like any other game (the orga can switch the toggle
off again). Read [Share a game](/docs/guides/share-a-game).

## Same list, on every client

The desktop launcher opens on **Spiele** with the same published recipes in **Spiele vom Server**,
the same **LAN** badge and the same action words (**Installieren**, **Starten**) as this page. The
native voice app has a **read-only Spiele tab**: it lists the published lineup
(`GET /api/voice-client/v1/recipes`), opens a game's detail with its install plan
(`…/recipes/{slug}` and `…/recipes/{slug}/plan`, exact slug) and says that installing and starting
follow with a later app version. The start-page chat at `/` renders its game and server cards from
the same read models, so the surfaces never disagree. In the launcher, **Meine Bibliothek** lists
this device's own installs with target folder and the number of verified files; a game is only
"installed" because the launcher verified it on that terminal, never because a recipe exists — every
machine pairs its own launcher. The one download page for both clients is `/app` (**App
herunterladen**; `/launcher` and `/talk/client` redirect there). Read
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
a participant shared, **Dateien freigeben und veröffentlichen** approves the archive and publishes
the draft in one action, which also sets `game_recipes.in_lineup`; use the **In der Spieleliste
zeigen** toggle to publish a game without a player-facing link. Create the tournament and let the
match-server preset provision a server; the game page picks it up through the same read-models as the
match page. Nothing on `/games` needs a separate configuration screen.

The server browser's recipe actions come from the same catalogue: a published, lineup-allowed
recipe is matched to the server's game, and the launcher join link is only built when the launcher
can actually install that recipe. A server whose game has no recipe keeps its own join string and
install hint.

<div class="callout-live">
  <strong>The library is a view, not a new module.</strong> It reads the recipe catalogue, the
  server links, the tournaments and the LFG module. If one of those is off, the page still renders
  what is left — a catalogue without a server section is a valid state.
</div>
