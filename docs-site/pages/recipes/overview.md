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
`/games`, which lists every published recipe, marks what is already on the LAN, and connects Install,
Launch, servers and tournaments in one page.

<div class="live">LIVE 17:42 · recipe scan · 9 recipes · schema valid</div>

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
  tags: [fps, vr, classic]
```

`version` is the recipe's own version, not the game's patch number — though for UT2004 it happens
to name the required patch. `slug` must equal the directory name. Aliases are unique across the
catalogue.

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

## Validation and contributing

Every recipe is checked by `resources/recipes/schema/validate.mjs`: JSON Schema validation, directory
name equals slug, unique slugs/skill names/aliases, a `SKILL.md` with Agent Skills frontmatter that
agrees with the YAML, no `&&` chains in commands, and a SKILL.md length of 60–150 lines.

```bash
cd resources/recipes/schema
npm install
npm run validate
```

The catalogue currently contains UT2004, Ridge Racer Type 4, F-Zero X VR, Pokémon Snap, Super Mario
Galaxy, Super Mario Sunshine, and the three Zelda recipes (Ocarina of Time 3D, Majora's Mask 3D,
Wind Waker). **CS 1.6** is in the catalogue as a draft: it is not listed on `/games` until it is
published, and its game page surfaces the CS 1.6 tournament and servers once they exist. See
[Contributing](/docs/recipes/contributing) for the workflow.

<div class="callout-live">
  <strong>No game files in the repo.</strong> A recipe never ships a disc, a BIOS or a ROM. Every
  byte is user-supplied and matched from the LAN file host.
</div>
