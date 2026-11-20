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
| `metadata`    | yes      | `genre`, `players`, `lan_recommended`, `tags`; optional `launch`, `launch_vr`, `play_url`, `live_url`, `multiplayer`, `players_max`, `install_size`, `note`. |

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

## Design rules

- Prefer first-party/official project URLs. Do not deep-link a moving asset
  unless the release is pinned by name.
- `match` globs must not accidentally include a sequel: after the first game,
  say so in `notes` (for example Crash 1 vs Crash 2/3).
- Drafts may have `sha256: ""` and `url: ""`; `published` recipes should have
  been run end-to-end.
- Keep commands safe to re-run: create folders with `-Force`/`-p`, copy with
  overwrite, never delete outside `{{target}}`.
