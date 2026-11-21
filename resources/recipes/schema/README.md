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
6. every `SKILL.md` is 60–150 lines;
7. drafts have 3–6 steps and say "needs verification on the LAN";
8. a draft that carries a `sha256` gets a warning (confirm it or leave it empty);
9. `os: windows` steps use only `powershell`/`cmd` and `os: linux` steps only
   `bash` (mixed `os: any` steps are allowed and filtered per shell);
10. `match` globs contain no `/` or `\` (they match base names, case-insensitively).

The validator is the executable contract test: `npm test` (or `node
validate.mjs`) must stay green before a recipe PR. The glob and command
semantics it enforces are documented in [`../README.md`](../README.md#matching-and-execution-contract).

### Backend note

When the installer implements the contract:

- scan the share recursively, then match each entry's **base name** with a
  case-folded `fnmatch` (`FNM_CASEFOLD` if available, otherwise lowercase both
  sides); do not match the path and do not let `*` cross a separator;
- treat `match: []` as user-supplied and skip the share search;
- filter steps by the host OS and, inside a step, commands by `shell`
  (`powershell`/`cmd` on Windows, `bash` on Linux) before running them in order;
- resolve `{{target}}`, `{{downloads}}` and `{{os}}` before `verify`.

Regression coverage for rules 9–10 exists only in the validator today; the
backend tests should cover a case-folded folder dump (`CUSA00207`) and an
`os: any` step with both shells.

Node 20+ is enough. `node_modules/` is git-ignored; commit `package-lock.json`.

## Shape

| Key           | Required | Notes                                                                            |
| ------------- | -------- | -------------------------------------------------------------------------------- |
| `schema`      | yes      | Always `lanomat.recipe/v1`.                                                      |
| `slug`        | yes      | `^[a-z0-9]+(-[a-z0-9]+)*$`, equals the directory name.                           |
| `title`       | yes      | Display name; quote it if it contains `: `.                                      |
| `aliases`     | yes      | Chat phrases that resolve to this recipe; unique.                                |
| `version`     | yes      | String. Patch, port or release the recipe targets.                               |
| `status`      | yes      | `draft`, `published` or `archived`.                                              |
| `platforms`   | yes      | Non-empty subset of `windows`, `linux`, `macos`.                                 |
| `summary`     | yes      | One sentence, ≤ 200 chars.                                                       |
| `description` | yes      | Multi-line markdown: what it is, why LAN, what the files are.                    |
| `homepage`    | no       | URI (official project/emulator page).                                            |
| `license`     | yes      | Who owns the game; what the user supplies; mod license.                          |
| `cover`       | no       | Docs image name; may be empty.                                                   |
| `skill`       | yes      | `name` (slug rules, ≤ 64) and `description` (≤ 1024).                            |
| `requires`    | yes      | File groups; see below. May be empty for pure tools.                             |
| `steps`       | yes      | 1–20 ordered steps.                                                              |
| `metadata`    | yes      | `genre`, `players`, `lan_recommended`, `tags`; optional `players_max`, `launch`. |

### Metadata

`players` stays the human string ("2-32", "1-4 (split-screen)"). `players_max`
is the numeric upper bound the games page filters and sorts on (1 for a
single-player game). `launch` is how a launcher starts the game after the
install:

```yaml
metadata:
    players_max: 32
    launch:
        kind: steam
        target: 'steam://rungameid/10'
        notes: 'Server join: steam://connect/<host>:27015'
```

`kind` is `steam` (Steam URL), `uri` (another registered protocol) or `exe`
(executable inside `{{target}}`, optional `args`). Omit `launch` when there is
no single sensible entry point, for example an installer whose executable name
still needs verification.

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
