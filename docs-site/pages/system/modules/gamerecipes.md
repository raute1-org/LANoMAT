---
title: Game recipes
description: Recipes as agent skills, the LAN installer, files and multi-select avatars.
sidebar_icon: package
---

`GameRecipes` is the bridge between a LAN's file host and a player's PC. A **recipe** describes how
to install one game: a `recipe.yaml` with the facts and a `SKILL.md` that an agent can read. The
installer (the pi agent) plans the install, downloads the required content from the LAN file host
and optionally adds mods and avatars.

<div class="live">LIVE 17:42 · UT2004 · base install · 3 avatars selected</div>

## Who uses it

- **Teilnehmer** browse recipes, pick avatars, and run the install through the pi agent.
- **Orga** publishes recipes, uploads ISOs/files, and keeps the SMB import in sync.
- **Agents** read the recipe skill and call `recipe_install_plan` before downloading anything.

## Key screens

- `/games` — the participant **[games library](/docs/system/games-library)**: published recipes,
  LAN dot, "Nur LAN-Spiele", install/launch, live servers and running tournaments.
- `/games/{slug}` — one game: install deep link, launch, server list, tournaments, "Runde ankündigen".
- `/recipes` — recipe catalogue with base/mod badges and last update.
- `/recipes/{slug}` — content list, avatars, files, install command.
- `/admin/game-recipes` — publish, unpublish, edit metadata.

The orga side is one Filament table: publish state, version, file count and last update per recipe.

| Dark | Light |
|---|---|
| ![Game recipes admin table in dark theme with publish state, version, file count and last update](/screens/admin-recipes-dark.png) | ![Game recipes admin table in light theme with publish state, version, file count and last update](/screens/admin-recipes-light.png) |
| `RECIPES · DARK` — publish state, version, file count, last update | `RECIPES · LIGHT` |

The edit form carries the recipe itself: description, homepage, license, the `SKILL.md` and every
required file as a typed row with a role and a filename pattern.

| Dark | Light |
|---|---|
| ![Game recipe edit form in dark theme with SKILL.md, license and required ISO and patch rows](/screens/admin-recipe-edit-dark.png) | ![Game recipe edit form in light theme with SKILL.md, license and required ISO and patch rows](/screens/admin-recipe-edit-light.png) |
| `RECIPE EDIT · DARK` — SKILL.md, license, ISO and patch rows | `RECIPE EDIT · LIGHT` |

## Guards and rules

- The **base game is required**; a mod is optional and must declare `requires` on the base.
- Every downloadable byte comes from the LAN file host through a bearer-authenticated download URL. No recipe embeds a
  public internet link as its primary source.
- `plan` is pure: it resolves files and sizes but downloads nothing. The agent confirms before the
  transfer.
- Avatars are **multi-select** content: zero or more, each with a size and a target path.
- A recipe is versioned by `updated_at` and a content hash; the agent refuses a stale plan.
- Deleting a recipe never deletes files still referenced by another recipe.
- An install is idempotent: re-running it verifies hashes and skips what is already there.

## Docs for agents

`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute`;
the skill itself is the resource `lanomat://recipes/{slug}/skill`.

<div class="callout-live">
  <strong>Read the skill first.</strong> The YAML is metadata; the SKILL.md carries the install
  steps, the pitfalls and the verify step. Agents should read both before planning.
</div>
