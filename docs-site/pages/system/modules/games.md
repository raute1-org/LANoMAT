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
