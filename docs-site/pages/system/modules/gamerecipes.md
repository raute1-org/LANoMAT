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
- Filament **Game recipes** — publish, unpublish, edit metadata, review drafts.
- `php artisan lanomat:recipes:sync` / `lanomat:recipes:export` — import from the bundled directory,
  SMB or git; export skills as a portable bundle.

## Guards and rules

- The **base game is required**; a mod is optional and must declare `requires` on the base.
- Every downloadable byte comes from the LAN file host through a bearer-authenticated URL. A recipe
  may carry an external `url` as a marked fallback, but the agent asks before using it.
- `recipe_install_plan` is pure: it resolves files and sizes but downloads nothing. The agent
  confirms before the transfer. Every plan is recorded for orga support.
- Avatars are **multi-select** content: zero or more, each with a size and a LAN availability flag.
- A recipe is versioned by `updated_at` and a content hash; the server stores the plan it handed
  out, so a changed recipe is visible in a re-plan.
- Deleting a recipe never deletes files still referenced by another recipe.
- An install is idempotent: re-running it verifies hashes and skips what is already there.

## Docs for agents

`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute`, plus
the `lanomat://recipes/{slug}/skill` resource. Files come through `file_download_url`, which needs a
file id from the plan or `files_list`.

<div class="callout-live">
  <strong>Read the skill first.</strong> The YAML is metadata; the SKILL.md carries the install
  steps, the pitfalls and the verify step. Agents should read both before planning.
</div>
