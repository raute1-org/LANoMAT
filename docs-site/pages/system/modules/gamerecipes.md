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
- **Agents** read the recipe skill and call `lanomat_plan_recipe` before downloading anything.

## Key screens

- `/games` — the participant **[games library](/docs/system/games-library)**: published recipes,
  LAN dot, "Nur LAN-Spiele", install/launch, live servers and running tournaments.
- `/games/{slug}` — one game: install deep link, launch, server list, tournaments, "Runde ankündigen".
- `/recipes` — recipe catalogue with base/mod badges and last update.
- `/recipes/{slug}` — content list, avatars, files, install command.
- `/admin/game-recipes` — publish, unpublish, edit metadata.

## Guards and rules

- The **base game is required**; a mod is optional and must declare `requires` on the base.
- Every downloadable byte comes from the LAN file host through a signed URL. No recipe embeds a
  public internet link as its primary source.
- `plan` is pure: it resolves files and sizes but downloads nothing. The agent confirms before the
  transfer.
- Avatars are **multi-select** content: zero or more, each with a size and a target path.
- A recipe is versioned by `updated_at` and a content hash; the agent refuses a stale plan.
- Deleting a recipe never deletes files still referenced by another recipe.
- An install is idempotent: re-running it verifies hashes and skips what is already there.

## Docs for agents

`lanomat_list_recipes`, `lanomat_get_recipe`, `lanomat_get_recipe_skill`,
`lanomat_plan_recipe`, `lanomat_list_recipe_files`, `lanomat_list_recipe_avatars`,
`lanomat_contribute_recipe`.

<div class="callout-live">
  <strong>Read the skill first.</strong> The YAML is metadata; the SKILL.md carries the install
  steps, the pitfalls and the verify step. Agents should read both before planning.
</div>
