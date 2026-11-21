# LANoMAT game recipes

Game recipes are the catalog that makes "install ut2004 for me on my PC" a real
command: each recipe names the LAN files a game needs, the ordered steps an
installer or assistant follows, and an Agent Skill that tells an assistant when
and how to use it.

Each recipe is a directory under `resources/recipes/<slug>/`:

```
resources/recipes/<slug>/
  recipe.yaml      # lanomat.recipe/v1 data (see schema/)
  SKILL.md         # Agent Skills frontmatter + hand-written instructions
```

`_catalog.md` lists every recipe. `schema/` holds the JSON Schema and the
validator.

## Statuses

| Status      | Meaning                                                                  |
| ----------- | ------------------------------------------------------------------------ |
| `published` | Files and steps checked against the real source; safe to offer to users. |
| `draft`     | Authored from the collection and official docs, not yet verified on LAN. |
| `archived`  | Kept for history; do not offer.                                          |

A draft is not a stub: it has real match patterns, official emulator URLs and
3–6 steps, and its notes say "needs verification on the LAN". Never invent a
SHA-256 or a download URL to make a draft look finished.

## How a recipe is used

1. The installer resolves `requires` against the LAN file host (`SharedFiles`)
   using each entry's `match` globs. `required: true` blocks; optional entries
   are offered as a multi-select.
2. The user picks a target folder (`{{target}}`) and the download folder
   (`{{downloads}}`). `{{os}}` is the detected platform.
3. Steps run in order for the matching `os` (`windows`, `linux`, or `any`).
   Optional steps (`optional: true`) are offered, never forced.
4. `verify` entries check the result (`file_exists`, `dir_exists`, `command`).
5. The matching `SKILL.md` tells an assistant how to ask the questions, run the
   steps and troubleshoot.

## Matching and execution contract

This is the contract the backend and the agent must implement, and the
validator enforces the parts that are visible in the YAML.

**Globs (`requires[].match`)**

- Match against the **base name** of each file or folder found on the share
  (subfolders are scanned; the path itself is not part of the matched string).
- **Case-insensitive** (simple case folding, as `fnmatch`-style matching does
  on Windows/SMB). `*Crash Bandicoot (USA).cue` matches
  `crash bandicoot (usa).cue`.
- `*` matches any run of characters, including none, but **not** a path
  separator. There is no `**`, `?` or character-class syntax; a glob is
  anchored end-to-end, so `*dust2*` is the substring form.
- Globs contain no `/` or `\`; matching a folder dump is a glob against the
  folder name, not its contents.
- `match: []` is a user-supplied requirement: the installer never matches it
  on the share and asks for the file (or an already-installed copy) instead.
- A required entry blocks until one match exists; an optional entry is a
  multi-select over all matches and may stay empty.

**Platform-specific files (`requires[].os`)**

- `os` narrows one file to `windows`, `linux` or `macos`. Omit it (or write
  `any`) when the same file serves every platform, as a DVD image does.
- When a project ships one archive per platform, they are two requirements
  that differ only in `os` — for example the OldUnreal 3374 Windows zip and its
  Linux tarball — each with its own label and globs. Never put both assets in
  one entry's `match`: the plan could not tell them apart.
- A plan for one OS leaves the other platform's entries out completely: they
  are not offered as downloads and never reported as missing. The file entries
  in the plan carry `os`, so a client can re-check.
- Labels must differ even across `os` values, because the LAN pin is keyed by
  `role|label`.

**Commands and steps**

- `os` filters whole steps: `windows` steps run only on Windows, `linux` only
  on Linux, `any` on both.
- Inside a step, commands are filtered by `shell`: a Windows host runs
  `powershell` and `cmd` entries, a Linux host runs `bash` entries. An
  `os: any` step may therefore carry both sets; each host executes only its
  own shell's commands, in file order.
- Commands are one per entry and contain no `&&`; a failing command stops the
  step. `verify` runs after the commands and every entry must pass.
- `verify` paths are relative to `{{target}}`; template variables are resolved
  before comparison.

## Avatars are requirements, not a separate system

Avatar packs are ordinary `role: avatar` requirements. There is no avatar
registry and no upload path of their own:

```yaml
- role: avatar
  label: 'VRM avatars (multi-select from the LAN file host)'
  match: ['*.vrm']
  required: false
  tags: [avatar, vrm]
```

- The installer shows **every matching LAN file** as a multi-select, with the
  LAN copy preferred over any external URL.
- A recipe can add named examples (`VRM avatar · Ayla`) with narrower globs.
  Leave `url` empty: the file is matched locally, not downloaded.
- The user may pick zero avatars; the base install must never depend on one.
- Conversion to a game's character format is a separate, optional step (for
  UT2004 that is `vrm2ut2004`, built on the LAN server).

## How a shared game gets here

A participant who already has a ready-to-run game archive does not edit this
directory by hand:

1. On the LAN, the participant uploads the archive to the file host and
   describes the game on `/games/share` (title, platforms, the executable inside
   the unpacked folder, version). The file is pinned to the recipe; the game
   files stay on the file host and never enter git.
2. LANoMAT writes `recipe.yaml`, `SKILL.md` and the `_catalog.md` row, and opens
   a pull request on branch `recipes/<slug>`. One branch and one pull request
   per recipe: a new proposal moves the branch instead of opening a second one.
3. `.github/workflows/recipes.yml` runs the validator on every pull request that
   touches these files: schema, SKILL.md contract, unique slugs/aliases, and the
   catalog row.
4. A maintainer reviews the diff and merges. The next `lanomat:recipes:sync`
   makes the game appear on `/games` and in the launcher.

Machine-written `recipe.yaml` and `SKILL.md` files are exempt from Prettier
(`.prettierignore`); the schema validator is their formatting gate.

A shared game carries `metadata.portable: true`. That promises the required
`base` archive is a ready-to-run game tree: unpacking it is the whole install.
The LANoMAT launcher installs such a recipe by itself — it unpacks the archive,
checks the files a step verifies and starts a launch target, and never runs the
step commands. That is also why a portable recipe has one required `base`
archive, real `verify` checks and a launch target.

Recipes added by hand keep the path described next.

## Adding a recipe

1. Create `resources/recipes/<slug>/` with a slug matching
   `^[a-z0-9]+(-[a-z0-9]+)*$`, unique across the catalog.
2. Write `recipe.yaml` against `schema/recipe.schema.json`. Keep the status
   `draft` until someone has run it on the LAN hardware.
3. Write `SKILL.md` (60–150 lines) with `name` equal to `skill.name` and a
   `description` that says what the skill does and when to use it.
4. Add the row to `_catalog.md`.
5. Run `node resources/recipes/schema/validate.mjs` (or `npm test` in
   `schema/`) and fix every error.

Slugs, skill names and aliases must be unique. Aliases are how the chat command
finds the recipe ("ut2k4", "ut 2k4", "unreal tournament" all point at
`unreal-tournament-2004`).

## Template variables and commands

The installer resolves `{{target}}` (install dir), `{{downloads}}` (download
dir) and `{{os}}`. Commands are one per entry, with no `&&` chains; use
`powershell` on Windows, `bash` on Linux, `cmd` only when a tool requires it.
Keep commands idempotent and safe to re-run.

## Licensing

Recipes ship **data only**. Game files, discs, BIOS dumps, ROMs and firmware are
user-supplied. Emulator and mod licenses are named in each recipe; no recipe
redistributes game data or a BIOS.
