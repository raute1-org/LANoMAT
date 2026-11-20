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

## Adding a recipe

1. Create `resources/recipes/<slug>/` with a slug matching
   `^[a-z0-9]+(-[a-z0-9]+)*$`, unique across the catalog.
2. Write `recipe.yaml` against `schema/recipe.schema.json`. Keep `status:
draft` until someone has run it on the LAN hardware.
3. Write `SKILL.md` (60–150 lines) with `name` equal to `skill.name` and a
   `description` that says what the skill does and when to use it.
4. Add the row to `_catalog.md`.
5. Run `node resources/recipes/schema/validate.mjs` and fix every error.

Slugs, skill names and aliases must be unique. Aliases are how the chat command
finds the recipe ("ut2k4", "ut 2k4", "unreal tournament" all point at
`unreal-tournament-2004`).

## Template variables and commands

The installer resolves `{{target}}` (install dir), `{{downloads}}` (download
dir) and `{{os}}`. Commands are one per entry, with no `&&` chains; use
`powershell` on Windows, `bash` on Linux, `cmd` only when a tool requires it.
Keep commands idempotent and safe to re-run.

## Launching

A recipe says how the game starts and where it is played. Two forms are
interchangeable for a client, the multi-target one is preferred when a game has
more than one way to start:

```yaml
metadata:
    launch: 'C:\Games\Unreal Tournament 2004\System\UT2004.exe' # one flat command …
    # or, for several options (flat, VR):
    launch_targets:
        - id: flat
          label: Flat on a monitor
          os: windows
          command: '{{target}}/System/UT2004.exe'
        - id: vr
          label: VR in a SteamVR headset
          os: windows
          command: '{{target}}/System/Play-VR.bat'
    play_url: http://{{host}}:8080/play?game=r4 # {{host}} is replaced by the request host
```

- `launch` accepts a string or `{kind: steam|uri|exe, target, args?}`; `steam`
  takes the app id, `exe` a path, `uri` a full URI.
- `launch_targets` wins over `launch`; the first entry is the default and each
  `id` is what `lanomat-agent launch <slug> --variant <id>` asks for.
- `launch_vr` is the legacy shortcut for a `vr` target.
- `play_url` must use `{{host}}`, never `localhost`: the app substitutes the host
  that serves the game. `live_url` names a live deployment, if one exists.

## Licensing

Recipes ship **data only**. Game files, discs, BIOS dumps, ROMs and firmware are
user-supplied. Emulator and mod licenses are named in each recipe; no recipe
redistributes game data or a BIOS.
