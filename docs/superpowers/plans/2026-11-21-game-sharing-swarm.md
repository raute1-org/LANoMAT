# Game sharing, server-browser glue and recipe pull requests (2026-11-21)

Operating spec for the swarm that finishes the gaming surfaces. A worker reads sections 1 to 4 and
its own card in section 5. Integration branch: `feat/game-sharing`.

## 1. The journey we are finishing

1. **Find.** A participant opens the server browser (`/servers`, `/servers/all`), the games library
   (`/games`), the launcher page or the launcher. Every running server says what it runs and who is
   on it.
2. **Install and join.** From a server, one click installs the game when needed and starts it on
   that server (`lanomat://join/<slug>`), or leads to the game page. From a game, the servers and
   the people looking for a round are one click away.
3. **Share.** Anyone can share a game: upload a ready-to-run archive to the LAN file host and
   describe it on `/games/share`. That creates a draft recipe with the file pinned.
4. **Review.** The orga approves the file and publishes the recipe in one action. A published
   shared game appears on `/games`, in the launcher and next to its servers without a config change.
5. **Keep.** The recipe travels into the git catalogue as a pull request, opened automatically.
   Recipe text only; game files never leave the LAN file host.

## 2. Decisions

| # | Decision |
|---|---|
| D1 | One class decides `launcher_supported`: `GameRecipes\Support\LauncherSupport`. The hard-coded slug lists in `RecipeController`, `HomeChatData` and `LauncherPageController` go away. |
| D2 | A shared game is a **portable recipe**: `metadata.portable: true`, one required `base` archive (`.zip` or `.tar.bz2`), file checks, launch targets. The launcher's generic engine installs it; it never runs step commands. |
| D3 | The lineup is the configured `LAUNCHER_LINEUP` plus every recipe with `game_recipes.in_lineup = true` (`LauncherLineup::slugs()`). Publishing a contributed recipe in the panel sets the flag. An empty configured lineup stays "unrestricted". |
| D4 | `GameServers` asks the `GameServers\Contracts\GameInstallLinks` contract how a player gets a server's game. `GameRecipes\Support\RecipeInstallLinks` answers; `NullGameInstallLinks` when the module is off. No new module edge. |
| D5 | Recipe pull requests go through the `GameRecipes\Contracts\RecipeRepository` contract (`GitHubRecipeRepository`, `NullRecipeRepository`, `Testing\FakeRecipeRepository`). Off by default (`RECIPES_PR_ENABLED`). |
| D6 | Trigger: `RECIPES_PR_TRIGGER=contribute` (default) opens the pull request when a participant shares; `publish` waits for the orga. Publishing a contributed or panel recipe always opens or updates it. |
| D7 | Before anything is sent, the text passes a public-safety check (private and public IP addresses, e-mail addresses, key material, tokens). A refused recipe gets `pr_state = blocked` and is never sent. Attribution is off by default. |
| D8 | One branch and one pull request per recipe: `recipes/<slug>`. A new proposal moves the branch; it never opens a second pull request. |
| D9 | Uploads keep going through the Files module, bound to the current event. `/games/share` reuses the existing ticket and upload routes; it adds no upload endpoint. |
| D10 | No new MCP tool. `recipe_contribute` and `POST /api/v1/recipes` forward the fields `ContributeRecipe` now understands. The tool count does not change. |
| D11 | Recipe text under `resources/recipes/**` is machine-written from now on, so `recipe.yaml` and `SKILL.md` leave the Prettier check; the schema validator is the gate and runs in a GitHub workflow. |
| D12 | Out of scope: account-level "who is on this server" on server cards (needs a Presence to GameServers edge), packing or uploading from the launcher, macOS for shared games, automatic hashing of tus uploads on arrival (a nightly `lanomat:files:hash` run is scheduled instead). |

## 3. Frozen contracts

Already on the integration branch (do not change signatures; ask the coordinator):

- `GameRecipes\Support\LauncherSupport::supports(GameRecipe): bool`, `isPortable(GameRecipe): bool`.
- `GameRecipes\Support\LauncherLineup::slugs()`, `configuredSlugs()`, `allows()`, `filter()`.
- `GameRecipes\Actions\ContributeRecipe::handle(User, array $payload): GameRecipe`. Payload has the
  YAML shape plus `via` (`web|mcp|api`), top-level `launch_targets` and `play_url`, `metadata`,
  `version`, `homepage`, `license`, and `shared_file_id` per requirement (the user's own file or an
  approved one, else a validation error on `requirements.N.shared_file_id`). Dispatches
  `Events\RecipeContributed($recipe, $userId)` after commit.
- `GameRecipes\Events\RecipePublished($recipe, $reviewerId)`: dispatched by whoever publishes.
- `GameRecipes\Contracts\RecipeRepository`: `isConfigured()`, `propose(RecipeProposal): PullRequestRef`,
  `state(int $number): PullRequestState`, `read(string $path): ?string`; throws
  `Exceptions\RecipeRepositoryException` (`->transient`).
- `GameRecipes\Domain\RecipeProposal(slug, branch, commitMessage, title, body, files)` and
  `Domain\PullRequestRef(number, url, branch, state)`; `Enums\PullRequestState`
  (`open|merged|closed|blocked|failed`).
- `GameRecipe`: `in_lineup` (fillable), `pr_number|pr_url|pr_branch|pr_state|pr_error|pr_synced_at`
  (not fillable, `forceFill` only), `isContribution()`, `pullRequest(): ?array{number,url,state,label}`,
  `SOURCE_CONTRIBUTION`.
- `GameServers\Contracts\GameInstallLinks::forServer(?string $gameSlug, ?string $gameName, ?string $address, ?int $port, ?string $joinUrl): ?array`.
- Config `recipes.pull_requests.{enabled,repository,token,base,branch_prefix,path,trigger,attribution,api_url}`.
- Test helper `fakeRecipeRepository(): FakeRecipeRepository` (`tests/Pest.php`); factory states
  `GameRecipe::factory()->portable()`, `->inLineup()`, `->contributedBy($user)`.

### 3.1 Server DTO: the `install` block

Every row of `ServerListProjection::dto()` gains:

```
install: null | {
  recipeSlug: string,
  title: string,
  pageUrl: string,              // "/games/<slug>"
  launcherSupported: boolean,
  launcherJoinUrl: string|null  // "lanomat://join/<slug>?address=<host>:<port>" or "...?url=<urlencoded join url>"
}
```

`install` is null when no published, lineup-allowed recipe matches the server's game. The match is
one class, `GameRecipes\Support\RecipeGameMatcher`: exact game slug first, then the recipe's
`searchTerms()` against the game's slug and name with the rules `GamePageController` uses today.
`launcherJoinUrl` is built by `GameRecipes\Support\LauncherJoinLink` with exactly the rules
`LauncherPageController::liveServers()` has today, and only when `LauncherSupport::supports()`.

### 3.2 The share page

Routes (inside the existing `routable(Module::GameRecipes)` + `auth` group, registered **before**
`/games/{slug}`): `GET /games/share` (`games.share`) and `POST /games/share` (`games.share.store`,
`throttle:20,60`). Controller `GameRecipes\Http\GameShareController`, page `Games/Share`.

Props:

```
event:   { name: string, slug: string } | null     // current public event that takes uploads
upload:  { mode: 'resumable'|'form', ticketUrl: string|null, storeUrl: string, maxFileBytes: number } | null
quota:   { usedBytes: number, capBytes: number } | null
files:   Array<{ id: number, name: string, sizeBytes: number, visibility: 'pending'|'approved', createdAt: string, usable: boolean }>
shares:  Array<{ slug: string, title: string, status: 'draft'|'published'|'archived', statusLabel: string,
                 inLineup: boolean, gameUrl: string|null,
                 file: { name: string, visibility: 'pending'|'approved'|'rejected' } | null,
                 pullRequest: { number: number|null, url: string|null, state: string, label: string } | null,
                 updatedAt: string }>
selectedFileId: number | null                       // from ?file=<id> when it is one of `files`
limits:  { titleMax: 120, summaryMax: 200, executableMax: 200, archiveExtensions: ['.zip', '.tar.bz2'] }
uploadLabels: Record<string, string>                // trans('files.page'), for the ResumableUploader
```

The page's own German copy is written inline in the Vue files, as `Games/Index.vue` and
`Games/Show.vue` do; `lang/de/game_share.php` holds only what PHP says (flash messages, validation
messages and attribute names). A placeholder `Games/Share.vue` exists on the integration branch so
the controller can render; card G2 replaces it.

`files` are the viewer's own non-rejected files of the current event, newest first, at most 50;
`usable` is false when the name does not end in an allowed archive extension. `shares` are the
viewer's own contributions, newest first; `gameUrl` is set only when the recipe is published and the
lineup allows it.

`POST /games/share` body:

```
title            required string 2..120
shared_file_id   required integer        // the viewer's own file or an approved one; never a rejected one
platforms        required array, min 1, each of windows|linux
executable       required string <= 200  // path inside the unpacked folder, e.g. "bin/Game.exe"
linux_runner     nullable native|wine    // required when platforms has linux
linux_executable nullable string <= 200  // required when linux_runner = native
summary          nullable string <= 200
version          nullable string <= 40
homepage         nullable http(s) url <= 500
players          nullable string <= 20   // "2-8"
multiplayer      boolean
```

Paths are relative: no leading `/` or `\`, no drive letter, no `..` segment, no control or shell
characters. The slug is `Str::slug(title)`; `share` is reserved; a slug that exists and is not the
viewer's own draft is a validation error on `title`. Success redirects to `games.share` with a
flash message. `GameRecipes\Support\PortableRecipeBuilder` turns the input and the file into a
`ContributeRecipe` payload (`via: web`, `metadata.portable: true`); `Actions\ShareGame` runs it.

### 3.3 Exporter

`GameRecipes\Support\RecipeYamlExporter`:

```php
/** @return array{yaml: string, skill: string} */
public function export(GameRecipe $recipe): array;
/** "| `slug` | Title | windows, linux | draft | Summary |" */
public function catalogRow(GameRecipe $recipe): string;
```

The YAML is `lanomat.recipe/v1` and passes `resources/recipes/schema/recipe.schema.json`: every
required key is present, `launch_targets` and `play_url` sit at the top level, `metadata` holds only
schema keys. `RecipeParser` reads it back to the same recipe. It never contains `shared_file_id`,
people, `pr_*`, `in_lineup` or `contributed_via`.

### 3.4 Pull request pipeline

`GameRecipes\Actions\ProposeRecipe::handle(GameRecipe $recipe): GameRecipe`, queued through
`GameRecipes\Jobs\ProposeRecipePullRequestJob(int $recipeId)`. Files in a proposal:
`<path>/<slug>/recipe.yaml`, `<path>/<slug>/SKILL.md` and `<path>/_catalog.md` with the recipe's
row added or replaced. Commands `lanomat:recipes:propose {slug}` and `lanomat:recipes:pr-status`.

## 4. Rules for every worker

**Isolation.** You work only in your own git worktree `/tmp/opencode/lanomat-swarm/<name>` on branch
`swarm/<name>`. Never edit the main checkout or another worker's worktree. Edit only
the files your card lists as writable; if you need a change elsewhere, stop and report it. Never
touch `CHANGELOG.md`, `README.md`, `.env.example`, `docs/ops/**` or
`app/Providers/AppServiceProvider.php` unless your card names them. Never push, never merge, never
rebase, never run `git stash`, `git reset --hard` or `git clean`.

**Running things.** There is no PHP, Composer or Rust on this host. Every PHP and npm command runs
through the harness, which syncs your worktree into a container with its own database:

```bash
/tmp/opencode/lanomat-swarm/bin/wt <name> /tmp/opencode/lanomat-swarm/<name> -- <command>

# examples
wt w03 /tmp/opencode/lanomat-swarm/w03 -- php artisan test --compact tests/Feature/GameRecipes
wt w03 /tmp/opencode/lanomat-swarm/w03 -- vendor/bin/pint app/Modules/GameRecipes tests/Feature/GameRecipes
wt w03 /tmp/opencode/lanomat-swarm/w03 -- vendor/bin/phpstan analyse --memory-limit=2G --no-progress
wt w03 /tmp/opencode/lanomat-swarm/w03 -- 'php artisan wayfinder:generate --with-form >/dev/null && npm run types:check'
```

The container cannot write to your worktree: run Pint with `--test` to see what it wants, or apply
the formatting yourself. Do not run the whole Pest suite (the coordinator does); run the test files
and folders your change touches. Do not use `--parallel`. Plain `node` scripts
(`clients/launcher/app/ui/check-ui.mjs`, `resources/recipes/schema/validate.mjs`) run on the host.

**Conventions.** Read `CLAUDE.md`. In short: code, comments, commits and docs in English; UI copy in
German (`lang/de/*.php` for PHP, inline in the Vue file only where that file already does it).
`declare(strict_types=1)`, final classes, typed everything, PHPStan level 8 clean, Pint clean, no
`mixed` returns, enums over magic strings. One Action class per use case, thin controllers, every
authorization through a Policy or Gate, never trust a client-supplied user id. A feature test file
starts with `uses(RefreshDatabase::class);`. Never call a real external API in a test
(`Http::fake`, `fakeRecipeRepository()`). Vue: `<script setup lang="ts">`, no `<style>` blocks,
semantic Tailwind tokens only (no hex), all four states (empty, loading, error, normal), visible
focus, usable at 375 px; follow `docs/design.md`. Match the comment density of the file you edit.
No real IP address, e-mail address, host name, token or person's name in any file: the repository
is public (`scripts/ops/check-public-safe.sh`). Use `lan.example`, `203.0.113.x` and invented names.

**Verify before you build.** If you use a framework or API feature you are not certain about, check
the current official documentation first (Laravel 13, Filament 5, Inertia 2, Vue 3, GitHub REST).

**Done means.** Your tests exist and pass, Pint and PHPStan are clean for the files you touched,
the frontend checks pass if you touched frontend files, and everything is committed on your branch
with Conventional Commits. Your final message is a report: what you built, the files you changed,
each command you ran with its result, and anything that is unfinished, uncertain or different from
this spec. Say plainly when something does not work; do not paper over it.

## 5. Cards

Wave 1 runs in parallel on the foundation commit. Wave 2 starts after wave 1 is merged.

| Card | Wave | Scope |
|---|---|---|
| B1 share-backend | 1 | `ShareGame`, `PortableRecipeBuilder`, `GameShareController`, routes, route matrix, `lang/de/game_share.php` |
| B2 api-forwarding | 1 | `RecipeController`, `RecipeContributeTool`, `HomeChatData`: new contribute fields, `LauncherSupport`, own drafts carry their pull request |
| B3 yaml-exporter | 1 | `RecipeYamlExporter`, repository-layout export command, round-trip tests |
| B4 github-client | 1 | `GitHubRecipeRepository` over the GitHub REST API, `Http::fake` tests |
| B6 plan-and-sync | 1 | `BuildInstallPlan` target folder and `play_url`, `SyncRecipes` `--only` with `--prune` |
| S1 server-glue | 1 | `RecipeGameMatcher`, `LauncherJoinLink`, `RecipeInstallLinks`, `install` block in `ServerListProjection`, `LauncherPageController`, `GamePageController` |
| S2 server-pages | 1 | `ServerCard`, `ServerRow`, Now join rows: install, launcher join, find players |
| G1 games-pages | 1 | `Games/Index`, `Games/Show`: share entry, honest join buttons, states |
| G2 share-page | 1 | `Games/Share` page and its components, "Als Spiel teilen" on the files page |
| L1 launcher-ui | 1 | launcher UI: share entry, generic recipes, copy |
| R1 recipes-ci | 1 | validator green, catalogue check, GitHub workflow, Prettier scope |
| B5 pr-pipeline | 2 | `ProposeRecipe`, job, listeners, commands, schedule, public-safety check, Preflight check |
| A1 admin-glue | 2 | Filament: lineup, pull request state, approve files and publish, propose |
| D1..D3 docs | 2 | docs site, repository docs, design rules |
| T1 story-test | 2 | one feature test that walks the whole journey |
| R-arch, R-sec, R-test, R-ui, R-docs | 3 | independent reviews of the merged result |

## 6. Definition of done

- `composer check` (Pint, PHPStan level 8, full Pest) green; `npm run lint:check`,
  `format:check`, `types:check`, `build` green; `node clients/launcher/app/ui/check-ui.mjs` green;
  `node resources/recipes/schema/validate.mjs` green; `scripts/ops/check-public-safe.sh` green.
- The story test passes: share, approve and publish, visible on `/games` and in the launcher
  catalogue, install link on the matching server, pull request proposed and its state synced.
- The admin handoff names every new switch and the order to turn them on.
