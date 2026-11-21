---
title: Games
description: The cross-event game catalogue, team sizes, server presets and install hints.
sidebar_icon: gamepad-2
---

`Games` is the catalogue tournaments and votes point at. A game is cross-event: its name, slug, icon
and team-size range stay stable across LANs, so stats and history accumulate.

## Who uses it

- **Orga** maintains the catalogue, sets min/max team size, attaches a Pelican egg and server
  presets, and writes an install hint.
- **Teilnehmer** see the game on tournament pages and in the vote options.

## Key screens

- Filament **Games** — name, slug, icon, team sizes, egg, presets, install hint, spectate hint.
- Tournament create/edit — game picker; the tournament inherits team-size defaults.

Participants meet the catalogue through the games library at `/games`: one card per published
recipe, with a LAN badge when the content is already on the file host. A card offers
**Installieren** (the launcher download when the launcher can install the recipe, else the deep
link), **Im Launcher öffnen** when a build exists, **Spielen** for a browser game, and **Details**
when there is nothing to install. The game page's server rows offer **Beitreten** through the
launcher, **Anders verbinden** for the direct ways and **Mitspieler suchen**; the library header and
sidebar also lead to **Spiel teilen**. Only a published, lineup-allowed recipe gets here.

| Dark | Light |
|---|---|
| ![Games library in dark theme with published recipe cards, LAN badges and install buttons](/screens/games-dark.png) | ![Games library in light theme with published recipe cards, LAN badges and install buttons](/screens/games-light.png) |
| `GAMES · DARK` — published catalogue, LAN badges, install per title | `GAMES · LIGHT` |

**Nur LAN-Spiele** narrows the grid to games that are actually playable tonight — content on the
LAN or a server running.

| Dark | Light |
|---|---|
| ![Games library in dark theme with the Nur LAN-Spiele filter active and two playable titles](/screens/games-filter-dark.png) | ![Games library in light theme with the Nur LAN-Spiele filter active and two playable titles](/screens/games-filter-light.png) |
| `GAMES · FILTER · DARK` — Nur LAN-Spiele on, two playable titles | `GAMES · FILTER · LIGHT` |

A single game page is where the catalogue entry, the recipe, the live server and the tournaments
meet.

| Dark | Light |
|---|---|
| ![UT2004 game page in dark theme with install, start, agent commands, a live server and install steps](/screens/game-ut2004-dark.png) | ![UT2004 game page in light theme with install, start, agent commands, a live server and install steps](/screens/game-ut2004-light.png) |
| `GAME · DARK` — install, start, join string, install steps | `GAME · LIGHT` |

## Guards and rules

- `min_team_size`/`max_team_size` define solo vs team tournaments; a tournament's `team_size` must
  fit the game.
- A game in use by a tournament cannot be deleted (`GameInUseException`).
- `pelican_egg_id` is optional: a game without an egg can still run a tournament, just without
  automatic server provisioning.
- Server presets are stored as typed domain objects, not raw JSON in the UI.
- Icons go to Laravel Storage; no Base64 in the database.
- Provider ownership hints (`games.provider`, `provider_app_id`) are advisory only.

## Docs for agents

`games_search` with an optional `query` returns the tournament game catalogue (name, slug, team sizes).

<div class="callout-live">
  <strong>The catalogue is small on purpose.</strong> It is not a store. If a game needs more than a
  name, an icon and a preset, that belongs in the recipe.
</div>
