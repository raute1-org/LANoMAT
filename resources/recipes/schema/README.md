# Recipe schema (lanomat.recipe/v1)

`recipe.schema.json` is a JSON Schema 2020-12 description of `recipe.yaml`.
`validate.mjs` checks the whole catalog, not just one file.

## Validate

```bash
cd resources/recipes/schema
npm install          # once: ajv, ajv-formats, yaml
node validate.mjs    # or: npm run validate
```

The validator exits non-zero and prints every error. It checks:

1. `recipe.yaml` parses and matches the schema (strict; unknown keys fail);
2. the directory name equals `slug`;
3. slugs, `skill.name` and aliases are unique across the catalog
   (aliases case-insensitively);
4. `SKILL.md` exists, has YAML frontmatter, and its `name`/`description` agree
   with the recipe (description ≤ 1024 chars);
5. no step command or launch target command contains an `&&` chain, and launch
   target ids are unique within a recipe;
6. a published recipe's `play_url` does not use `localhost` (warning only: it
   would only work on the host itself);
7. every `SKILL.md` is 60–150 lines.

Node 20+ is enough. `node_modules/` is git-ignored; commit `package-lock.json`.

## Shape

| Key              | Required | Notes                                                                                                                |
| ---------------- | -------- | -------------------------------------------------------------------------------------------------------------------- |
| `schema`         | yes      | Always `lanomat.recipe/v1`.                                                                                          |
| `slug`           | yes      | `^[a-z0-9]+(-[a-z0-9]+)*$`, equals the directory name.                                                               |
| `title`          | yes      | Display name; quote it if it contains `: `.                                                                          |
| `aliases`        | yes      | Chat phrases that resolve to this recipe; unique.                                                                    |
| `version`        | yes      | String. Patch, port or release the recipe targets.                                                                   |
| `status`         | yes      | `draft`, `published` or `archived`.                                                                                  |
| `platforms`      | yes      | Non-empty subset of `windows`, `linux`, `macos`.                                                                     |
| `summary`        | yes      | One sentence, ≤ 200 chars.                                                                                           |
| `description`    | yes      | Multi-line markdown: what it is, why LAN, what the files are.                                                        |
| `homepage`       | no       | URI (official project/emulator page).                                                                                |
| `license`        | yes      | Who owns the game; what the user supplies; mod license.                                                              |
| `cover`          | no       | Docs image name; may be empty.                                                                                       |
| `play_url`       | no       | Page a player opens on the LAN, e.g. `http://{{host}}:8080/play?game=r4`. Use `{{host}}` for the host's LAN address. |
| `launch_targets` | no       | Ordered ways to start the game when they differ (flat vs VR, Windows vs Linux). First entry is the default.          |
| `skill`          | yes      | `name` (slug rules, ≤ 64) and `description` (≤ 1024).                                                                |
| `requires`       | yes      | File groups; see below. May be empty for pure tools.                                                                 |
| `steps`          | yes      | 1–20 ordered steps.                                                                                                  |
| `metadata`       | yes      | `genre`, `players`, `lan_recommended`, `tags`; optional `live_url` for a live deployment.                            |

### Requirements

`role` is one of `base`, `iso`, `patch`, `mod`, `tool`, `avatar`, `content`,
`config`. Each entry needs `label`, `match` (glob array) and `required`.
`size_bytes`, `sha256`, `url`, `notes` and `tags` are optional. Use `sha256: ""`
and `url: ""` when unknown; never invent them.

### Steps

Each step needs `id`, `title`, `os` (`windows`/`linux`/`any`), `optional` and
`detail` (markdown). `commands` are `{shell, run}` with `powershell`, `cmd` or
`bash`; one command per entry, no `&&`. `verify` entries are `file_exists` or
`dir_exists` (need `path`) or `command` (needs `command`), plus an optional
`os` (`windows`/`linux`/`any`) for checks that only apply on one platform.

The installer runs only the commands whose `shell` matches the machine:
`powershell` on Windows, `bash` on Linux, `cmd` only when a tool needs it. An
`os: any` step may therefore carry both a `powershell` and a `bash` set; the
agent (or installer) filters by shell, not by reading comments.

An optional step **must** set `optional: true`, and a required install must not
depend on it.

### Requirements and globs

`match` globs are matched literally, not case-folded. On Linux a pattern is
case-sensitive, so list the spellings a file host may use (`*.vrm` and
`*.VRM`, `r4.chd` and `R4.chd`, `ut2004*.iso` and `UT2004*.iso`). Prefer narrow
globs over a broad `*.iso`, and say in `notes` which title and disc a pattern
belongs to. A `required` entry blocks the install when nothing matches; a
folder-based install (for example an existing GOG/Steam copy) cannot be
resolved by a glob, so document it as a manual `{{target}}` instead.

### Play URL and launch targets

`play_url` is the page a player opens on the LAN. It is not a download and not
a launcher: the browser cabinet, the server status page or a hosted game page.
Use `{{host}}` for the LAN address of the machine that hosts the game
(`http://{{host}}:8080/play?game=r4`); `localhost` only resolves on that
machine. The recipe still works when the address changes, because the
installer substitutes the current LAN address. Document any port switch and
how to change it.

`launch_targets` lists the ways to start an installed game, in preference
order:

```yaml
launch_targets:
    - id: flat-windows
      label: 'Flat on a monitor (Windows)'
      os: windows
      shell: powershell
      command: 'Start-Process -FilePath "{{target}}/System/UT2004.exe"'
      notes: 'Starting this way is always the flat game.'
```

Required keys are `id` (unique within the recipe), `label`, `os` and
`command`; `shell` and `notes` are optional. Use it only when there is a real
choice (flat vs VR, per-OS binaries); one obvious way to start the game belongs
in `steps`.

### Template variables

`{{target}}` install directory, `{{downloads}}` download directory, `{{os}}`
detected platform and `{{host}}` the LAN address of the machine that hosts the
game. The installer resolves them; the schema only stores the strings.

## Design rules

- Prefer first-party/official project URLs. Do not deep-link a moving asset
  unless the release is pinned by name.
- `match` globs must not accidentally include a sequel: after the first game,
  say so in `notes` (for example Crash 1 vs Crash 2/3).
- Drafts may have `sha256: ""` and `url: ""`; `published` recipes should have
  been run end-to-end.
- Keep commands safe to re-run: create folders with `-Force`/`-p`, copy with
  overwrite, never delete outside `{{target}}`.
