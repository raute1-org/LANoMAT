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
- `/games/share` — **Spiel teilen**: upload or pick an archive, describe the game, follow your own
  shares. It creates a draft portable recipe with the archive pinned.
- `/recipes` — recipe catalogue with base/mod badges and last update.
- `/recipes/{slug}` — content list, avatars, files, install command.
- `/admin/recipes` — **Spielrezepte**: the review queue, publish, unpublish, edit metadata, the
  lineup switch and the recipe's pull request.

## The review queue

The orga side is one Filament table (`/admin/recipes`) with a navigation badge: the number of
contributed drafts still waiting for a reviewer, in warning colour, with the tooltip **„Geteilte
Spiele, die auf Prüfung warten“**. No badge means the queue is empty.

| Column | What it shows |
|---|---|
| **Titel**, **Slug**, **Version** | The recipe's identity; slug and version are toggleable |
| **Status** | `Entwurf`, `Veröffentlicht` or `Archiviert` |
| **Beitrag von** | The contributor of a shared game; `—` for a curated recipe |
| **Datei** | The state of the pinned LAN file: **„Datei wartet auf Freigabe“**, **„Datei abgelehnt“**, **„Datei fehlt“**; `—` when the recipe pins no file |
| **In der Spieleliste zeigen** | The lineup flag as an icon: a green check or a grey minus |
| **Pull Request** | The catalogue state (**Pull Request offen**, **Im Katalog**, **Pull Request geschlossen**, **Nicht gesendet**, **Fehlgeschlagen**). Only a state that exists in the repository links to it; a blocked or failed one stays local |
| **Zuletzt geprüft**, **Dateien**, **Aktualisiert** | Review stamp, file count, last update |

Filters: **Status**, the **Geteilte Spiele** toggle (rows with a contributor), the **Spieleliste**
ternary (**Alle**, **In der Spieleliste**, **Nicht in der Spieleliste**) and **Pull Request**. The
list is sorted by **Aktualisiert**, newest first.

| Dark | Light |
|---|---|
| ![Game recipes admin table in dark theme with publish state, version, file count and last update](/screens/admin-recipes-dark.png) | ![Game recipes admin table in light theme with publish state, version, file count and last update](/screens/admin-recipes-light.png) |
| `RECIPES · DARK` — publish state, version, file count, last update | `RECIPES · LIGHT` |

The edit page carries the recipe itself: description, homepage, license, the `SKILL.md` and every
required file as a typed row with a role and a filename pattern. The header actions are
**Veröffentlichen**, **Dateien freigeben und veröffentlichen**, **Pull Request öffnen / aktualisieren**
and **Archivieren**.

- **Dateien freigeben und veröffentlichen** appears while the recipe is not published and one of its
  pinned files waits for approval or was rejected. It approves every pending pinned file and
  publishes the recipe in one transaction, so a shared game never stays half-released. A rejected pin
  disables the action (**„Eine verknüpfte Datei wurde abgelehnt. Verknüpfe zuerst eine andere Datei —
  abgelehnte Dateien können nicht freigegeben werden.“**); the orga points the requirement at a new
  upload first.
- **Veröffentlichen** publishes without touching files. A contributed recipe joins the player lineup
  (`in_lineup = true`); a curated recipe keeps whatever the orga chose with the toggle.
- **Archivieren** hides a published recipe and keeps its release stamp.

The **In der Spieleliste zeigen** toggle is the lineup switch (helper text: „Die Spieler sehen die
eingestellte Spieleliste plus jedes Rezept mit diesem Schalter. Ist keine Spieleliste eingestellt,
erscheint ohnehin jedes veröffentlichte Rezept.“).

| Dark | Light |
|---|---|
| ![Game recipe edit form in dark theme with SKILL.md, license and required ISO and patch rows](/screens/admin-recipe-edit-dark.png) | ![Game recipe edit form in light theme with SKILL.md, license and required ISO and patch rows](/screens/admin-recipe-edit-light.png) |
| `RECIPE EDIT · DARK` — SKILL.md, license, ISO and patch rows | `RECIPE EDIT · LIGHT` |

## Guards and rules

- The **base game is required**; a mod is optional and must declare `requires` on the base.
- `LauncherSupport` is the one place that decides `launcher_supported`: the UT2004 pilot, the
  hand-verified NFSU2 recipe, a browser recipe with `play_url`, or a portable recipe (required
  `base` archive, a file/dir check in a non-optional step, a launch target). `metadata.launcher_supported`
  is the orga override. The launcher unpacks, checks and starts; it never runs step commands.
- The **lineup** is the configured `LAUNCHER_LINEUP` plus every recipe with
  `game_recipes.in_lineup = true`; an empty `LAUNCHER_LINEUP` means no curation. The lineup drives
  `/games`, the launcher and the server browser. A recipe outside it stays in the database.
- Every downloadable byte comes from the LAN file host through a bearer-authenticated download URL. No recipe embeds a
  public internet link as its primary source.
- `plan` is pure: it resolves files and sizes but downloads nothing. The agent confirms before the
  transfer.
- Avatars are **multi-select** content: zero or more, each with a size and a target path.
- A recipe is versioned by `updated_at` and a content hash; the agent refuses a stale plan.
- Deleting a recipe never deletes files still referenced by another recipe.
- An install is idempotent: re-running it verifies hashes and skips what is already there.

## Shared games and the catalogue

A participant packs a ready-to-run game folder as `.zip` or `.tar.bz2`, uploads it to the file host
and describes it on `/games/share`. `ShareGame` refuses a foreign or rejected file, a file that is
not an archive, a taken title and the slug `share`; `PortableRecipeBuilder` writes the payload
(`metadata.portable: true`, one required `base` requirement, file checks, launch targets) and
`ContributeRecipe` stores it as the contributor's draft. The orga's part is one action plus one
switch: **Dateien freigeben und veröffentlichen** approves the pinned archive and publishes the
recipe, and publishing a contributed recipe sets **In der Spieleliste zeigen**. Read
[Share a game](/docs/guides/share-a-game) for the participant view.

## Pull requests into the git catalogue

A recipe is a database row; the git catalogue is what the next deployment ships. `GameRecipes`
bridges the two through the `RecipeRepository` contract: `GitHubRecipeRepository` over the GitHub
REST API, `NullRecipeRepository` when the module runs without a link, and a fake in tests. Off by
default (`RECIPES_PR_ENABLED`), and never used at runtime without `RECIPES_PR_REPOSITORY` and
`RECIPES_PR_TOKEN`.

| Piece | Behaviour |
|---|---|
| Trigger | `RECIPES_PR_TRIGGER=contribute` proposes as soon as a participant shares; `publish` waits for the orga. With the link configured, publishing a contributed or panel recipe always opens or updates the pull request |
| Branch and pull request | One per recipe: `RECIPES_PR_BRANCH_PREFIX` + slug (default `recipes/<slug>`). A later proposal moves the branch and updates the same pull request — never a second one |
| Files | `<RECIPES_PR_PATH>/<slug>/recipe.yaml`, `<slug>/SKILL.md` and the recipe's row in `_catalog.md`. Only text; game files stay on the LAN file host, and the body names requirement labels, never file ids or URLs |
| Safety check | Before the first request, the YAML, the skill, both commit titles and the body pass `PublicSafeText` (same rules as `scripts/ops/check-public-safe.sh`: private and public IPs, IPv6, e-mail addresses, private keys, SSH keys, bearer tokens). A refused recipe is **Nicht gesendet** and is never sent |
| States | **Pull Request offen** → **Im Katalog** / **Pull Request geschlossen** from the repository; **Nicht gesendet** and **Fehlgeschlagen** are local. The hourly `lanomat:recipes:pr-status` keeps the open ones honest |
| Panel | **Pull Request öffnen / aktualisieren** queues the proposal and says „Pull Request wird vorbereitet“; the **Katalog (Pull Request)** section on the edit page shows the state, the link, the refusal reason and the last sync |
| Commands | `lanomat:recipes:propose <slug>` in the foreground, `lanomat:recipes:pr-status [--json]` for the sweep, `lanomat:recipes:export <path> --format=repository` to write the repository layout by hand |

Check the link before the LAN: Preflight's `recipe_repository` check is green when the token may read
and push, and warns — never goes down — when it cannot.

The catalogue itself is synced with `lanomat:recipes:sync` from the bundled directory, an SMB share
or a git source. A resync keeps a moderator's status and the admin's file pins. `--only=<slug>`
narrows what is **updated**, never what counts as present, so `--only=<slug> --prune` never deletes
the other recipes; `--prune` only removes rows of that source whose recipe directory is gone, and an
empty or unreadable source prunes nothing. `node resources/recipes/schema/validate.mjs [dir]`
checks the schema, the `SKILL.md` contract, uniqueness, the draft rules and the `_catalog.md` rows;
`.github/workflows/recipes.yml` runs the same check on recipe pull requests.

## Docs for agents

`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute`;
the skill itself is the resource `lanomat://recipes/{slug}/skill`.

<div class="callout-live">
  <strong>Read the skill first.</strong> The YAML is metadata; the SKILL.md carries the install
  steps, the pitfalls and the verify step. Agents should read both before planning.
</div>
