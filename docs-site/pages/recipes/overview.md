---
title: How recipes work
description: recipe.yaml + SKILL.md = an Agent Skill that installs a game from the LAN file host.
sidebar_icon: package
---

A **game recipe** is a small, versioned description of how to put one game on a player's PC at the
LAN. It has two files:

```text
resources/recipes/<slug>/
├── recipe.yaml   # facts: title, platforms, content requirements, steps, checks
└── SKILL.md      # an Agent Skill: when to use it, how to ask, how to verify, what breaks
```

The YAML is what the installer and the API can validate and plan. The `SKILL.md` is what an agent
reads to behave well: which aliases the user might say, which questions to ask (avatars are a
multi-select), which steps are optional, and what the known failure modes are.

Participants do not read the YAML. They open the **[games library](/docs/system/games-library)** at
`/games`, which lists every published recipe the lineup allows, marks what is already on the LAN, and connects Install,
Launch, servers and tournaments in one page.

| Dark | Light |
|---|---|
| ![Games library listing published recipes with LAN dots and Install buttons, dark](/screens/games-dark.png) | ![Games library listing published recipes with LAN dots and Install buttons, light](/screens/games-light.png) |
| `LIBRARY · CATALOGUE · LAN FILTER · INSTALL` | `LIBRARY · LIGHT` |

<div class="live">LIVE 17:42 · recipe scan · 9 recipes · schema valid</div>

<div class="callout-live">
  <strong>Live example.</strong> The reference recipe's VR path is deployed by the operator at
  <a href="https://ut.raute1.org/">ut.raute1.org</a>. The recipe carries that address as
  <code>metadata.live_url</code>, so a page or agent that reads the recipe metadata can link it
  without hardcoding a host.
</div>

## The shape of `recipe.yaml`

```yaml
schema: lanomat.recipe/v1
slug: unreal-tournament-2004
title: Unreal Tournament 2004
aliases: ["ut2004", "ut2k4", "ut 2k4"]
version: "3374"
status: published                 # draft | published | archived
platforms: [windows, linux]
summary: Install UT2004 from the LAN disc, patch to OldUnreal 3374, optionally add UT2004VR.
description: |
  Longer prose for the recipe page.
license: "Epic Games; base game files are user-supplied (UT2004VR: MIT)"
skill:
  name: ut2004
  description: "Install Unreal Tournament 2004 … Use when the user says install ut2004."
requires: [...]                  # content requirements, see below
steps: [...]                     # ordered install steps
metadata:
  genre: arena-shooter
  players: "2-32"
  lan_recommended: true
  live_url: https://ut.raute1.org/   # optional: a deployed instance to link from the game page
  tags: [fps, vr, classic]
```

`version` is the recipe's own version, not the game's patch number — though for UT2004 it happens
to name the required patch. `slug` must equal the directory name. Aliases are unique across the
catalogue.

Two keys sit at the top level of the YAML, not under `metadata`: `play_url` (a page the player opens
instead of installing anything) and `launch_targets` (the ways to start an installed game, the first
entry being the default).

## Requirements: roles and matching

`requires[]` is how a recipe asks for content without hardcoding a URL. Each entry has a `role`, a
`label`, a list of glob `match` patterns and a `required` flag.

| Role | Meaning | Example |
|---|---|---|
| `base` | The base game itself | a GOG/Steam install or a disc image |
| `iso` | A disc image from the LAN file host | `ut2004*.iso` |
| `patch` | A required or recommended patch | OldUnreal 3374 |
| `mod` | Optional content that changes the game | UT2004VR |
| `tool` | A helper the steps need | Node.js, a converter |
| `avatar` | Optional multi-select personal content | `.vrm` avatars |
| `content` | Maps, packs, additional data | map packs |
| `config` | A config file to place | renderer settings |

Matching is against the LAN file host's file names, not the internet. A requirement may carry a
`size_bytes`, a `sha256` for verification, an optional `url` for provenance, and `notes` for the
human/agent reading the plan. Empty `sha256`/`url` are allowed.

`manual: true` marks a requirement the player supplies by hand: the installer asks for it and never
matches it on the share. A required manual entry needs neither `match` patterns nor a `url`.

| Dark | Light |
|---|---|
| ![Admin file overview with ISO, patch and avatar files marked as released, dark](/screens/admin-shared-files-dark.png) | ![Admin file overview with ISO, patch and avatar files marked as released, light](/screens/admin-shared-files-light.png) |
| `FILES · ISO · PATCH · AVATAR · RELEASED` | `FILES · LIGHT` |

**Base is required, extras are optional.** A recipe must be installable with only its
`required: true` entries. Optional mods and avatars never block the base install. If a user picks
no avatars, the install still succeeds.

## Steps and verification

A step has an `id`, a `title`, an `os` (`windows`, `linux`, `any`), an `optional` flag and a
`detail` in prose. It may carry `commands` (one command per entry, `powershell`/`cmd`/`bash`) and
`verify` checks:

```yaml
steps:
  - id: patch-3374
    title: Apply OldUnreal patch 3374
    os: windows
    optional: false
    detail: |
      Extract the archive and merge Help/, Sounds/ and System/ into {{target}}.
    commands:
      - shell: powershell
        run: 'Expand-Archive -Path "{{downloads}}/OldUnreal-UT2004Patch3374-Windows.zip" -DestinationPath "{{downloads}}/UT2004Patch3374" -Force'
    verify:
      - type: file_exists
        path: '{{target}}/System/D3D11Drv.dll'
```

`{{target}}` is the install folder, `{{downloads}}` the staging folder the installer filled from the
LAN file host. Verification types are `file_exists`, `dir_exists` and `command`. A plan that ends
without a verification is a guess.

## What the installer does with it

1. Resolve the recipe for the user's platform.
2. Match every requirement against the LAN file host (and the user's existing install).
3. Produce a **plan**: required downloads, optional choices, target paths, estimated bytes.
4. Ask for the optional choices — avatars are one multi-select, not a chain of yes/no questions.
5. Download through signed LAN URLs, verify hashes/sizes, run the steps in order.
6. Run the verify checks and report what failed, if anything.
7. Write nothing outside the target and downloads folders.

Planning is pure: no bytes move until the user confirms. A stale plan (recipe changed after it was
built) is rejected and rebuilt.

| Dark | Light |
|---|---|
| ![Agent install dry run printing required files, ordered steps, verify checks and avatar choices, dark](/screens/agent-install-terminal-dark.png) | ![Agent install dry run printing required files, ordered steps, verify checks and avatar choices, light](/screens/agent-install-terminal-light.png) |
| `AGENT · PLAN · STEPS · VERIFY · DRY RUN` | `AGENT · LIGHT` |

## Portable recipes and the launcher

The native launcher is a second consumer next to the agent. It downloads archives, unpacks them,
checks files and starts a launch target — and it **never runs the step commands**. A recipe is
installable in the launcher when its shape fits that: it is **portable**.

```yaml
metadata:
  portable: true
  multiplayer: true          # false for a single-player-only game
```

A portable recipe has all of these:

- `metadata.portable: true` — the author's promise that unpacking the required base archive is the
  whole install;
- one required `base` requirement: the ready-to-run archive;
- a `file_exists` or `dir_exists` check in a non-optional step;
- at least one launch target in `launch_targets` (or `metadata.launch`).

The launcher lifts one wrapper folder, checks the path the step verifies and starts the matching
launch target: the Windows `.exe`, a native Linux binary, or the Windows tree through Wine.

`metadata.launcher_supported` is the orga's override for a recipe without that shape; the panel
offers it as the three-state **Launcher-Freigabe** (**Automatisch entscheiden**, **Immer anbieten**,
**Nie anbieten**). A browser recipe with `play_url` needs no install at all. The UT2004 pilot has a
dedicated launcher path; Need for Speed: Underground 2 was verified by hand with the generic engine.

A published recipe that the launcher cannot drive is still a full catalogue entry: it has its page,
its servers and its tournaments, and the launcher card links to the game page instead of offering an
install.

## The automatic path next to the hand-written one

A participant who has a ready-to-run game folder does not edit `resources/recipes/` by hand:

1. They pack the folder as `.zip` or `.tar.bz2`, upload it to the LAN file host and describe it on
   `/games/share`. That creates a draft portable recipe with the archive pinned as its required
   `base` requirement.
2. The orga releases it in `/admin` → **Spielrezepte** with **Dateien freigeben und veröffentlichen**
   (approves the pinned archive and publishes in one action). Publishing a contributed recipe sets
   **In der Spieleliste zeigen**, so the game joins the lineup the players see.
3. The catalogue entry follows as a pull request against this directory — opened on the publish by
   default (`RECIPES_PR_TRIGGER=publish`), right after the share when the operator opted into
   `contribute`, or by `lanomat:recipes:propose`. The exported text must pass the repository's
   validator rules (schema, `SKILL.md` 60–150 lines, 3–6 draft steps) and the public-safety check
   before it is sent. The files stay on the file host; only the recipe text travels. The
   **Katalog (Pull Request)** section in the panel and the **Katalog** column on `/games/share` show
   where it stands.

Read [Share a game](/docs/guides/share-a-game) for the participant side and
[Contributing](/docs/recipes/contributing) for the repository side.

## Validation and contributing

Every recipe is checked by `resources/recipes/schema/validate.mjs`. With no argument it checks the
bundled catalogue; a directory argument checks another recipes root, for example a set exported
before it becomes a pull request.

```bash
cd resources/recipes/schema
npm install
node validate.mjs                 # the bundled catalogue
node validate.mjs /path/to/recipes  # an exported set
```

The validator checks the JSON Schema (strict: unknown keys fail), that the directory name equals the
slug, unique slugs/skill names/aliases, the `SKILL.md` contract (frontmatter, name and description,
60–150 lines), no `&&` chains, OS-matching shells, globs without path separators, the draft rules
(3–6 steps and the "needs verification on the LAN" sentence), and that `_catalog.md` lists every
recipe directory exactly once in the table for its status. Exit code 0 means every recipe is valid;
warnings never fail the run.

The same check runs in `.github/workflows/recipes.yml` on every pull request and every push to
`main` that touches `resources/recipes/**`.

The admin recipe list mirrors the catalogue: one row per recipe with its slug, version, status and
last update, so a draft and a published recipe stay distinguishable before anyone opens the YAML.

| Dark | Light |
|---|---|
| ![Admin recipe list showing slug, version, draft or published status and file count per recipe, dark](/screens/admin-recipes-dark.png) | ![Admin recipe list showing slug, version, draft or published status and file count per recipe, light](/screens/admin-recipes-light.png) |
| `ADMIN · RECIPES · SLUG · STATUS · UPDATED` | `ADMIN · LIGHT` |

The bundled catalogue's published entries are UT2004, Need for Speed: Underground 2 (the portable
tree and the browser variant) and Ridge Racer Type 4. Everything else is a draft — from F-Zero X VR
and the Super Mario and Zelda titles to **CS 1.6**. A draft is not listed on `/games` until it is
published, and a game page surfaces its tournament and servers once they exist. See
[Contributing](/docs/recipes/contributing) for the workflow.

<div class="callout-live">
  <strong>No game files in the repo.</strong> A recipe never ships a disc, a BIOS or a ROM. Every
  byte is user-supplied and matched from the LAN file host.
</div>
