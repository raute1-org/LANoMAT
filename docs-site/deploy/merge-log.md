# Merge log — MCP server, agent, game recipes and docs

**Owner:** R5 (swarm merge captain) · **Worktree:** `/home/reneil1337/coding/lanomat-swarm/r5`, branch `swarm/r5`
**Task:** `docs/superpowers/plans/2026-11-21-agent-swarm.md` § 4.5–4.7, § 7 (R5)
**Status:** merge order, conflict resolutions and gates are prepared and validated on a trial
branch. Waiting for the coordinator's APPROVED list, then merged one branch at a time.

| | |
|---|---|
| Trial branch | `scratch/r5-gate` (disposable; mirror of the validated merge) |
| Validated against | `feat/mcp-server` @ `2c1d1d3`, `feat/game-recipes` @ `753a665`, `feat/agent-client` @ `fd5ab6e`, `docs/zudoku` @ `c35225d` |
| Trial merge result | `be3a88b` — tree identical to `feat/mcp-server` plus `docs-site/README.md` |
| Base of `swarm/r5` | `63e8a2b` (an ancestor of `feat/mcp-server`, i.e. the slice's own base) |

`feat/mcp-server` was still moving while this was written (`2ffc930 → c238734 → 1aeed4e → 2c1d1d3`).
Re-run § 6 after it stops; the numbers below are from `2c1d1d3`.

## 1. Branches in this slice

| Branch | Head | Ahead of `main` | Diff vs `main` | Scope |
|---|---|---|---|---|
| `feat/mcp-server` | `2c1d1d3` | 6 commits | 461 files, +45 055 / −316 | `app/Modules/Mcp`, `app/Modules/GameRecipes`, `clients/agent`, `resources/recipes`, `docs-site` **and** every shared file |
| `feat/game-recipes` | `753a665` | 7 commits | 50 files, +5 315 | `resources/recipes/**` only |
| `feat/agent-client` | `fd5ab6e` | 10 commits | 49 files, +13 514 | `clients/agent/**` + `.github/workflows/client-agent.yml` |
| `docs/zudoku` | `c35225d` | 7 commits | 246 files, +17 719 | `docs-site/**` only |

## 2. Dependency map (what is already inside what)

Evidence is git tree hashes, not vibes:

| Path | `feat/mcp-server` | Other branch | Relationship |
|---|---|---|---|
| `clients/agent` | `598203d9d422` | `feat/agent-client` `598203d9d422` | **identical** |
| `resources/recipes` | `349c826d51c6` | `feat/game-recipes` `05bb132f1064` | mcp is a superset, re-formatted |
| `docs-site` | `bdb78c40a49d` | `docs/zudoku` `71c6a32eb9f8` | mcp = zudoku − `README.md` + 2 fixes |

File-set difference (`comm -23 <other> <mcp-server>`):

- `feat/game-recipes` → **no file that mcp lacks**; mcp additionally ships `counter-strike-1-6` and the launch metadata.
- `feat/agent-client` → **no file that mcp lacks** (same workflow file blob, `a892a33`).
- `docs/zudoku` → only `docs-site/README.md`.

So `feat/mcp-server` is the vertical slice (backend + agent + recipes + docs, one squashed pair of
commits), and the three split branches carry the same content in branch history. The merge exists to
keep that history for the worker branches (`swarm/w6`–`w8` sit on `feat/agent-client`,
`swarm/w9`–`w11` on `feat/game-recipes`, `swarm/w12`–`w15` on `docs/zudoku`), not to add code.

Shared files are touched by **one** branch only:

```
.env.example  CHANGELOG.md  README.md  compose.yml  composer.json  composer.lock
app/Support/Modules/Module.php   app/Providers/AppServiceProvider.php   bootstrap/app.php
routes/web.php   routes/mcp.php   routes/ai.php   config/mcp_agent.php   config/recipes.php
tests/Architecture/core-optional-baseline.php   tests/Architecture/module-dependencies.php
tests/Feature/Security/RouteAuthorizationMatrixTest.php
```

`app/Modules/GameRecipes` adds a `requires(): [Files]` edge, so a `Files`-off profile still lists and
plans recipes; `Mcp` is routed from `bootstrap/app.php` behind `Modules::routable(Module::Mcp)`.

## 3. Merge order

1. `feat/mcp-server`
2. `feat/game-recipes`
3. `feat/agent-client`
4. `docs/zudoku`
5. then the worker branches in card order (§ 8), CI/ops last

Rationale, in the order the arguments matter:

- **Green at every step.** `npm run format:check` is `prettier --check resources/`, and
  `resources/recipes/**` is inside it. The `feat/game-recipes` copies are *not* prettier-formatted —
  measured: **32 files fail** (`npx prettier --check resources/recipes`). `feat/mcp-server` ran
  `npm run format` and is clean. If the recipes branch went first, step 1 would be a red merge and
  § 4.6 ("a red merge is reverted, not fixed forward") would revert the one branch holding the
  content.
- **Only one branch touches shared files.** Merging it first means every later merge only writes
  inside `resources/recipes`, `clients/agent`, `docs-site` — shared-file conflicts become
  structurally impossible for this slice and for the worker branches that follow.
- **No content is lost by taking the backend side of the recipe conflicts** (§ 4).
- `feat/agent-client` is fully contained: merging it is a **no-op** that keeps the graph for
  `swarm/w6`–`w8`.

**Deviation from the plan's § 4.5 order** (`recipes-content → backend → agent → docs → CI/ops`): the
plan assumed the backend branch carries only backend code. Here it carries the recipes, the agent
and the docs as well, so the recipes branch has to merge *after* the backend to keep every step
green. Recorded here so the next captain does not "fix" the order back.

## 4. Conflicts and their resolution

Trial merges were run in both orders on `63e8a2b` and on `facec5d` (`main`); the conflict set is the
same, only the step where it appears moves.

### 4.1 `feat/game-recipes` — 32 conflicts, all under `resources/recipes/`

| Group | Files | Nature of the conflict |
|---|---|---|
| `recipe.yaml` | 21 | YAML re-serialised on the mcp side (4-space, single quotes) |
| `SKILL.md` | 4 (`azeroth-survivors`, `ridge-racer-type-4`, `uevr`, `unreal-tournament-2004`) | quote/indent style only |
| catalog + README | 2 (`_catalog.md`, `README.md`) | prettier table alignment |
| recipe schema | 5 (`schema/{README.md,package.json,package-lock.json,recipe.schema.json,validate.mjs}`) | same code, 4-space formatting |

**Resolution:** keep the backend side for the whole directory.

```bash
git merge --no-ff feat/game-recipes          # 32 conflicts
git checkout --ours -- resources/recipes
git add -A resources/recipes
git commit                                   # "Merge branch 'feat/game-recipes' into swarm/r5"
```

**Why that is not content loss** (checked file by file, YAML parsed and compared):

- 19 of 21 recipes are **semantically identical** after parsing; only quoting/indentation differs.
- 2 recipes are *strictly larger* on the mcp side: `unreal-tournament-2004` and
  `ridge-racer-type-4` gained `metadata.launch`, `metadata.launch_vr`, `metadata.play_url`,
  `metadata.multiplayer`, `metadata.install_size` — the fields the agent deep link and the games
  page read.
- The 4 `SKILL.md` diffs are quote style + one indented list block.
- `_catalog.md` / `README.md` diffs are table alignment; no row is added or removed.
- `schema/*` is the same validator, reformatted; re-validated after the merge:
  `node resources/recipes/schema/validate.mjs` → `OK: 22 recipes valid (2 published, 20 draft)`.

### 4.2 `docs/zudoku` — 1–2 conflicts under `docs-site/`

| File | Resolution | Why |
|---|---|---|
| `docs-site/pages/recipes/smb.md` | **ours** (`git checkout --ours`) | mcp carries the public-safe placeholder fix `smb://<LAN-IP>` → `smb://nas.local` (`2ffc930`); the zudoku side still has the real LAN IP |
| `docs-site/zudoku.config.tsx` | **ours** (only at `feat/mcp-server` ≥ `2c1d1d3`) | ours = zudoku's config **plus** redirects `/games`, `/mcp`, `/agent`; the branch side is a strict subset |
| `docs-site/README.md` | **take theirs** (clean add) | only file the zudoku branch has that mcp does not |

```bash
git merge --no-ff docs/zudoku
git checkout --ours -- docs-site/pages/recipes/smb.md docs-site/zudoku.config.tsx
git add docs-site/pages/recipes/smb.md docs-site/zudoku.config.tsx
git commit                                   # README.md comes in with the merge
```

### 4.3 `feat/agent-client` — no conflicts

`git merge --no-ff feat/agent-client` merges with a zero-file diff (identical tree), keeping the
branch history for `swarm/w6`–`w8`.

### 4.4 Post-merge invariant

`git diff --stat feat/mcp-server HEAD` must print exactly:

```
 docs-site/README.md | 129 ++++++++++++++++++++++++++++++++++++++++++++++++++++
```

Anything else means a resolution took the wrong side.

> `git rerere` is enabled in this repo (`rerere.enabled=true`), so a repeated trial merge replays the
> resolutions above automatically. That is convenient but it hides decisions in
> `.git/rr-cache`; for the real merges either keep the cache (it replays the same "ours" choices) or
> run `git rerere forget <path>` before resolving by hand.

## 5. Checks after each merge

Run after **every** merge, in this order (order matters: the build and the Wayfinder codegen are
inputs to `types:check` and to the Inertia page tests).

```bash
# 0. target hygiene
git status --porcelain            # only *.node_modules symlinks may remain untracked

# 1. backend
docker run --rm --network host -v "$PWD:/app" -w /app --entrypoint php lanomat-app:latest \
  vendor/bin/pint --test
docker run --rm --network host -v "$PWD:/app" -w /app --entrypoint php lanomat-app:latest \
  vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker run --rm --network host --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app \
  -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=lanomat_review_r5 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=5434 -e DB_USERNAME=lanomat -e DB_PASSWORD=lanomat \
  --entrypoint php lanomat-app:latest vendor/bin/pest -d memory_limit=1G
docker run --rm -v "$PWD:/app" -w /app composer:2 validate --no-check-publish

# 2. frontend (host has Node, no PHP: /tmp/opencode/bin/php is a shim that forwards into the app image)
rm -rf docs-site/dist clients/agent/dist          # root eslint does not ignore them
PATH=/tmp/opencode/bin:$PATH npm run format:check
PATH=/tmp/opencode/bin:$PATH php artisan wayfinder:generate --skip-database --with-form
PATH=/tmp/opencode/bin:$PATH npm run build        # also generates the Vite manifest the page tests need
npm run types:check
npm run lint:check

# 3. agent
cd clients/agent && npm run lint && npm test && npm run build

# 4. recipes + docs
node resources/recipes/schema/validate.mjs
cd docs-site && npm run build && npm run check:links

# 5. public safety
bash scripts/ops/check-public-safe.sh
```

Environment facts these commands encode (learned the hard way, do not "optimise" them away):

- **No PHP on the host.** Everything PHP runs in `lanomat-app:latest` with the worktree mounted at
  `/app`; the `composer:2` image has no `pdo_pgsql`, so Pest there is useless. The image ships no
  `composer` binary (removed in the prod stage), hence the three vendor binaries instead of
  `composer check`.
- **One database per reviewer:** `lanomat_review_r5` (created once with
  `docker exec lanomat-postgres-1 createdb -U lanomat lanomat_review_r5`). Never `lanomat_test` —
  `RefreshDatabase` deadlocks against another run.
- **Run PHP containers as `--user $(id -u):$(id -g)`.** As root they leave `storage/framework/testing`
  and `bootstrap/cache` root-owned, and the next `npm run lint:check` dies with
  `EACCES: permission denied, scandir storage/framework/testing/disks`.
- **`npm run types:check` needs `php artisan wayfinder:generate --skip-database --with-form` first**
  (`resources/js/routes`, `resources/js/actions` are gitignored). The Vite plugin does it during
  `npm run build`, which is why the host needs the PHP shim for the build.
- **`public/build` must exist before Pest**, otherwise every Inertia page test 500s on the missing
  Vite manifest (128 spurious failures in the first run, 6 real ones after the build).
- **Delete `docs-site/dist` and `clients/agent/dist` before `npm run lint:check`** — the root ESLint
  config does not ignore them (52 345 findings with them, 130 without).
- `vendor/` and `node_modules/` are symlinked from the main checkout in this worktree (per plan § 3);
  a symlinked `vendor` breaks PHPStan's bootstrap because Composer's autoloader resolves `app/`
  relative to the physical vendor directory. Run PHP gates where `vendor` is real.

## 6. Gate results on the validated merge (`be3a88b`, against `feat/mcp-server` `2c1d1d3`)

| Gate | Result | Detail |
|---|---|---|
| `pint --test` | **FAIL** | 3 files — see 6.1 |
| `phpstan analyse` (level 8) | PASS | `[OK] No errors` |
| `pest` (full) | **FAIL** | 6 failed, 2 risky, 5 skipped, 3 465 passed — all 6 in `ScreenshotSeederTest`, see 6.2 |
| `composer validate --no-check-publish` | PASS | `./composer.json is valid`; `laravel/mcp ^1.2` in lock + vendor |
| `prettier --check resources/` | PASS | `resources/recipes/**` clean (mcp reformatted them) |
| `npm run build` | PASS | 2.05 s, no errors |
| `npm run types:check` | PASS | after Wayfinder codegen |
| `npm run lint:check` | **FAIL** | 130 findings — see 6.3 |
| agent `lint` / `test` / `build` | PASS | 108 tests in 16 files, `tsc` clean |
| recipe validator | PASS | `OK: 22 recipes valid (2 published, 20 draft)` |
| docs `build` / `check:links` | PASS | 62 pages, 168 assets, no broken internal links |
| docs container, deep links | PASS | image builds; `GET /docs/`, `/docs/recipes/ut2004`, `/docs/mcp/tools` → **200** |
| `scripts/ops/check-public-safe.sh` | PASS | `public-safe check passed` (`gitleaks` itself is not installed on the host) |

All three red gates are **inside `feat/mcp-server`**, none is a merge artefact: the merged tree is
that branch plus one README. They must be fixed on the branch (or by the coordinator, for the shared
file) before the slice can be called green.

### 6.1 Pint — 3 files (`feat/mcp-server`)

```
database/seeders/ScreenshotSeeder.php                 fully_qualified_strict_types, unary_operator_spaces
tests/Architecture/core-optional-baseline.php         array_syntax, single_space_around_construct
tests/Architecture/module-dependencies.php            array_syntax, single_space_around_construct
```

Fix (mechanical, no behaviour change):

```bash
./vendor/bin/pint database/seeders/ScreenshotSeeder.php \
  tests/Architecture/core-optional-baseline.php tests/Architecture/module-dependencies.php
```

`ScreenshotSeeder` uses fully-qualified `\App\Modules\...` references although the imports exist at
the top of the file. The two baselines were refreshed in `2ffc930` with the raw output of the
ratchet's `LANOMAT_ARCH_DUMP=1` path, which writes `return array ( … );` via `var_export`
(`tests/Architecture/ModuleDependenciesTest.php:85`); `main` carries the same files in pint-clean
short-array form, so the dump is followed by `pint` in the normal workflow. Run pint after any
future dump, otherwise the baseline is regenerated "wrong" on the next ratchet run.

### 6.2 Pest — 6 failures in `ScreenshotSeederTest` (`feat/mcp-server`)

```
SQLSTATE[23502]: null value in column "path" of relation "shared_files" violates not-null constraint
Failing row contains (..., local, null, UT2004-DVD1.iso, null, null, pending, ...)
```

`database/seeders/ScreenshotSeeder.php:639` creates the demo file with
`SharedFile::query()->updateOrCreate([...], ['disk' => …, 'path' => …, 'size_bytes' => …, 'mime' => …, 'visibility' => …, 'reviewed_by' => …, 'reviewed_at' => …])`,
but `app/Modules/Files/Models/SharedFile.php:41` deliberately keeps `$fillable` at
`event_id, user_id, original_name` (the path is always set by the upload action). The extra
attributes are silently discarded, so the insert has a NULL `path`.

Fix: follow the `ServerLink` pattern a few lines below — `firstOrNew([...])` + `$model->forceFill([...])->save()`.

The 2 risky tests are the Music Assistant jukebox cases
(`it does not throw when Music Assistant is unavailable …`) and predate this slice; they are not a
merge regression.

### 6.3 ESLint — 130 findings created by the slice’s new sub-projects

`npm run lint:check` is `eslint .` with the root flat config, which has **no ignore entries** for the
three new sub-projects, so it lints them with the app’s `projectService`:

| Path | Findings | Cause |
|---|---|---|
| `clients/agent/**` | 66 | 18 × "file was not found by the project service" (the agent has its own `tsconfig`), 33 × `import/order`, 11 × `import/consistent-type-specifier-style`, 3 × `@stylistic/padding-line-between-statements`, 1 × unused `eslint-disable` |
| `docs-site/scripts/**` | 46 | 28 × `@stylistic/padding-line-between-statements`, 16 × `curly`, 2 × unused vars |
| `resources/recipes/schema/**` | 18 | `validate.mjs` under the app's TS project service: 18 × `@stylistic/padding-line-between-statements` |

Both sub-projects pass their **own** gates (`clients/agent: npm run lint` → 0; `docs-site` builds and
link-checks clean). The root config just must not read them. `eslint.config.js` is a shared file, so
the fix belongs to the coordinator or to card 17 (CI/ops), not to a worker:

```js
ignores: [
  // …
  'clients/agent/**',
  'docs-site/**',
  'resources/recipes/**',
],
```

Alternative if the root config is meant to own them: add `docs-site/tsconfig.json` to the resolver
and fix the style findings — 109 of 130 are auto-fixable, the 18 parse errors are not.

## 7. Rollback

Every merge records its pre-merge head; rollback is one command and never a fix-forward.

| Step | Pre-merge head (trial) | Result (trial) | Rollback |
|---|---|---|---|
| base | `63e8a2b` | — | — |
| 1 `feat/mcp-server` | `63e8a2b` | `e1ecce2` | `git reset --hard 63e8a2b` |
| 2 `feat/game-recipes` | `e1ecce2` | `89dffd8` | `git reset --hard e1ecce2` |
| 3 `feat/agent-client` | `89dffd8` | `519bd42` | `git reset --hard 89dffd8` |
| 4 `docs/zudoku` | `519bd42` | `be3a88b` | `git reset --hard 519bd42` |

In the real run, write down `git rev-parse --short HEAD` before each merge and append it to this
table. `git reflog` on the target branch is the fallback. Because `rerere` is on, a revert + re-merge
replays the same resolutions.

## 8. Worker branches — first-pass conflict forecast

Simulated on `scratch/r5-sim` (= the validated slice `be3a88b`) by merging each worker head with
`--no-commit`, in card order, committing the clean ones. Workers are still moving; treat the table as
a forecast, not a contract, and re-run the simulation before the real wave.

| Card | Branch | Head | Merge onto the slice | Conflicts / notes |
|---|---|---|---|---|
| 1 | `swarm/w1` | `dea4571` | clean, 12 files | recipes factories/policy/Filament tests |
| 2 | `swarm/w2` | `9d928ce` | clean, 10 files | sync pruning, sources, export |
| 3 | `swarm/w3` | `a818d15` | clean, 10 files | MCP device flow hardening |
| 4 | `swarm/w4` | `faf7586` | clean, 15 files | MCP tool contracts + gating |
| 5 | `swarm/w5` | `678023c` | clean, 8 files | games page queries, indexes |
| 6 | `swarm/w6` | `46008b4` | clean, 9 files | agent launch/deep links |
| 7 | `swarm/w7` | `b5c2f3a` | **1 conflict** | `clients/agent/README.md` — w6 and w7 both appended sections; keep both |
| 8 | `swarm/w8` | `b36c4c3` | clean, 10 files | runner quoting + config migration |
| 9 | `swarm/w9` | `753a665` | still at base | no commits yet |
| 10 | `swarm/w10` | `0b127a3` | **28 conflicts** | `resources/recipes/**` — see 8.1 |
| 11 | `swarm/w11` | `746b1a7` | **3 conflicts** | `resources/recipes/{README.md,schema/recipe.schema.json,schema/validate.mjs}` — see 8.2 |
| 12 | `swarm/w12` | `c35225d` | still at base | no commits yet |
| 13 | `swarm/w13` | `24d031f` | clean, 21 files | screenshots for games library + MCP pairing |
| 14 | `swarm/w14` | `c35225d` | still at base | no commits yet |
| 15 | `swarm/w15` | `1dce109` | **1 conflict** | `compose.yml` — see 8.3 |
| 16 | `swarm/w16` | `63e8a2b` | still at base | its work sits on `review/w16` (`b36c4c3`, identical to `swarm/w8`) — coordinator must say which ref is the deliverable |
| 17 | `swarm/w17` | `33d803b` | **2 conflicts** | `.env.example`, `README.md` — see 8.4 |
| 18 | `swarm/w18` | `e7db357` | clean, 5 files | seeded end-to-end script |
| 19 | `swarm/w19` | `dfb841d` | **3 conflicts** | `GamePageController.php`, `Games/Index.vue`, `Games/Show.vue` — see 8.5 |

General rules for the worker wave:

- Merge in card order so review dependencies hold; a `feat/game-recipes`-based branch (w9–w11) must
  merge after `feat/game-recipes`, and its recipes then need the reformat step below.
- **After every recipe/schema merge** run
  `npx prettier --write resources/recipes && npx prettier --check resources/ &&
  node resources/recipes/schema/validate.mjs` — the worker branches were cut from the
  non-reformatted `feat/game-recipes` tree, so they reintroduce style drift.
- Any worker that adds a PHP class needs `composer dump-autoload` in the **real** vendor
  (`lanomat-app` image) or the class is invisible to PHPStan/Pest.
- Workers that touch `config/recipes.php`, `config/mcp_agent.php`, `routes/mcp.php`,
  `routes/web.php`, `AppServiceProvider.php` or `Module.php` get a normal modify/modify conflict
  with the slice; resolve by keeping both sides and re-running R1's architecture checks.

### 8.1 `swarm/w10` (recipes normalization + CS 1.6) — 28 conflicts, content trap

The branch was cut from `feat/game-recipes`, so it carries the **old formatting** plus real content
work (normalised drafts, `counter-strike-1-6`). Resolution:

1. `git checkout --theirs -- resources/recipes` (w10 is the newer content).
2. **Re-add the launch metadata the slice added** — w10's copies of
   `unreal-tournament-2004/recipe.yaml` and `ridge-racer-type-4/recipe.yaml` have no
   `metadata.launch`, `launch_vr`, `play_url`, `multiplayer`, `install_size`. Without them the games
   page launch button and the agent deep link silently die for the two published recipes.
3. `npx prettier --write resources/recipes`, then the validator.
4. Verify: `grep -l "metadata:" -A6` on both files, and `git diff be3a88b -- resources/recipes | grep '^-.*launch'` must be empty.

### 8.2 `swarm/w11` (VRM avatar catalog) — 3 conflicts

Same base problem (old formatting) with a real addition: an `avatars` recipe plus ~15 lines of JSON
schema and ~79 lines of validator. Resolution: take the branch side for the three files, then
`npx prettier --write resources/recipes` (this reproduces the slice's formatting), keep both sides of
`resources/recipes/README.md`, run the validator.

### 8.3 `swarm/w15` (docs deployment) — `compose.yml`

Both sides add a `docs:` service; w15's is the hardened superset (`image: lanomat-docs:${APP_VERSION}`,
`read_only`, `tmpfs: /tmp`, `cap_drop: ALL`, `no-new-privileges`, `expose: 8080`, own healthcheck,
and it deliberately does **not** attach the app's `secure-headers@file` middleware because the image
sets its own headers). Take **theirs**, then check: profile `docs`, the `/docs` prefix is not
stripped by Traefik, and `docs-site/README.md` still describes the command that now exists.

### 8.4 `swarm/w17` (CI/ops) — `.env.example`, `README.md`

Both sides rewrite the recipe-source block. Resolution: keep w17's `<NAS_HOST>` placeholder, keep
both README sections, and **fix one stale claim**: w17 writes "smbclient, which is not in the app
image", but `c238734` now installs `smbclient` and `git` in the runtime stage
(`docker/Dockerfile`, prod stage). The comment must say the image ships them, otherwise ops will
mount a share for no reason. w17 also adds `docs/infra/mcp-ops.md`, which its `.env.example` comment
links to — the merge must land both, never one without the other.

### 8.5 `swarm/w19` (games page E2E) — 3 conflicts

w19 changes `GamePageController` and the two `Games/*.vue` pages for test hooks; w5 (merged earlier)
changed the same files for batching/indexes. Resolve by hand, keeping w5's query work and w19's
hooks, then run the targeted games tests plus `npm run types:check` and the Playwright/browser run.

## 9. Open questions for the coordinator

1. `feat/mcp-server` is red on three gates (§ 6). Fix on the branch, or fix-forward on the merge
   (pint is mechanical)? The plan says revert, not fix forward — but reverting the only content
   branch would revert the whole slice.
2. Who owns `eslint.config.js`? It is not a worker-writable path in any card.
3. Keep `docs-site/README.md` (from `docs/zudoku`)? It is the only unique file in that branch and
   documents the site for contributors — kept in the trial.
4. Is `feat/mcp-server` still moving? It advanced 3 commits during this review; gates were run
   against `2c1d1d3`.

Answers received from the coordinator (2026-11-21, binding):

1. Mechanical failures (pint, import order, ESLint sub-project scope) are fixed **on the integration
   branch**, never by reverting a content branch. Behavioural failures still block the branch.
2. `eslint.config.js` is mine on the integration branch: ignore `clients/agent/**`, `docs-site/**`,
   `resources/recipes/schema/**` and keep `storage/**` out. Done in `fbae418`/`085de58`.
3. `docs-site/README.md` stays.
4. `feat/mcp-server` keeps moving; always merge its latest head before starting the next worker.
   Its docs win over older branch docs in conflicts (`77f15d0`, `f709b8a`); never take a worker's
   `docs-site` wholesale.

## 10. Canonical launch contract (settles W9 × W10)

W9 and W10 disagreed: W9 had `launch_targets` (array of `{id,label,os,command,shell?}`) plus a
`play_url` with `{{host}}`; W10 had `metadata.launch` as an object. The contract below is now
implemented on the integration branch (`45f5d0f`) — **both** content branches rebase onto it.

1. `metadata.launch` accepts a **string** (flat command/path) **or an object**
   `{kind: steam|uri|exe, target, args?, shell?, notes?}`. `steam` takes the app id
   (`steam://rungameid/<id>`), `uri` a full URI, `exe` a path plus optional args.
2. `metadata.launch_targets` is the preferred multi-option form (`flat`, `vr`, …). It wins over
   `launch`; `GameRecipe::launchTargets()` normalises entries (defaults for `id`/`label`/`os`, skips
   entries without a command) and `launchTarget(?id)` picks one, falling back to the first. The legacy
   `metadata.launch_vr` still yields a `vr` entry when the list has none.
3. `metadata.play_url` may contain `{{host}}`; `GameRecipe::playUrlFor($host)` substitutes the request
   host in the games page, the REST API and `recipe_get`, so no recipe hardcodes `localhost`.
4. Kept: `metadata.live_url`, and `avatar_converter_url` where a recipe names one.
5. Schema: `$defs.launchSpec` + `$defs.launchTarget`, `metadata.launch` is `oneOf[string, launchSpec]`;
   documented in `resources/recipes/README.md`, `resources/recipes/schema/README.md`,
   `docs-site/pages/recipes/contributing.md` and the agent command list.
6. Agent: picks the OS-matching target (first as default) and supports
   `lanomat-agent launch <slug> --variant <id>`; unknown ids exit 2 and list the available ids.
7. Tests: `tests/Unit/GameRecipes/LaunchTargetsTest.php` (string/object/array/legacy/defaults),
   `tests/Feature/GameRecipes/LaunchContractTest.php` (`{{host}}` substitution, target list in the
   payload), `clients/agent/tests/launch-targets.test.ts` (selection, variant, unknown id).
8. Recipe-side canonical shape: `requires` in YAML (not `requirements`), `status`, `platforms`.

## 11. Integration branch — running log

Branch `integration/mcp-agent` in this worktree, based on `swarm/r5`.

| # | Ref | Commit | Result | Notes |
|---|---|---|---|---|
| 0 | `swarm/r5` base | `63e8a2b` + log commits | — | merge log lives here |
| 1 | `feat/mcp-server` | `838275f` | clean | whole slice; later re-merged twice as the branch moved (`ea42b90`, `62328e5`) |
| 2 | green-base fixes | `fbae418` | — | pint on `ScreenshotSeeder` + both architecture baselines, ESLint sub-project ignores, one `import/order` fix. Pint and Pest green afterwards (3 471 passed) |
| 3 | `swarm/w7` (R8) | `36a30f1` | 6 conflicts, all "ours == base" | `clients/agent/{package.json,package-lock.json,README.md,Dockerfile,.dockerignore}` + `.github/workflows/client-agent.yml` taken from w7; agent gates `npm ci` / `lint` / `test` (108) / `build` green |
| 4 | launch contract | `45f5d0f` | — | § 10 |
| 5 | `feat/mcp-server` @ `62328e5` | `3ee5cbc` | 2 conflicts | `eslint.config.js` union of the identical ignore sets; `docs-site/pages/agent/install.md` keeps mcp's newer command list + the `--variant` line |
| 6 | `swarm/w3` (R10) | `eabf3fd` | 3 conflicts | `ExchangeDeviceCode`: keep HEAD's smallint comment, clamp via `min(self::MAX_INTERVAL, …)`; `IssueMcpTokenCommand`: HEAD's empty-needle guard **plus** w3's grouped `where()` closure (no `orWhere` leak); `docs-site/pages/mcp/security.md` keeps our newer wording (w3's row is the same fact, older phrasing) |

Deferred at this point (review not approved / re-review pending): w2 (attempted, 3 conflicts forecast,
merge aborted), w1, w4, w5, w6, w8, w16, w17, w9–w11, w12–w15, w22.

### 11.1 The w2 attempt and its planned resolution

`swarm/w2` (head `9d928ce`) was merged and **aborted** again after R13 reopened it. Its three
conflicts against the slice are all "both hardened the same code":

| File | Resolution |
|---|---|
| `RecipeValidator.php` | keep w2's `match must be a list` check, keep HEAD's `required`/`manual` default and its message |
| `RecipeFileMatcher.php` | use w2's `$this->query($event)->whereKey(...)` — the helper carries the same visibility + non-draft + event filters as HEAD's inline query |
| `RecipeSourceLocator.php` | keep HEAD's credentials file (`-A`, password out of `ps`) **and** w2's `-D <sub>` argv form; git branch keeps HEAD's `--`+scheme allowlist and w2's `ExecutableFinder` try/catch |
