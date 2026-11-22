# Recipe schema (lanomat.recipe/v1)

`recipe.schema.json` is a JSON Schema 2020-12 description of `recipe.yaml`.
`validate.mjs` checks the whole catalog, not just one file.

## Validate

```bash
cd resources/recipes/schema
npm install          # once: ajv, ajv-formats, yaml
node validate.mjs    # or: npm run validate
```

Pass a directory to check another recipes root, for example a set exported by
LANoMAT before it becomes a pull request:

```bash
node validate.mjs /path/to/recipes
npm run validate -- /path/to/recipes
```

With no argument the validator checks the catalog that ships in this repository
(the parent of `schema/`). Exit code 0 means every recipe is valid, 1 means at
least one error (one line per problem) and 2 means the argument is not a
directory. Warnings never fail the run. The last line always says how many
recipes were checked.

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
10. `match` globs contain no `/` or `\` (they match base names, case-insensitively);
11. `_catalog.md` lists every recipe directory exactly once, in the table whose
    heading matches the recipe's `status` (`## Published`, `## Draft`,
    `## Archived`), with the slug in backticks in the first column. A missing
    row, a row without a directory, a duplicate row and a row under the wrong
    status are errors naming the slug. When the checked directory has no
    `_catalog.md` (an exported set), this check is skipped with a note.

The validator is the executable contract test: `npm test` (or `node
validate.mjs`) must stay green before a recipe PR. The glob and command
semantics it enforces are documented in [`../README.md`](../README.md#matching-and-execution-contract).
The workflow `.github/workflows/recipes.yml` runs the same check on every pull
request that touches `resources/recipes/**`.

### Backend note

When the installer implements the contract:

- scan the share recursively, then match each entry's **base name** with a
  case-folded `fnmatch` (`FNM_CASEFOLD` if available, otherwise lowercase both
  sides); do not match the path and do not let `*` cross a separator;
- treat `match: []` as user-supplied and skip the share search;
- filter steps by the host OS and, inside a step, commands by `shell`
  (`powershell`/`cmd` on Windows, `bash` on Linux) before running them in order;
- resolve `{{target}}`, `{{downloads}}` and `{{os}}` before `verify`; the plan
  already carries final text for `{{server_host}}`/`{{server_port}}`, so do not
  expect those placeholders in a plan at all.

Regression coverage for rules 9–10 exists only in the validator today; the
backend tests should cover a case-folded folder dump (`CUSA00207`) and an
`os: any` step with both shells.

Node 20+ is enough. `node_modules/` is git-ignored; commit `package-lock.json`.

## Shape

| Key           | Required | Notes                                                                                                                                                                                                                           |
| ------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `schema`      | yes      | Always `lanomat.recipe/v1`.                                                                                                                                                                                                     |
| `slug`        | yes      | `^[a-z0-9]+(-[a-z0-9]+)*$`, equals the directory name.                                                                                                                                                                          |
| `title`       | yes      | Display name; quote it if it contains `: `.                                                                                                                                                                                     |
| `aliases`     | yes      | Chat phrases that resolve to this recipe; unique.                                                                                                                                                                               |
| `version`     | yes      | String. Patch, port or release the recipe targets.                                                                                                                                                                              |
| `status`      | yes      | `draft`, `published` or `archived`.                                                                                                                                                                                             |
| `platforms`   | yes      | Non-empty subset of `windows`, `linux`, `macos`.                                                                                                                                                                                |
| `summary`     | yes      | One sentence, ≤ 200 chars.                                                                                                                                                                                                      |
| `description` | yes      | Multi-line markdown: what it is, why LAN, what the files are.                                                                                                                                                                   |
| `homepage`    | no       | URI (official project/emulator page).                                                                                                                                                                                           |
| `license`     | yes      | Who owns the game; what the user supplies; mod license.                                                                                                                                                                         |
| `cover`       | no       | Docs image name; may be empty.                                                                                                                                                                                                  |
| `skill`       | yes      | `name` (slug rules, ≤ 64) and `description` (≤ 1024).                                                                                                                                                                           |
| `requires`    | yes      | File groups; see below. May be empty for pure tools.                                                                                                                                                                            |
| `steps`       | yes      | 1–20 ordered steps.                                                                                                                                                                                                             |
| `metadata`    | yes      | `genre`, `players`, `lan_recommended`, `tags`; optional `players_max`, `launch`, `store_ids` (Steam/Epic/GOG/Ubisoft ids the native client matches on), `portable`, `launcher_supported`, `live_url` and the browser recipe's `server` (`name`, `address`, `port`, `connect_url`, optional `players`). |

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

`portable: true` marks a recipe whose required base archive is a ready-to-run
game tree: unpacking it is the whole install. The launcher installs such a
recipe on its own — it unpacks the archive, checks the files a step verifies and
starts a launch target, but never runs the step commands. `launcher_supported`
is the orga override for the launcher (`true` offers a recipe that is not
portable, `false` hides it); omit it to let LANoMAT decide from the recipe.

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

`{{server_host}}` and `{{server_port}}` name this installation's server, so a
recipe never carries that address (the one exception is a browser recipe's own
public server in `metadata.server` — the game's deployment, not the LAN's).
LANoMAT fills both in when it builds a plan, from a server of the game the
viewer may see — but only when that server's game carries **exactly** the
recipe's slug (no alias, no name resemblance; the browser twin of a native game
never qualifies) and the server is `ready` or at least `not_responding`.
Ranking: a server that answers first, then a permanent one, then the lowest id.
Both are final in the plan **and in the recipe detail** (REST, MCP and the
native client fill them too, so an agent never reads a raw placeholder):
`BuildInstallPlan` substitutes them in step commands, step checks and launch
targets before the plan leaves the server, because the clients substitute their
own set (`{{target}}`, `{{downloads}}`, `{{avatars}}` and a few more) and would
pass an unknown placeholder through literally. No client resolves
`{{server_host}}`/`{{server_port}}`. `{{server_host}}` keeps letters, digits, dot and hyphen only (at
most 253 characters), `{{server_port}}` digits 1–65535; anything else — and "no
such server" — is the empty string. A step must therefore handle the case
where it did not get a host: the NFSU2 step leaves the game's `host` line as
the file carries it and says in its `detail` that the address is on the server
list (`/servers`) or with the Orga. An IPv6 literal is empty too (the contract
has no colons).

## Design rules

- Prefer first-party/official project URLs. Do not deep-link a moving asset
  unless the release is pinned by name.
- `match` globs must not accidentally include a sequel: after the first game,
  say so in `notes` (for example Crash 1 vs Crash 2/3).
- Drafts may have `sha256: ""` and `url: ""`; `published` recipes should have
  been run end-to-end.
- Keep commands safe to re-run: create folders with `-Force`/`-p`, copy with
  overwrite, never delete outside `{{target}}`.
