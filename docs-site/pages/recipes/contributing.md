---
title: Contributing
description: Add a recipe, contribute an ISO, and get both past the schema and the skill review.
sidebar_icon: git-pull-request
---

A recipe is a small, reviewable artefact. You do not need PHP to add one: two files, a schema check
and a pull request. The same pull request can add the game's disc image to the LAN file host, but
never to git.

## 1. Copy the shape

```text
resources/recipes/<slug>/
├── recipe.yaml
└── SKILL.md
```

Use `unreal-tournament-2004` as the reference. The directory name must equal `slug`, the slug must
be lowercase kebab-case, and aliases must be unique across the catalogue.

An existing database recipe can start the same layout instead of a blank page:
`lanomat:recipes:export <path> --format=repository` writes `<slug>/recipe.yaml` and `<slug>/SKILL.md`
for every published recipe (drafts only with `--all`).

## 2. Write `recipe.yaml`

Start from the schema, not from prose:

```bash
cd resources/recipes/schema
npm install
node validate.mjs                 # the bundled catalogue
node validate.mjs /path/to/recipes  # an exported set, before it becomes a pull request
```

The validator checks, in order:

1. `recipe.yaml` parses and matches `recipe.schema.json` (JSON Schema 2020-12, strict).
2. The directory name equals the slug.
3. Slugs, skill names and aliases are unique across the catalogue.
4. `SKILL.md` exists, has Agent Skills frontmatter, and its `name`/`description` agree with the YAML.
5. Commands contain no `&&` chains — one command per entry.
6. `SKILL.md` is between 60 and 150 lines.
7. Drafts have 3–6 steps and say that they still need verification on the LAN.
8. OS-specific steps use only that OS's shells; `match` globs carry no path separators.
9. `_catalog.md` lists every recipe directory exactly once, in the table for its status.

Hard requirements for a merge:

- `schema: lanomat.recipe/v1`, a `status` (`draft` is fine while you iterate), at least one
  `platforms` entry.
- A `skill` block whose `description` says when to use it, including the words a user would say.
- `requires[]` with `role`, `label`, `match`, `required`; **the base game is required**, extras are
  optional. `manual: true` marks a file the player supplies by hand.
- At least one step with a `detail`; every non-optional step should end in a `verify` check.
- `metadata` with genre, players, `lan_recommended` and tags; `multiplayer: false` for a
  single-player-only game.
- A row in `_catalog.md` under the recipe's status.

If the recipe should be installable by the native launcher, make it **portable**: `metadata.portable:
true`, one required `base` archive that only needs unpacking, a `file_exists` or `dir_exists` check
in a non-optional step, and at least one launch target in `launch_targets`. The launcher unpacks the
archive, checks the file and starts the target; it never runs the step commands.
`metadata.launcher_supported` is the orga override; the panel shows it as the three-state
**Launcher-Freigabe** (**Automatisch entscheiden**, **Immer anbieten**, **Nie anbieten**). A browser
recipe needs `play_url` instead.

The admin form edits the same fields without YAML: role and label per requirement, the file-name
patterns as removable chips, and a `Pflicht` (required) toggle for the base entry.

| Dark | Light |
|---|---|
| ![Admin recipe edit form with SKILL.md text, requirement role, match patterns and required toggle, dark](/screens/admin-recipe-edit-dark.png) | ![Admin recipe edit form with SKILL.md text, requirement role, match patterns and required toggle, light](/screens/admin-recipe-edit-light.png) |
| `ADMIN · RECIPE EDIT · ROLE · MATCH · REQUIRED` | `ADMIN · LIGHT` |

## 3. Write `SKILL.md`

The skill is for an agent, not for a search engine. Keep it 60–150 lines and make it operational:

- **When to use** — the exact phrases and the near-misses ("not UT99").
- **What you need** — content, tools, disk, OS, headset.
- **Steps** — the same order as the YAML, with the pitfalls inline.
- **Asking about optional content** — avatars are a multi-select; never turn them into a chain of
  yes/no questions.
- **Verification** — what a successful install looks like.
- **Troubleshooting** — the errors users actually hit, with the fix.
- **License** — who owns the game, what the user supplies.

Do not paste the YAML into the skill. The skill explains intent and judgement; the YAML is the
machine-readable plan.

## 4. Contribute the content

Game files never enter git. Put the ISO, patch or avatar on the LAN file host, then let the recipe
match it by name:

| Item | Where | Naming |
|---|---|---|
| Disc image | file host `isos/` | `ut2004-dvd1.iso`, `r4.chd` |
| Patch | file host `patches/` | keep the upstream name (`OldUnreal-UT2004Patch3374-Windows.zip`) |
| Mod | file host `mods/` | keep the release name (`UT2004VR-1.2.zip`) |
| Avatar | file host `avatars/` | `ayla.vrm`, `brutus.vrm` |
| Recipe | git | `resources/recipes/<slug>/` |

Add `size_bytes` when you know it, and `sha256` if the file is stable. A `match` glob should be
specific enough to avoid grabbing the wrong disc (`ut2004*.iso`, not `*.iso`). If the content is
optional, say so in `notes` and keep `required: false`.

| Dark | Light |
|---|---|
| ![Admin shared files list with disc image, patch and avatar uploads and their release status, dark](/screens/admin-shared-files-dark.png) | ![Admin shared files list with disc image, patch and avatar uploads and their release status, light](/screens/admin-shared-files-light.png) |
| `FILES · NAME · SIZE · STATUS` | `FILES · LIGHT` |

## The automatic path (a shared game)

A participant who shares a game on `/games/share` produces the same three artefacts without touching
the repository: `recipe.yaml`, `SKILL.md` and the `_catalog.md` row. When the orga has enabled
recipe pull requests, LANoMAT proposes them as one pull request on a branch named after the recipe
(`recipes/<slug>`); a new proposal moves that branch instead of opening a second pull request. Before
anything is sent, the exported text passes the repository's own gate: the recipe schema, the
`SKILL.md` contract (front matter, 60–150 lines), 3–6 steps for a draft plus the LAN-verification
sentence, one command per entry, OS-matching shells and base-name globs. Then a public-safety check
refuses a private address, an e-mail address or a token in the text. A refused recipe is **Nicht
gesendet** with the reasons instead of a red pull request. The game files never travel with it — the
recipe pins the archive on the LAN file host by name.

A participant may hold `RECIPES_MAX_DRAFTS_PER_USER` drafts waiting for review at the same time (20
by default; the orga is exempt). The share page, the MCP tool and `POST /api/v1/recipes` all count
against the same budget.

The proposal is opened at one of two moments, chosen by the operator: on the orga's publish by
default (`RECIPES_PR_TRIGGER=publish`, so the repository only sees reviewed text), or right after
the share when the operator opts into `contribute`. Publishing a contributed or panel recipe always
opens or updates it. Automatic proposals are capped per contributor per hour
(`RECIPES_PR_PER_USER_HOURLY`, 6) and by this installation's open pull requests
(`RECIPES_PR_MAX_OPEN`, 30); the orga's manual path is not limited. In the panel it is **Pull
Request öffnen / aktualisieren** on the recipe's edit page (offered only for a recipe the pipeline
would take); the **Katalog (Pull Request)** section shows the state (**Pull Request offen**, **Im
Katalog**, **Pull Request geschlossen**, **Nicht gesendet**, **Fehlgeschlagen**), the link, the
refusal reason and the last sync. A recipe that already has an open pull request keeps it when a new
attempt is refused. From a shell, `lanomat:recipes:propose <slug>` runs one proposal in the
foreground and `lanomat:recipes:pr-status` sweeps the open ones hourly. Before the LAN, Preflight's
`recipe_repository` check says whether the token may read and push.

The same validator gates that pull request. A maintainer reviews the diff and merges; the next sync
from the git source brings the recipe into the catalogue, and the lineup flag then decides whether
players see it on `/games` and in the launcher. The manual path above stays for recipes written by
hand.

Read [Share a game](/docs/guides/share-a-game) for the participant side.

## 5. Review checklist

- [ ] `node validate.mjs` is green, including the `_catalog.md` row.
- [ ] The base install works with only the required entries.
- [ ] A recipe meant for the launcher is portable (one required `base` archive, a file check, a
      launch target), or the orga sets **Immer anbieten** in the panel's **Launcher-Freigabe**.
- [ ] Optional content is genuinely optional and does not block verification.
- [ ] `SKILL.md` names the aliases, the optional questions and the failure modes.
- [ ] Commands are one per entry, quoted, and use `{{target}}`/`{{downloads}}`.
- [ ] No game file, BIOS, ROM or key is in the repository.
- [ ] The recipe page renders (the docs build includes it).
- [ ] The pull request says which platforms you actually tested.

A merged recipe ends up on the game page: Install and Launch, the join string, the server list and
the optional avatar picks all come from the YAML you just wrote.

| Dark | Light |
|---|---|
| ![UT2004 game page with Install, Launch, server join and avatar selection from the recipe, dark](/screens/game-ut2004-dark.png) | ![UT2004 game page with Install, Launch, server join and avatar selection from the recipe, light](/screens/game-ut2004-light.png) |
| `GAME · UT2004 · INSTALL · SERVER · AVATARS` | `GAME · LIGHT` |

<div class="callout-live">
  <strong>Test on a clean machine.</strong> A recipe that only works on the author's PC is a note,
  not a recipe. At minimum, test the base path on a fresh Windows install and, if you claim Linux,
  in a fresh prefix.
</div>

The agent's dry run is the cheapest way to prove that: it lists the files your `match` globs actually
resolve, the steps in order, and the verify checks that will run — without moving any bytes.

| Dark | Light |
|---|---|
| ![Agent install dry run listing resolved files, ordered steps and verify checks, dark](/screens/agent-install-terminal-dark.png) | ![Agent install dry run listing resolved files, ordered steps and verify checks, light](/screens/agent-install-terminal-light.png) |
| `AGENT · PLAN · FILES · STEPS · VERIFY` | `AGENT · LIGHT` |

