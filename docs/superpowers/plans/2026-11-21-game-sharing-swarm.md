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
| D6 | Trigger: `RECIPES_PR_TRIGGER=publish` (default) opens the pull request when the orga publishes, so only reviewed text reaches the repository; `contribute` opens it when a participant shares. Publishing a contributed or panel recipe always opens or updates it. The automatic path is bounded per contributor and per installation. |
| D7 | Before anything is sent, the text passes a public-safety check (addresses, e-mail addresses, key material, tokens) and a catalogue readiness check (what the repository's validator would refuse). A refused recipe gets `pr_state = blocked` with the reasons and is never sent. Attribution is off by default. |
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

The container cannot write to your worktree: run Pint with `--test` to see what it wants, or let
the tool rewrite the file inside the container and fetch it with
`/tmp/opencode/lanomat-swarm/bin/wtpull <name> <worktree> <path>...`. One call per container runs at
a time; a second call waits. Do not run the whole Pest suite (the coordinator does); run the test files
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
| R-sec, R-arch, R-ui | 3 | independent reviews of the merged result (security, architecture and correctness, frontend). The planned test and docs reviews did not run in that round; section 8 makes up for them. |

## 6. Definition of done

- `composer check` (Pint, PHPStan level 8, full Pest) green; `npm run lint:check`,
  `format:check`, `types:check`, `build` green; `node clients/launcher/app/ui/check-ui.mjs` green;
  `node resources/recipes/schema/validate.mjs` green; `scripts/ops/check-public-safe.sh` green.
- The story test passes: share, approve and publish, visible on `/games` and in the launcher
  catalogue, install link on the matching server, pull request proposed and its state synced.
- The admin handoff names every new switch and the order to turn them on.

## 7. What the reviews changed

Three independent reviews (security, architecture, frontend) ran on the merged result. What they
changed, so the plan above is read with it:

- **A shared title could reach a shell.** The install folder was built from the title and is
  substituted into quoted command arguments. The folder now keeps letters, digits, space, dot,
  underscore and hyphen only (`BuildInstallPlan`), and the share builder prints the same
  literal-quoted commands it runs.
- **The orga reviews what runs.** The panel shows launch commands, the play link and every step's
  checks before publishing; they were stored but invisible.
- **Pull requests are bounded and reviewed.** Default trigger `publish`; per-contributor and
  per-installation limits; a readiness check instead of a red pull request; an existing open pull
  request is never recorded as "not sent".
- **One name per action.** "Im Launcher beitreten" comes first wherever a launcher join exists;
  the game page has one amber action.
- **A launcher link answers.** Every `lanomat://` action notices when nothing took over and says
  what to do. This came from a production report, not from the plan.

Not done, on purpose: quoting `{{target}}` at execution time in the installer (a player who picks
an install path with a quote in it can break their own command), and anything that needs the real
GitHub API or real hardware to verify.

## 8. Gap round (2026-11-22)

What section 2 left out on purpose (D12), what the reviews listed as residual, and what the first
round never verified. Same rules as section 4. `main` moved in between: `clients/launcher`'s core
now has a copy in `clients/voice/launcher-core` and is being merged into one desktop app
(`docs/superpowers/decisions/2026-11-22-all-in-one-client-adr.md`).

### 8.1 Decisions

| # | Decision |
|---|---|
| G1 | **No Rust in this round.** Packing and uploading a game from inside the launcher would add an upload engine to `clients/launcher/launcher-core`, which the all-in-one plan copies and then retires; the voice client already has resumable uploads. The launcher keeps opening the share page in the browser. The feature belongs to the merged app. |
| G2 | A server row says who is on it by **account**, from the Presence read model and under its visibility rule (`share_location`, blocks; the viewer always sees themselves). Website only: the bearer APIs do not carry it. |
| G3 | The install block says when the game's archive is not on the LAN (`lanReady`). The launcher join stays: a player who already has the game can still join. |
| G4 | A recipe never names this installation's server. Step and launch commands use `{{server_host}}` and `{{server_port}}`, which the server fills in when it builds the plan, from a server of that game the viewer may see; without one they are empty and the step leaves the game's configuration alone. |
| G5 | A contribution answer says what the catalogue would refuse (`ProposeRecipe::preview()`), and the MCP tool takes `skill_markdown`, so an assistant can finish a recipe without the panel. |
| G6 | The Node agent refuses an install path it cannot quote, instead of building a broken command from it. |

### 8.2 Frozen contracts

**Preview** (landed before the round): `ProposeRecipe::preview(GameRecipe $recipe, ?RecipeStatus $as = null): list<string>`.
No repository call, no state. Empty means ready.

**Contribution answer** (`recipe_contribute` and `POST /api/v1/recipes`), one new key:

```jsonc
"catalog": {
  "pull_requests_enabled": true,          // RecipeRepository::isConfigured()
  "problems": ["SKILL.md has 19 lines; …"],  // preview() for the stored status
  "problems_when_published": []           // preview(…, RecipeStatus::Published)
}
```

**Install block** (section 3.1), one new key: `lanReady: bool`. True when every required base or
disc image of the recipe has an approved file on the LAN (pinned or matched, in an event that is
not a draft) or an external URL. Viewer-independent. `launcherJoinUrl` does not depend on it.

**Occupants**, a new key on a server row, filled on the server pages (`/servers`, `/servers/all`, an event's servers) and `null` on every other surface:

```ts
occupants: { people: Array<{ id: number; name: string; avatarUrl: string | null }>; more: number } | null
```

`null` while Presence is off or there is no logged-in viewer. `people` holds at most 8 accounts the
viewer may see, by name; `more` counts further *visible* accounts. Somebody who hides their location
is in neither. Contract `App\Modules\GameServers\Contracts\ServerOccupants` with a null
implementation (the Presence-backed one lives in WebVoice, which already depends on both), resolved once per web request.

**Plan variables**: `{{server_host}}` (letters, digits, dot, hyphen; at most 253 characters) and
`{{server_port}}` (digits), substituted by `BuildInstallPlan` in step commands, step checks and
launch targets before the plan leaves the server. Anything else resolves to an empty string.

### 8.3 Cards

| Card | Scope |
|---|---|
| M1 mcp-finish | `recipe_contribute` takes `skill_markdown`; the `catalog` key in both contribution answers; a how-to resource or prompt for a catalogue-ready recipe |
| A2 panel-preview | the panel shows what would block a pull request, as a draft and once published; a requirement's `os`, `manual`, notes and tags are visible at review |
| P1 occupants | the `ServerOccupants` contract, its Presence-backed implementation, the `occupants` key, the line on server cards and rows; one catalogue read per request on every server surface |
| S3 lan-ready | `lanReady` in the install block and its hint; the lineup compares slugs the same way everywhere |
| N1 server-vars | plan variables; the NFSU2 recipe, `config/launcher.php` and the seeder without a built-in address |
| AG1 agent-paths | the Node agent refuses install and download paths it cannot quote |
| Q1 contribution-rows | file rows keep their ids across an edit; a form upload stores a cleaned file name; the files page asks the server which archives can be shared |
| Q2 frontend-polish | the share page selects the archive just uploaded, the quota gauge, the match page's join, small type leftovers (its launcher page rows were dropped again: see section 9) |
| C1 js-ci | the JS unit tests and the launcher UI guard run in a workflow |
| DOC-A1, DOC-A2 | audit of `docs-site/` against the code (participant and operator halves) |
| DOC-B1, DOC-B2 | audit of `docs/`, `README.md`, `CHANGELOG.md`, the handoff |
| SCREENS | screenshots of every surface the two rounds changed |
| E2E | one real-browser run of the journey, including the launcher-link notice |
| R-sec2, R-corr2 | independent reviews of the gap round |

## 9. What the gap round's reviews, the browser run and the audits changed

Seventeen subagents ran: nine builders (M1, A2, P1, S3, N1, AG1, Q1, Q2, C1), four documentation
auditors, one screenshot worker, one real-browser tester and two independent reviewers. What they
changed against section 8, so that section is read with it:

- **A plan names a server only of exactly the recipe's game.** Both reviewers found independently
  that N1's matcher-based rule wrote the browser twin's public host into the native NFSU2
  configuration whenever no native server existed, which is the state of a fresh installation.
  The server's game must carry the recipe's slug; a server that is switched off never qualifies; one
  that answers ranks first. The recipe detail fills the same variables, because the Node agent reads
  its launch targets there.
- **The occupants line follows the event's people list** (`EventPolicy::viewPeople`), not "any
  account", and the projection takes its viewer explicitly.
- **"Im Launcher beitreten" is only said when the link names the server.** The browser run showed a
  bare `lanomat://join/<slug>` under a promise to start "auf diesem Server". A link that cannot name
  the server is "Im Launcher starten".
- **The upload selection of Q2 did not work in form mode.** Its pure function was tested; the page
  around it was not, and only the browser run showed it.
- **Smaller ones:** a contribution's file row keeps a pin only while it still names the same file;
  archive names the installing client would rename are refused; old upload names are cleaned when
  offered as a download; the agent resolves a leading `~/` (it pasted `~/Games/<slug>` literally into
  quoted commands on Linux and macOS before) and refuses `; & | < >` everywhere; a published shared
  game stops calling itself a draft; the games pages name the app's current event.
- **The documentation was not right.** The four audits found well over a hundred statements that
  were not true of the code, some from the first round and many older: seeder commands that do
  nothing in production without `--force`, an agent installer that does not exist, an OpenAPI file
  that described other answers than the controllers give, module and MCP counts. The audits fixed
  what they could verify from code and listed what they could not.
- **`main` moved under the round.** The launcher's download page was merged into one `/app` page
  and its live server list removed while Q2's rows for it were being reviewed. That work was
  dropped in the merge; the server pages carry the same actions.

Verified in a real browser (headless Chromium only): the journey with a form upload, the refusals,
the review and one-step publish, the server rows, the occupants line with its privacy cases, the
missing-files line, and the launcher link without a handler (no navigation, no dialog, no loss of
focus, the notice after 2.5 seconds, retry and dismiss; a simulated loss of focus shows the
confirmation). A launcher build of the tree compiles and packages on Linux.

Not done or not verified, on purpose or for lack of means: packing and uploading from inside the
launcher (G1); a real launcher taking over a link; Firefox and WebKit; the resumable upload path in
a browser; anything on Windows (the agent's PowerShell and cmd rules rest on documentation); the
NFSU2 step on a real PC; the real GitHub API. Left as they are: the game page's "AUF DER LAN" badge
counts a running server (a tested decision that differs from `lanReady`), the config key
`recipes.prefer_lan` that no code reads, the admin recipe table's width at 1440 pixels, and the
order of the join ways on an expanded `/servers` row versus a `/servers/all` card.
