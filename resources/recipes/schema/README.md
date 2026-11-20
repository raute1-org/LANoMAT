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
5. no command contains an `&&` chain;
6. every `SKILL.md` is 60–150 lines.

Node 20+ is enough. `node_modules/` is git-ignored; commit `package-lock.json`.

## Shape

| Key           | Required | Notes                                                         |
| ------------- | -------- | ------------------------------------------------------------- |
| `schema`      | yes      | Always `lanomat.recipe/v1`.                                   |
| `slug`        | yes      | `^[a-z0-9]+(-[a-z0-9]+)*$`, equals the directory name.        |
| `title`       | yes      | Display name; quote it if it contains `: `.                   |
| `aliases`     | yes      | Chat phrases that resolve to this recipe; unique.             |
| `version`     | yes      | String. Patch, port or release the recipe targets.            |
| `status`      | yes      | `draft`, `published` or `archived`.                           |
| `platforms`   | yes      | Non-empty subset of `windows`, `linux`, `macos`.              |
| `summary`     | yes      | One sentence, ≤ 200 chars.                                    |
| `description` | yes      | Multi-line markdown: what it is, why LAN, what the files are. |
| `homepage`    | no       | URI (official project/emulator page).                         |
| `license`     | yes      | Who owns the game; what the user supplies; mod license.       |
| `cover`       | no       | Docs image name; may be empty.                                |
| `skill`       | yes      | `name` (slug rules, ≤ 64) and `description` (≤ 1024).         |
| `requires`    | yes      | File groups; see below. May be empty for pure tools.          |
| `steps`       | yes      | 1–20 ordered steps.                                           |
| `metadata`    | yes      | `genre`, `players`, `lan_recommended`, `tags`.                |

### Requirements

`role` is one of `base`, `iso`, `patch`, `mod`, `tool`, `avatar`, `content`,
`config`. Each entry needs `label`, `match` (glob array) and `required`.
`size_bytes`, `sha256`, `url`, `notes` and `tags` are optional. Use `sha256: ""`
and `url: ""` when unknown; never invent them.

### Steps

Each step needs `id`, `title`, `os` (`windows`/`linux`/`any`), `optional` and
`detail` (markdown). `commands` are `{shell, run}` with `powershell`, `cmd` or
`bash`; one command per entry, no `&&`. `verify` entries are `file_exists` or
`dir_exists` (need `path`) or `command` (needs `command`).

An optional step **must** set `optional: true`, and a required install must not
depend on it.

### Template variables

`{{target}}` install directory, `{{downloads}}` download directory, `{{os}}`
detected platform. The installer resolves them; the schema only stores the
strings.

### Launching

`metadata` carries how the installed game starts and where it is played. The
forms are interchangeable for a client, but a recipe should pick the richest one
it can support:

| Key              | Shape                                               | Meaning                                                                                                                                                                                                                                           |
| ---------------- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `launch`         | string **or** object                                | One way to start an installed copy. A string is a flat command or executable path. The object is `{kind: steam\|uri\|exe, target, args?, shell?, notes?}`; `steam` takes the app id (`13210`), `uri` a full URI, `exe` a path plus optional args. |
| `launch_targets` | array of `{id, label, os, command, shell?, notes?}` | Several ways to start the same install, most useful first (`flat`, `vr`, …). Wins over `launch` when present; `id` is what `lanomat-agent launch --variant <id>` asks for. `os` is `windows`, `linux`, `macos` or `any`.                          |
| `launch_vr`      | string                                              | Legacy shortcut for a second VR command; equivalent to a `launch_targets` entry with `id: vr`. Do not combine both.                                                                                                                               |
| `play_url`       | string                                              | Browser game or web front end. Use `{{host}}` for the LAN address of the serving machine — the app replaces it with the request host, so never hardcode `localhost`.                                                                              |
| `live_url`       | string                                              | A live deployment of this game (for example an event's public server).                                                                                                                                                                            |

Commands and `play_url` may use `{{target}}` (install dir) and `{{avatars}}`
(avatar dir); the client substitutes them like the step variables.

```yaml
metadata:
    genre: arena-shooter
    players: 2-32
    lan_recommended: true
    tags: [fps, vr]
    play_url: http://{{host}}:8080/
    launch_targets:
        - id: flat
          label: Flat on a monitor
          os: windows
          command: '{{target}}/System/UT2004.exe'
        - id: vr
          label: VR in a SteamVR headset
          os: windows
          shell: powershell
          command: 'Start-Process "{{target}}/System/Play-VR.bat"'
```

## Design rules

- Prefer first-party/official project URLs. Do not deep-link a moving asset
  unless the release is pinned by name.
- `match` globs must not accidentally include a sequel: after the first game,
  say so in `notes` (for example Crash 1 vs Crash 2/3).
- Drafts may have `sha256: ""` and `url: ""`; `published` recipes should have
  been run end-to-end.
- Keep commands safe to re-run: create folders with `-Force`/`-p`, copy with
  overwrite, never delete outside `{{target}}`.
