# M6 — Gameserver (Pelican) & Stats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Derived just-in-time from the roadmap M6 section (`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`), same format as the M4/M5 detail plans.

**Goal:** One-click game servers from the match context — a match becoming ready provisions a Pelican server, writes its join info to the match page + Discord embed, and cleans up when the tournament ends; plus a cross-event stats/leaderboard, a games catalog, server presets with resource guardrails, a warmup→live match gate, and (where telemetry exists) CS2 live stats.

**Architecture:** A new `app/Modules/GameServers/` module owns the Pelican integration behind a `PelicanClient` contract (Http impl + Fake, mirroring the M3 `MumbleClient`/`DiscordClient` pattern exactly) — the app **only ever calls Pelican's REST API**; Pelican Panel + Wings run as external infra, so every app-side task is fully testable against `FakePelicanClient` with no real Pelican. A new cross-event **`Game`** catalog (`games` table) finally backs the pre-existing nullable `tournaments.game_id`. Server provisioning follows the established thin-listener → queued-job pattern on `MatchReady`/`TournamentCompleted`, writing to a new `server_links` table (the roadmap/`architecture.md` design). New UI (Filament server overview, participant server list, `Stats/Leaderboard`, infoscreen `SceneServers`) is **bound by the M13 "Signalpult" design system** (`docs/design.md`). Pelican itself is verified via a spike (6.1) that decides one-click vs. manual mode per game.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5 (Schema API), Inertia v2 + Vue 3 `<script setup lang="ts">` + Tailwind v4 + shadcn-vue (Signalpult tokens), Reverb + `useEventChannel`, Pest 4, PostgreSQL 16, Redis, Docker Compose (FrankenPHP prod profile). New external system: **Pelican Panel** (Application + Client REST API) + **Wings** daemon — accessed only through the `PelicanClient` contract; **never called in tests** (Fake only). Optional per-game telemetry: **MatchZy/G5API** (CS2, 6.9).

## Global Constraints

Copied verbatim from the roadmap Global Constraints + the binding conventions from M2–M5 reviews — every task's requirements implicitly include these:

- Code, comments, commits, docs in **English**; UI copy in **German** via `lang/de/*.php` (no hardcoded strings in components — pass a `labels` prop from `trans('...')`).
- **Conventional Commits** (`feat(scope): …`). TDD: failing test first wherever there is a testable behavior; frequent commits. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. **Stage only task-specific paths** (never `git add -A`).
- PHP: Pint (Laravel preset), Larastan **level 8**, no `mixed` returns, **enums over magic strings**. Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- **Every authorization through a Policy** (registered in `AppServiceProvider::configureAuthorization()`); never trust client-supplied user IDs (`$request->user()`; Discord via `discord_id`). Helpers (`isHelper()`, M5) may operate servers where the roadmap says orga/helper; `canAccessPanel()` stays `isOrga()`.
- **Actions pattern:** one class per use case, single `handle()`, returns the domain result. For capacity/window/transition or "check over child rows": `DB::transaction` + parent-row `lockForUpdate()` **first**; `QueryException` narrowed to SQLSTATE `23505`. Input validation in the domain Action (not only the FormRequest) — the guardrails (6.7) especially must enforce in the Action/Job, not just UI.
- **Privilege/state fields never `$fillable`** (server status, join_info, provisioned ids, match warmup/live state, seed) — set only inside Actions/Jobs. Factories bypass `$fillable`.
- **Typed jsonb** via a `CastsAttributes` cast + readonly DTO, edited in Filament with typed components (the M4 `MenuCast` / M5 `SceneConfigCast` precedent) — `games.default_server_config` and `server_links.join_info` are typed, not raw `KeyValue`.
- **External systems only via contracts + fakes.** `PelicanClient` (new) joins `DiscordClient`/`MumbleClient`; `HttpPelicanClient` retries only transient errors (`Http::retry`, 429/5xx + connection) like `HttpMumbleClient`; **no real Pelican in tests** (`FakePelicanClient` + a `fakePelican()` helper). `Http::preventStrayRequests()` is global in the external-touching test dirs — add `Feature/GameServers`.
- **Provisioning is queued & idempotent:** listeners are thin (`ShouldQueue` → dispatch job); jobs (`ShouldQueue`) do the API work, are idempotent (re-run is a no-op if already provisioned), run after commit, and never block the bracket-progression transaction. Delayed cleanup via `->delay(...)`. Mirror `ProvisionMatchVoiceJob`/`CleanupMatchChannelJob`.
- **Broadcasting** (live scoreboard, warmup gong, server status): public `event.{id}` channel via the M5 `SceneOverride`/`ScenesUpdated` machinery, or `tournament.{id}` for match-scoped updates; `ShouldBroadcast, ShouldDispatchAfterCommit`; no private data in payloads.
- **Design system is binding (M13, `docs/design.md`):** all new UI uses semantic token utilities (no raw hex), Space Grotesk + **JetBrains Mono for machine data** (server IP/port/slots/RAM, scores, IDs), the `LiveIndicator` for running/live state, all four states (empty/loading/error/normal), responsive + focus + `prefers-reduced-motion`. **Invoke the `frontend-design` skill for any UI work** (also enforced by the PreToolUse hook on `.vue`/`.css` edits).
- Uploads (config files, icons) to Laravel Storage (`public` disk), never Base64 in the DB.
- **Gates green after every task:** `composer check` (pint --test, phpstan L8, `pest -d memory_limit=1G`) **and**, for any `.vue`/`.ts` change, `npm run lint:check && npm run format:check && npm run types:check && npm run build`.
- **i18n-gate:** every task adding `lang/de` keys includes ≥1 Feature-test assertion on a translated label; `.env.testing` has `APP_LOCALE=de`.

## M6-specific facts folded in (from the recon of the current tree — binding for this plan)

- **No `Game` model exists.** `tournaments.game_id` is a **nullable, unconstrained, fillable** FK column (`create_tournaments_table.php:14-16`, `Tournament.php:34`) referencing nothing. Task 1 builds the `games` catalog, adds the FK constraint + `belongsTo`, and adds the `game_id` field to the Filament `TournamentForm` (currently absent).
- **`GameMatch`** (`app/Modules/Tournaments/Models/GameMatch.php`, table `matches`): `tournament_id, round, bracket, position, entry1_id, entry2_id, score1, score2, winner_entry_id, status (MatchStatus), scheduled_at, lock_version, next_match_id, next_slot, loser_match_id, loser_slot, discord_channels jsonb, voice_channels jsonb`. `score1/score2/winner_entry_id/status/lock_version` are **non-fillable** (Action-only). `MatchStatus` = `Pending/Ready/Reported/Disputed/Completed` — **no Warmup/Live** (Task 11 adds `Warmup`). Relations: `tournament/entry1/entry2/winnerEntry/nextMatch/loserMatch/reports`.
- **`TournamentEntry`**: exactly one of `team_id`/`user_id` (DB check constraint), `display_name`, `seed`, `checked_in_at`, `roster_snapshot jsonb`, `status (EntryStatus)`. `scopeOwnedBy($user)`. Stats chain: `matches.winner_entry_id → tournament_entries.(team_id|user_id) → Team|User`.
- **Domain events** (all `public readonly GameMatch|Tournament $x`): `MatchReady` (dispatched in `StartTournament::handle` + `MatchProgression::apply`; listeners `CreateMatchChannelOnReady`, `NotifyRosterOnMatchReady`, `ProvisionMatchVoiceOnReady`); `MatchCompleted` (`MatchProgression::apply`; listeners `AnnounceAndCleanupOnCompleted`, `BroadcastWinnerMoment`); `TournamentStarted` (`StartTournament::handle`; `ProvisionVoiceOnStart`); `TournamentCompleted` (`MatchProgression::detectCompletion`; `CleanupVoiceOnCompleted`). Wired in `AppServiceProvider::configureEventListeners()` (~lines 167-173).
- **Contract pattern to mirror EXACTLY** (`MumbleClient`): contract in `Contracts/`, `Http<X>Client` reads `config('services.<x>')` + `Http::retry(3, backoff, transientOnly, throw:false)` (transient = `ConnectionException` or 429/5xx; backoff from `Retry-After` else 100ms), `Fake<X>Client` with public state arrays + `assert*` helpers + a `fake<X>()` helper in `tests/Pest.php`, bound via `$this->app->bind(<X>Client::class, fn () => new Http<X>Client(config...))` in `AppServiceProvider`.
- **Provisioning to mirror**: `ProvisionMatchVoiceOnReady` (listener, `ShouldQueue`) → `ProvisionMatchVoiceJob` (`ShouldQueue`, `handle(MumbleClient $c)`, idempotent guard `if ($match->voice_channels !== null) return;`, writes jsonb back). Cleanup: `AnnounceAndCleanupOnCompleted` dispatches `CleanupMatchChannelJob::dispatch($id)->delay(now()->addMinutes(config(...)))`. `MatchEmbed::welcome(GameMatch)` builds the Discord embed + `MumbleJoinLink::for(...)` (`mumble://host:port/path`) — M6 adds a `PelicanJoinLink` + a server section.
- **Infoscreen scene mechanism (M5)**: add `SceneType` case → `ScenePayload::dataFor()` match arm + a `serversData()` builder → register in `resources/js/pages/Screen/Show.vue`'s `sceneComponents` + a `SceneServers.vue`. `LiveIndicator` props `{ variant?: 'live'|'ok'|'warn'|'down'; label?: string; pulse?: boolean }`.
- **Filament**: resource delegates `form(Schema)`/`table(Table)` to `Schemas/<X>Form` + `Tables/<X>Table`; header/row actions `->authorize('verb')->requiresConfirmation()->action(fn ($r) => try { app(Action::class)->handle(...) } catch (ModuleException $e) { Notification::make()->title(__($e->translationKey))->danger()->send(); return; } Notification::make()->success()->send();)`; external deeplink column via `->url(fn ($r) => ..., shouldOpenInNewTab: true)->copyable()`; **register the module** in `AdminPanelProvider` via `->discoverResources(in: app_path('Modules/GameServers/Filament/Resources'), for: 'App\\Modules\\GameServers\\Filament\\Resources')`.
- **Config**: `config/services.php` gets a `pelican` block (`panel_url`, `application_token`, `client_token`, `node_id`); `.env.example` gets `PELICAN_*` keys; `routes/console.php` gets a status-poll/cleanup schedule. `docs/prod-test.md` (M5) is extended with a Pelican section; `docs/pelican-setup.md` is new (6.1).

---

## Task ordering & dependencies

```
Foundation:  T1 (games catalog) ──┐
Contract:    T2 (PelicanClient + Fake) ──┐
Provision:   T3 (server_links) → T4 (provision job + cleanup) → T5 (match embed + join info)
UI:          T6 (Filament ServerResource) → T7 (participant list + SceneServers)
Stats:       T8 (leaderboard)                 (needs only existing tournament data)
Presets:     T9 (presets/settings) → T10 (guardrails)          (T9/T10 refine T4's provisioning)
Match gate:  T11 (warmup & go)                (extends M3 match lifecycle)
Telemetry:   T12 (CS2 live stats)             (recipe; needs T7 scene tech)
Infra:       T13 (Pelican+Wings compose, egg SPIKE, docs)   (external infra; app tasks use the Fake regardless)
```
`T13` is the real-world **spike** (does Pelican+Wings run; do CS1.6/UT2004 eggs build) — its outcome only decides one-click vs. manual mode for specific games; it does **not** block the coded tasks (all fake-tested). Recommended execution: T1 → T2 → T3 → T4 → T5 → T6 → T7 → T8 → T9 → T10 → T11 → T12, with T13 done against real infra whenever available (ideally early, to fill real egg IDs into the T1 seeder). Each task ends green on all gates and is committed + pushed.

> **Verify first (2026):** before writing `HttpPelicanClient` (T2) and the compose/eggs (T13), check the **current Pelican Panel docs** (Application API vs. Client API, server-create payload, power-action endpoint, allocation/egg model) and the current `dunglas/frankenphp` + Pelican/Wings images via context7/WebFetch. Pin whatever latest stable resolves; note deviations in the commit body. Pelican's API differs from its Pterodactyl ancestor — do not assume Pterodactyl payloads.

---

# Task 1: `Game` catalog (games table, model, seeder, tournament FK + Filament)

**Files:**
- Create: `database/migrations/2026_07_16_200000_create_games_table.php`, `database/migrations/2026_07_16_200100_constrain_tournaments_game_id.php`
- Create: `app/Modules/Games/Enums/…` (none needed yet), `app/Modules/Games/Domain/ServerConfig.php` (readonly DTO), `app/Modules/Games/Casts/ServerConfigCast.php`, `app/Modules/Games/Models/Game.php`
- Create: `database/factories/GameFactory.php`, `database/seeders/GamesSeeder.php`
- Create: `app/Modules/Games/Filament/Resources/Games/{GameResource, Schemas/GameForm, Tables/GamesTable, Pages/{ListGames,CreateGame,EditGame}}.php`, `app/Modules/Games/Policies/GamePolicy.php`
- Modify: `app/Modules/Tournaments/Models/Tournament.php` (add `game(): BelongsTo`), `app/Modules/Tournaments/Filament/Resources/Tournaments/Schemas/TournamentForm.php` (add `game_id` Select), `app/Providers/Filament/AdminPanelProvider.php` (discover Games resources), `app/Providers/AppServiceProvider.php` (register policy), `database/seeders/DatabaseSeeder.php` (call GamesSeeder), `lang/de/games.php` (create)
- Test: `tests/Unit/Games/GameTest.php`, `tests/Feature/Games/GameResourceAccessTest.php`

**Interfaces:**
- Produces:
  - `Game`: `id, name, slug (unique), icon_path nullable, min_team_size int default 1, max_team_size int default 1, pelican_egg_id string nullable, default_server_config (ServerConfigCast) nullable, timestamps`. Fillable: `name, slug, icon_path, min_team_size, max_team_size, pelican_egg_id` (descriptive). **`default_server_config` non-fillable typed jsonb** (set via cast/Filament override). `tournaments(): HasMany`.
  - `ServerConfig` (readonly): `?int $maxPlayers, ?string $map, ?string $password, array<string,scalar> $extra` (default `[]`). `toArray()`/`fromArray()` (drop nulls/empties). This is the typed preset shape reused by T9.
  - `ServerConfigCast implements CastsAttributes` — jsonb ⇄ `ServerConfig`; `get()` tolerates `null`/`[]` → empty config; `set()` `json_encode(...,JSON_THROW_ON_ERROR)` with an `is_array`/`instanceof` guard (mirror `SceneConfigCast`).
  - `Tournament::game(): BelongsTo` (nullable).
  - `GamePolicy`: `viewAny/create/update/delete => isOrga()`.
  - `GamesSeeder` seeds a few real games (name/slug/team sizes) with placeholder `pelican_egg_id` (filled after the T13 spike).

- [ ] **Step 1: Failing tests** — `GameTest`: factory row with `default_server_config` cast to `ServerConfig`, `slug` unique (second insert → `QueryException` 23505), `tournaments()` relation resolves; a German label assertion (`lang/de/games.php`). `GameResourceAccessTest`: participant `GET /admin/games` → 403; orga → 200 and the list renders a seeded game name.
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Games tests/Feature/Games` → FAIL.
- [ ] **Step 3: Implement** — migrations (games table below; the FK migration adds `$table->foreign('game_id')->references('id')->on('games')->nullOnDelete()` to `tournaments`), DTO, cast, model, factory, seeder (+ `DatabaseSeeder` call), the `Game` `belongsTo` on Tournament, the `game_id` Filament Select (`->relationship('game','name')->searchable()->preload()`), `GameResource` (+ typed `default_server_config` via a `Repeater`/typed fields, non-fillable persisted through Create/Edit overrides like Catering), policy registration, discovery entry, `lang/de/games.php`.
```php
// games migration up()
Schema::create('games', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('icon_path')->nullable();
    $table->unsignedInteger('min_team_size')->default(1);
    $table->unsignedInteger('max_team_size')->default(1);
    $table->string('pelican_egg_id')->nullable();
    $table->jsonb('default_server_config')->nullable();
    $table->timestamps();
});
```
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Games tests/Feature/Games && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_16_2000* app/Modules/Games database/factories/GameFactory.php database/seeders/GamesSeeder.php database/seeders/DatabaseSeeder.php app/Modules/Tournaments/Models/Tournament.php app/Modules/Tournaments/Filament/Resources/Tournaments/Schemas/TournamentForm.php app/Providers/Filament/AdminPanelProvider.php app/Providers/AppServiceProvider.php lang/de/games.php tests/Unit/Games tests/Feature/Games
git commit -m "feat(games): games catalog with typed server-config, tournament FK and filament resource"
```

# Task 2: `PelicanClient` contract + `HttpPelicanClient` + `FakePelicanClient` + config

**Files:**
- Create: `app/Modules/GameServers/Contracts/PelicanClient.php`, `app/Modules/GameServers/Domain/{PelicanServer,PowerAction}.php`, `app/Modules/GameServers/HttpPelicanClient.php`, `app/Modules/GameServers/Testing/FakePelicanClient.php`, `app/Modules/GameServers/Enums/ServerState.php`
- Modify: `config/services.php` (add `pelican` block), `.env.example` (add `PELICAN_*`), `app/Providers/AppServiceProvider.php` (bind `PelicanClient` → `HttpPelicanClient`), `tests/Pest.php` (add `fakePelican()` helper + `Feature/GameServers`/`Unit/GameServers` to the `preventStrayRequests()` group)
- Test: `tests/Unit/GameServers/{HttpPelicanClientTest,FakePelicanClientTest}.php`

**Interfaces:**
- Produces:
  - `ServerState: string { Provisioning='provisioning', Installing='installing', Running='running', Stopped='stopped', Failed='failed' }` + `label()`.
  - `PowerAction: string { Start='start', Stop='stop', Restart='restart', Kill='kill' }`.
  - `PelicanServer` (readonly): `string $id, ServerState $state, ?string $address, ?int $port, array<string,mixed> $meta`.
  - `PelicanClient`: `createServer(string $eggId, array $config, ?string $nodeId = null): PelicanServer`; `getServer(string $serverId): PelicanServer`; `powerAction(string $serverId, PowerAction $action): void`; `deleteServer(string $serverId): void`.
  - `HttpPelicanClient` — constructor `(string $panelUrl, string $applicationToken, ?string $nodeId)`; uses `Http::withToken($applicationToken)->acceptJson()->retry(3, …, transientOnly, throw:false)` (mirror `HttpMumbleClient` retry/backoff); maps Pelican's server JSON → `PelicanServer` (**verify the exact Application-API payload/endpoints against current Pelican docs**).
  - `FakePelicanClient implements PelicanClient` — `public array $created = []`, `public array $powerActions = []`, `public array $deleted = []`, an in-memory server store with a settable `nextState`/per-id state so `getServer` can simulate provisioning→running; assertion helpers `assertServerCreated(?string $eggId = null)`, `assertPowerAction(string $serverId, PowerAction $action)`, `assertServerDeleted(string $serverId)`, `assertNothingCreated()`.

- [ ] **Step 1: Failing tests** — `FakePelicanClientTest`: `createServer` records + returns a `PelicanServer` (state Provisioning), `getServer` reflects a settable state transition to Running, `powerAction`/`deleteServer` recorded + asserted, `assertNothingCreated` on a fresh fake. `HttpPelicanClientTest`: with `Http::fake()` (allowed here — it's the client's own unit test, not a stray request), `createServer` POSTs to the panel with the bearer token and parses the response into a `PelicanServer`; a 500 is retried; a 404 is not.
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/GameServers` → FAIL.
- [ ] **Step 3: Implement** — enums, DTOs, contract, Http impl (verify Pelican API first), Fake, config block, env keys, binding, `fakePelican()` helper + preventStrayRequests dirs.
```php
// config/services.php — add
'pelican' => [
    'panel_url' => env('PELICAN_PANEL_URL'),
    'application_token' => env('PELICAN_APPLICATION_TOKEN'),
    'client_token' => env('PELICAN_CLIENT_TOKEN'),
    'node_id' => env('PELICAN_NODE_ID'),
],
```
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/GameServers && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Contracts app/Modules/GameServers/Domain app/Modules/GameServers/HttpPelicanClient.php app/Modules/GameServers/Testing app/Modules/GameServers/Enums config/services.php .env.example app/Providers/AppServiceProvider.php tests/Pest.php tests/Unit/GameServers
git commit -m "feat(gameservers): PelicanClient contract, http client, fake and config"
```

# Task 3: `server_links` migration + `ServerLink` model + match relation

**Files:**
- Create: `database/migrations/2026_07_16_210000_create_server_links_table.php`, `database/migrations/2026_07_16_210100_add_server_link_id_to_matches.php`
- Create: `app/Modules/GameServers/Enums/ServerLinkStatus.php`, `app/Modules/GameServers/Domain/JoinInfo.php` (readonly DTO), `app/Modules/GameServers/Casts/JoinInfoCast.php`, `app/Modules/GameServers/Models/ServerLink.php`, `database/factories/ServerLinkFactory.php`
- Modify: `app/Modules/Tournaments/Models/GameMatch.php` (add `serverLink(): BelongsTo`, `server_link_id` fillable-as-descriptive? — see note), `lang/de/gameservers.php` (create — enum labels)
- Test: `tests/Unit/GameServers/ServerLinkTest.php`

**Interfaces:**
- Produces:
  - `ServerLinkStatus: string { Pending='pending', Provisioning='provisioning', Ready='ready', Failed='failed', Stopped='stopped' }` + `label()`.
  - `JoinInfo` (readonly): `?string $address, ?int $port, ?string $password, ?string $connectString` (e.g. `steam://connect/ip:port`). `toArray()`/`fromArray()`.
  - `ServerLink`: `id, match_id nullable, tournament_id nullable, pelican_server_id nullable, join_info (JoinInfoCast) nullable, status (ServerLinkStatus) default Pending, manual bool default false, timestamps`. Non-fillable: `pelican_server_id, join_info, status` (Job/Action-only); fillable: `match_id, tournament_id, manual`. Relations `match(): BelongsTo(GameMatch)`, `tournament(): BelongsTo`. Index `(match_id)`, `(tournament_id)`.
  - `GameMatch::serverLink(): BelongsTo` via `server_link_id` (nullable FK added to `matches`).

- [ ] **Step 1: Failing test** — `ServerLinkTest`: factory row; `join_info` casts to `JoinInfo`; `status` enum German label; `match`/`tournament` relations resolve; `pelican_server_id`/`status`/`join_info` are NOT in `$fillable` (getFillable assertion).
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/GameServers/ServerLinkTest.php` → FAIL.
- [ ] **Step 3: Implement** — migrations (`server_links` per below; `matches` gets `$table->foreignId('server_link_id')->nullable()->constrained()->nullOnDelete()`), enum, DTO, cast, model, factory, `serverLink` relation, enum labels.
```php
// server_links migration up()
Schema::create('server_links', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('match_id')->nullable()->constrained('matches')->cascadeOnDelete();
    $table->foreignId('tournament_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('pelican_server_id')->nullable();
    $table->jsonb('join_info')->nullable();
    $table->string('status')->default('pending');
    $table->boolean('manual')->default(false);
    $table->timestamps();
    $table->index('match_id');
    $table->index('tournament_id');
});
```
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/GameServers && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_16_2100* app/Modules/GameServers/Enums/ServerLinkStatus.php app/Modules/GameServers/Domain/JoinInfo.php app/Modules/GameServers/Casts/JoinInfoCast.php app/Modules/GameServers/Models/ServerLink.php database/factories/ServerLinkFactory.php app/Modules/Tournaments/Models/GameMatch.php lang/de/gameservers.php tests/Unit/GameServers/ServerLinkTest.php
git commit -m "feat(gameservers): server_links model with typed join-info and match relation"
```

# Task 4: Provision-on-ready job + status poll + cleanup + manual fallback

**Files:**
- Create: `app/Modules/GameServers/Listeners/ProvisionMatchServerOnReady.php`, `app/Modules/GameServers/Jobs/{ProvisionMatchServerJob,PollServerStatusJob,CleanupServerJob}.php`, `app/Modules/GameServers/Listeners/CleanupServersOnCompleted.php`, `app/Modules/GameServers/Actions/{SetManualJoinInfo,DeprovisionServer}.php`, `app/Modules/GameServers/Exceptions/GameServerException.php`, `app/Modules/GameServers/Events/ServerLinkUpdated.php`
- Modify: `app/Providers/AppServiceProvider.php` (wire listeners), `routes/console.php` (optional stale-server sweep)
- Test: `tests/Feature/GameServers/{ProvisionMatchServerTest,CleanupServersTest,ManualJoinInfoTest}.php`

**Interfaces:**
- Consumes: `MatchReady`, `TournamentCompleted`, `PelicanClient`, `Game` (`pelican_egg_id`, `default_server_config`), `ServerLink`.
- Produces:
  - `ProvisionMatchServerOnReady implements ShouldQueue` — on `MatchReady`, if the match's tournament has a `Game` with a `pelican_egg_id`, `ProvisionMatchServerJob::dispatch($match->id)` (else no-op → manual mode).
  - `ProvisionMatchServerJob implements ShouldQueue` — idempotent (`if ($match->server_link_id !== null) return;`); create/attach a `ServerLink` (status Provisioning), resolve the effective config (`Game::default_server_config`; T9 layers presets), `PelicanClient::createServer(eggId, config, nodeId)`, store `pelican_server_id` + `match.server_link_id`, then `PollServerStatusJob::dispatch($serverLinkId)->delay(now()->addSeconds(10))`.
  - `PollServerStatusJob implements ShouldQueue` — `getServer`; on Running → write `JoinInfo` + status Ready + dispatch `ServerLinkUpdated` (updates match page + Discord embed, Task 5) ; on still-installing → re-dispatch delayed (bounded retries via `$tries`/`backoff`); on Failed/exhausted → status Failed (surfaces manual fallback in the UI).
  - `CleanupServersOnCompleted implements ShouldQueue` — on `TournamentCompleted`, dispatch `CleanupServerJob` (delayed) for each `ServerLink` of the tournament's matches + any tournament-level link; `CleanupServerJob` calls `PelicanClient::deleteServer` + marks status Stopped (idempotent).
  - `SetManualJoinInfo::handle(GameMatch $match, JoinInfo $info, User $actor): ServerLink` — orga/helper fallback; authorize `isHelper()`; upsert a `manual=true` ServerLink with status Ready; dispatch `ServerLinkUpdated`. `DeprovisionServer::handle(ServerLink $link): void` — orga stop/delete.
  - `GameServerException` with `translationKey`s. `ServerLinkUpdated` (`Dispatchable`, carries `ServerLink`).

- [ ] **Step 1: Failing tests** — `ProvisionMatchServerTest` (`fakePelican()`; a `MatchReady` for a tournament whose game has an egg creates a ServerLink + calls `createServer`; poll transitions Provisioning→Ready and writes `JoinInfo`; re-running the job is a no-op; a game with no `pelican_egg_id` provisions nothing → manual mode). `CleanupServersTest` (`TournamentCompleted` deletes each match's server via the fake, idempotent). `ManualJoinInfoTest` (`SetManualJoinInfo` by a helper writes a `manual` ServerLink Ready; a participant → 403).
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/GameServers` → FAIL.
- [ ] **Step 3: Implement** — listeners (`Event::listen(MatchReady::class, ProvisionMatchServerOnReady::class)`, `Event::listen(TournamentCompleted::class, CleanupServersOnCompleted::class)`), jobs (idempotent, queued, poll-with-retry), actions, exception, event.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/GameServers && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Listeners app/Modules/GameServers/Jobs app/Modules/GameServers/Actions app/Modules/GameServers/Exceptions app/Modules/GameServers/Events app/Providers/AppServiceProvider.php routes/console.php tests/Feature/GameServers
git commit -m "feat(gameservers): provision-on-ready job with status poll, cleanup and manual fallback"
```

# Task 5: Join info on the match page + Discord embed

**Files:**
- Create: `app/Modules/GameServers/Support/PelicanJoinLink.php`, `app/Modules/GameServers/Listeners/UpdateMatchSurfacesOnServerReady.php`
- Modify: `app/Modules/Discord/Support/MatchEmbed.php` (append a server section when a Ready `ServerLink` exists), the tournament/match page controller (`app/Modules/Tournaments/Http/TournamentPageController.php`) + `resources/js/pages/Tournaments/Show.vue` (show server join info + connect button, Signalpult: `font-mono` for address/port), `app/Providers/AppServiceProvider.php` (wire listener), `lang/de/gameservers.php`
- Test: `tests/Feature/GameServers/MatchServerSurfaceTest.php`

**Interfaces:**
- Consumes: `ServerLinkUpdated`, `ServerLink`/`JoinInfo`, `DiscordClient` (embed update), `MatchEmbed`.
- Produces: `PelicanJoinLink::for(JoinInfo $info): string` (a `steam://connect/…` or copyable `address:port`); `UpdateMatchSurfacesOnServerReady implements ShouldQueue` — on `ServerLinkUpdated` for a match link, re-post/patch the Discord match embed (via `DiscordOutboxGuard` dedup) and (the page reads it on next load / via a `tournament.{id}` broadcast). The match DTO gains `server: { address, port, connectString, status } | null` (mono-rendered on the page with a "Verbinden" button + copy).

- [ ] **Step 1: Failing test** — `MatchServerSurfaceTest`: a Ready match `ServerLink` surfaces in the tournament-page match DTO (`server.address`/`server.port`); `fakeDiscord()` — the embed is (re)sent once containing the connect string; German label asserted.
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — join link helper, listener + wiring, embed extension, controller DTO + Vue rendering (reuse `LiveIndicator` for server status: Ready → `ok`, Provisioning → `warn`/pulse, Failed → `down`).
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/GameServers tests/Feature/Discord && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Support/PelicanJoinLink.php app/Modules/GameServers/Listeners/UpdateMatchSurfacesOnServerReady.php app/Modules/Discord/Support/MatchEmbed.php app/Modules/Tournaments/Http/TournamentPageController.php resources/js/pages/Tournaments/Show.vue app/Providers/AppServiceProvider.php lang/de/gameservers.php tests/Feature/GameServers/MatchServerSurfaceTest.php
git commit -m "feat(gameservers): surface server join info on the match page and discord embed"
```

# Task 6: Filament `ServerResource` (overview, power actions, panel deeplink)

**Files:**
- Create: `app/Modules/GameServers/Filament/Resources/ServerLinks/{ServerLinkResource, Schemas/ServerLinkForm, Tables/ServerLinksTable, Pages/{ListServerLinks,EditServerLink}}.php`, `app/Modules/GameServers/Policies/ServerLinkPolicy.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover GameServers resources), `app/Providers/AppServiceProvider.php` (register policy), `lang/de/gameservers.php`
- Test: `tests/Feature/GameServers/ServerResourceAccessTest.php`

**Interfaces:**
- Produces: `/admin/server-links` — table (match/tournament, `pelican_server_id` mono, status badge, `join_info.address` mono, a **Pelican-panel deeplink** column `->url(fn ($r) => rtrim(config('services.pelican.panel_url'),'/')."/server/{$r->pelican_server_id}", shouldOpenInNewTab: true)`); header/row **power actions** Start/Stop/Restart (`->authorize('power')`, `->requiresConfirmation()`, call `PelicanClient::powerAction` via an action class, catch `GameServerException` → `Notification::danger`); a Delete/Deprovision action calling `DeprovisionServer`. `ServerLinkPolicy`: `viewAny/power/update/delete => isOrga()`.

- [ ] **Step 1: Failing test** — `ServerResourceAccessTest`: participant `GET /admin/server-links` → 403; orga → 200 + list renders a seeded link's status label; a Livewire test that the Start power action calls `PelicanClient::powerAction(Start)` (via `fakePelican()`).
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — resource + power actions + deeplink + discovery + policy.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/GameServers && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Filament app/Modules/GameServers/Policies/ServerLinkPolicy.php app/Providers/Filament/AdminPanelProvider.php app/Providers/AppServiceProvider.php lang/de/gameservers.php tests/Feature/GameServers/ServerResourceAccessTest.php
git commit -m "feat(gameservers): filament server overview with power actions and panel deeplink"
```

# Task 7: Participant server list + infoscreen `SceneServers`

**Files:**
- Create: `app/Modules/GameServers/Http/GameServerPageController.php`, `resources/js/pages/Servers/Index.vue`, `app/Modules/GameServers/Support/ServerListProjection.php`, `resources/js/components/scenes/SceneServers.vue`, `resources/js/types/gameservers.ts`
- Modify: `routes/web.php` (public `GET /events/{event:slug}/servers`), `app/Modules/Infoscreen/Enums/SceneType.php` (add `Servers='servers'`), `app/Modules/Infoscreen/Support/ScenePayload.php` (`serversData()` arm), `resources/js/pages/Screen/Show.vue` (register `SceneServers`), `lang/de/{gameservers,infoscreen}.php`
- Test: `tests/Feature/GameServers/ServerPageTest.php`, `tests/Feature/Infoscreen/SceneServersPayloadTest.php`

**Interfaces:**
- Produces: route `servers.index` → `Servers/Index` with the event's active servers (DTO: `id, game, matchLabel, address, port, connectString, status, slotsUsed?, slotsMax?`) + `labels`; `ServerListProjection::forEvent(Event): array` shared by the page + the scene. `SceneType::Servers` + `ScenePayload::serversData()` → `{ servers: [...] }`; `SceneServers.vue` beamer-scale (mono address/port/slots, `LiveIndicator` per status). Signalpult throughout; empty/loading states.

- [ ] **Step 1: Failing tests** — `ServerPageTest`: public GET renders `Servers/Index` with German `labels.title` and a Ready server's mono `address`; draft event → 404. `SceneServersPayloadTest`: a `servers` scene yields `data.servers` for the event's Ready links.
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — controller + projection + route + Vue page + scene (enum/payload/registry/component) + labels.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/GameServers tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Http/GameServerPageController.php app/Modules/GameServers/Support/ServerListProjection.php resources/js/pages/Servers resources/js/components/scenes/SceneServers.vue resources/js/types/gameservers.ts routes/web.php app/Modules/Infoscreen/Enums/SceneType.php app/Modules/Infoscreen/Support/ScenePayload.php resources/js/pages/Screen/Show.vue lang/de/gameservers.php lang/de/infoscreen.php tests/Feature/GameServers/ServerPageTest.php tests/Feature/Infoscreen/SceneServersPayloadTest.php
git commit -m "feat(gameservers): participant server list and infoscreen SceneServers"
```

# Task 8: Stats query layer + leaderboard page (+ badges)

**Files:**
- Create: `app/Modules/Stats/Support/{LeaderboardQuery,BadgeCalculator}.php`, `app/Modules/Stats/Http/StatsPageController.php`, `resources/js/pages/Stats/Leaderboard.vue`, `resources/js/types/stats.ts`
- Modify: `routes/web.php` (public `GET /stats/leaderboard`), `lang/de/stats.php` (create)
- Test: `tests/Unit/Stats/LeaderboardQueryTest.php`, `tests/Feature/Stats/LeaderboardPageTest.php`

**Interfaces:**
- Consumes: `matches.winner_entry_id → tournament_entries.(team_id|user_id)`, `tournaments`, `Team`/`User`.
- Produces: `LeaderboardQuery::topEntrants(int $limit = 25): array` — cross-event aggregate per competitor (user or team): `wins` (count of matches won), `tournamentWins` (count of `tournaments.winner_entry_id` mapping to them), `participations` (distinct tournaments entered), `podiums`. Pure query, no new tables (roadmap 6.5). `BadgeCalculator::for(competitorId, kind): string[]` — `first_win`, `hattrick` (3 match wins in one tournament), `veteran` (≥3 events participated) as computed values. `StatsPageController::leaderboard()` → `Stats/Leaderboard` with rows + `labels`. Signalpult page (mono for counts/ranks, badges as tokened `Badge`s).

- [ ] **Step 1: Failing tests** — `LeaderboardQueryTest`: seed 2 events with tournaments/matches/entries (solo + team), assert wins/participations/tournamentWins aggregate correctly across events and map to the right user/team; a competitor with 3 wins in one tournament gets `hattrick`; ≥3 events → `veteran`. `LeaderboardPageTest`: public GET renders `Stats/Leaderboard` with German `labels.title` and the top entrant's win count (mono).
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Stats tests/Feature/Stats` → FAIL.
- [ ] **Step 3: Implement** — query, badge calculator, controller + route + Vue page + labels.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Stats tests/Feature/Stats && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Stats resources/js/pages/Stats resources/js/types/stats.ts routes/web.php lang/de/stats.php tests/Unit/Stats tests/Feature/Stats
git commit -m "feat(stats): cross-event leaderboard query, computed badges and page"
```

# Task 9: Server presets & settings model (one effective config)

**Files:**
- Create: `app/Modules/Games/Domain/ServerPreset.php` (readonly), `app/Modules/GameServers/Support/EffectiveConfig.php`, `app/Modules/GameServers/Actions/UploadServerConfig.php`
- Modify: `app/Modules/Games/Models/Game.php` (a `presets` list on the typed config, or a `server_presets` jsonb column — migration), `app/Modules/Games/Filament/Resources/Games/Schemas/GameForm.php` (preset repeater + "settings form OR upload" mode), `app/Modules/GameServers/Jobs/ProvisionMatchServerJob.php` (use `EffectiveConfig::resolve`), `lang/de/games.php`
- Create: `database/migrations/2026_07_16_220000_add_server_presets_to_games.php`
- Test: `tests/Unit/GameServers/EffectiveConfigTest.php`, `tests/Feature/GameServers/ServerConfigUploadTest.php`

**Interfaces:**
- Produces: per-game one-click **presets** (`ServerPreset`: `key,name,ServerConfig`) in `games.server_presets` (typed jsonb). `EffectiveConfig::resolve(Game $game, ?string $presetKey, ?string $uploadedPath): array` — resolves **exactly one** effective config: a chosen preset (form mode) OR an uploaded config file (`UploadServerConfig` stores it to the `public` disk, no Base64) — never both; the provision job feeds the result to `PelicanClient::createServer`. Filament lets the orga pick the mode (Nitrado/ShockByte-style form vs. upload). Roadmap 6.6: "genau eine Config auf dem Server ausgeführt (eine Wahrheit)."

- [ ] **Step 1: Failing tests** — `EffectiveConfigTest`: preset mode yields the preset's config; upload mode yields the uploaded file's parsed config; supplying both throws (`GameServerException`); neither → the game default. `ServerConfigUploadTest`: `UploadServerConfig` stores to Storage (asserted path, not Base64) and is used by the provision job (`fakePelican()` receives the resolved config).
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — preset DTO + migration + Filament preset UI/mode, `EffectiveConfig`, upload action, provision-job wiring.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/GameServers tests/Feature/GameServers && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Games/Domain/ServerPreset.php app/Modules/GameServers/Support/EffectiveConfig.php app/Modules/GameServers/Actions/UploadServerConfig.php app/Modules/Games/Models/Game.php app/Modules/Games/Filament/Resources/Games/Schemas/GameForm.php app/Modules/GameServers/Jobs/ProvisionMatchServerJob.php database/migrations/2026_07_16_220000_add_server_presets_to_games.php lang/de/games.php tests/Unit/GameServers/EffectiveConfigTest.php tests/Feature/GameServers/ServerConfigUploadTest.php
git commit -m "feat(gameservers): server presets + config-upload resolving to one effective config"
```

# Task 10: Resource guardrails (RAM estimate, caps, concurrency)

**Files:**
- Create: `app/Modules/GameServers/Support/ResourceEstimate.php`, `app/Modules/GameServers/Support/GuardrailPolicy.php`
- Modify: `app/Modules/GameServers/Jobs/ProvisionMatchServerJob.php` + `app/Modules/GameServers/Actions/SetManualJoinInfo.php` (enforce guardrails before create), `config/services.php` (pelican caps: `max_ram_mb`, `max_slots`, `max_servers_per_user`), `resources/js/pages/Servers/Index.vue` + the match page (show the RAM estimate before start), `lang/de/gameservers.php`
- Test: `tests/Unit/GameServers/GuardrailPolicyTest.php`, `tests/Feature/GameServers/GuardrailEnforcementTest.php`

**Interfaces:**
- Produces: `ResourceEstimate::for(Game $game, array $config): int` (estimated RAM MB from slots/config). `GuardrailPolicy::assertWithinLimits(Game, array $config, User $requester): void` — throws `GameServerException` if the estimate exceeds the per-instance cap, slots exceed the cap, or the requester already has ≥ `max_servers_per_user` running. **Enforced in the provisioning Job/Action, not only the UI** (roadmap 6.7). The estimate is shown pre-start in the UI.

- [ ] **Step 1: Failing tests** — `GuardrailPolicyTest`: over-RAM config throws; over-slots throws; a user at the concurrent-server cap throws; within limits passes. `GuardrailEnforcementTest`: `ProvisionMatchServerJob` refuses (no `createServer` call on the fake) when a guardrail is exceeded, marking the link Failed.
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — estimate + guardrail policy + job/action enforcement + config caps + UI estimate display.
- [ ] **Step 4: Green + gates** — `…/GameServers && composer check && npm run … build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Support/ResourceEstimate.php app/Modules/GameServers/Support/GuardrailPolicy.php app/Modules/GameServers/Jobs/ProvisionMatchServerJob.php app/Modules/GameServers/Actions/SetManualJoinInfo.php config/services.php resources/js/pages/Servers/Index.vue resources/js/pages/Tournaments/Show.vue lang/de/gameservers.php tests/Unit/GameServers/GuardrailPolicyTest.php tests/Feature/GameServers/GuardrailEnforcementTest.php
git commit -m "feat(gameservers): resource guardrails (ram estimate, caps, per-user concurrency) enforced in the job"
```

# Task 11: Warmup & Go (WARMUP → LIVE match gate + gong)

**Files:**
- Modify: `app/Modules/Tournaments/Enums/MatchStatus.php` (add `Warmup='warmup'`), `database/migrations/2026_07_16_230000_add_warmup_to_matches.php` (nullable `warmup_started_at`), `app/Modules/Tournaments/Models/GameMatch.php`
- Create: `app/Modules/Tournaments/Actions/{EnterWarmup,GoLive}.php`, `app/Modules/Tournaments/Events/MatchWentLive.php`, `app/Modules/Infoscreen/Listeners/GongOnMatchLive.php` (broadcast a gong `SceneOverride`), `resources/js/components/scenes/SceneGong.vue` (or reuse announcement), match-page "Go" control
- Modify: `app/Providers/AppServiceProvider.php` (wire), `resources/js/pages/Tournaments/Show.vue`, `lang/de/tournaments.php`
- Test: `tests/Feature/Tournaments/WarmupGoTest.php`

**Interfaces:**
- Produces: a spielagnostic gate — a `Ready` match can `EnterWarmup` (auto on server-ready or manual); `GoLive::handle(GameMatch, User $actor)` (orga/helper OR "all rosters ready") flips Warmup→Live-ish and dispatches `MatchWentLive`, which triggers a **gong** beamer moment (M5 `SceneOverride`) and (CS2, T12) can be enforced server-side via MatchZy. Uses the M3 match lifecycle; no new bracket concept. Authorize `isHelper()` for the manual Go.

- [ ] **Step 1: Failing test** — `WarmupGoTest`: a helper `GoLive` on a warmup match dispatches `MatchWentLive` + a gong `SceneOverride` on `event.{id}`; a participant → 403; the match status/warmup timestamp transitions correctly.
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — enum + migration + actions + event + gong listener/scene + match-page control + wiring.
- [ ] **Step 4: Green + gates** — `…/Tournaments tests/Feature/Infoscreen && composer check && npm run … build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Tournaments/Enums/MatchStatus.php database/migrations/2026_07_16_230000_add_warmup_to_matches.php app/Modules/Tournaments/Models/GameMatch.php app/Modules/Tournaments/Actions/EnterWarmup.php app/Modules/Tournaments/Actions/GoLive.php app/Modules/Tournaments/Events/MatchWentLive.php app/Modules/Infoscreen/Listeners/GongOnMatchLive.php resources/js/components/scenes resources/js/pages/Tournaments/Show.vue app/Providers/AppServiceProvider.php lang/de/tournaments.php tests/Feature/Tournaments/WarmupGoTest.php
git commit -m "feat(tournaments): warmup-and-go match gate with beamer gong moment"
```

# Task 12: CS2 live stats (MatchZy webhook → live scoreboard + scene)

**Files:**
- Create: `app/Modules/GameServers/Http/MatchTelemetryController.php` (webhook endpoint), `app/Modules/GameServers/Support/Cs2TelemetryMapper.php`, `app/Modules/GameServers/Events/MatchScoreUpdated.php`, `resources/js/components/scenes/SceneScoreboard.vue`
- Modify: `routes/web.php` (signed/token-guarded `POST /api/telemetry/cs2/{serverLink}`), `app/Modules/Infoscreen/Enums/SceneType.php` (+`Scoreboard`), `ScenePayload.php`, `Screen/Show.vue`, `resources/js/pages/Tournaments/Show.vue` (live scoreboard on the match), `lang/de/gameservers.php`
- Test: `tests/Feature/GameServers/Cs2TelemetryTest.php`

**Interfaces:**
- Produces: a **token-verified** webhook that ingests MatchZy/G5API round/score events for a `ServerLink`, maps them (`Cs2TelemetryMapper`) to a normalized `{ team1, team2, score1, score2, round }`, dispatches `MatchScoreUpdated` (broadcast on `tournament.{id}` + an infoscreen `SceneScoreboard`). **Honest scope:** a per-game recipe, only where telemetry exists (roadmap 6.9) — no universal claim; the endpoint validates a per-server token and ignores unknown payloads.

- [ ] **Step 1: Failing test** — `Cs2TelemetryTest`: a valid MatchZy-shaped payload with the right token updates the match's live score + dispatches `MatchScoreUpdated`; a bad/missing token → 401/403; an unknown payload is ignored gracefully.
- [ ] **Step 2: Run red** — → FAIL.
- [ ] **Step 3: Implement** — webhook controller + token guard + mapper + broadcast event + scoreboard scene + match-page live score.
- [ ] **Step 4: Green + gates** — `…/GameServers tests/Feature/Infoscreen && composer check && npm run … build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/GameServers/Http/MatchTelemetryController.php app/Modules/GameServers/Support/Cs2TelemetryMapper.php app/Modules/GameServers/Events/MatchScoreUpdated.php resources/js/components/scenes/SceneScoreboard.vue routes/web.php app/Modules/Infoscreen/Enums/SceneType.php app/Modules/Infoscreen/Support/ScenePayload.php resources/js/pages/Screen/Show.vue resources/js/pages/Tournaments/Show.vue lang/de/gameservers.php tests/Feature/GameServers/Cs2TelemetryTest.php
git commit -m "feat(gameservers): cs2 live-stats webhook, scoreboard scene and match-page score"
```

# Task 13: Pelican + Wings infra, egg spike, deploy docs (roadmap 6.1)

**Files:**
- Modify: `compose.yml` (an optional `pelican` service under a `pelican`/`prod` profile, referencing `docker/pelican/` if a sidecar is needed; Wings runs on the game host, **documented, not in this compose**), `.env.example` (already has `PELICAN_*` from T2), `README.md` (link the setup doc), `docs/prod-test.md` (add a Pelican section mirroring the Discord one)
- Create: `docs/pelican-setup.md`
- Test: n/a (infra + docs) — a verification step instead.

**Interfaces:**
- Produces: `docs/pelican-setup.md` — Pelican Panel + Wings install, Application/Client API token creation, node registration, importing/creating Eggs (Minecraft, CS2), and mapping each `Game.pelican_egg_id` to a real egg. **SPIKE FIRST:** build + verify **CS 1.6 / UT2004** eggs from the v1 Docker images (`goldsrc-engine:cs16`, `ut2004-server`) — the outcome decides whether those games get one-click provisioning or the manual `SetManualJoinInfo` path; record the decision in `docs/pelican-setup.md` and fill the T1 seeder's `pelican_egg_id`s (or leave null → manual).

> **Verify first:** current Pelican Panel + Wings install method and Docker images; whether Pelican provides a maintained image or must be built. Note deviations in the commit body.

- [ ] **Step 1:** Stand up Pelican Panel + a Wings node (locally or on infra per `docs/pelican-setup.md`); create an Application API token; register the node; import the Minecraft + CS2 eggs.
- [ ] **Step 2 (spike):** attempt CS 1.6 + UT2004 eggs from the v1 images; record success/failure + the one-click-vs-manual decision in `docs/pelican-setup.md`.
- [ ] **Step 3:** point a dev/staging `.env` at the panel (`PELICAN_*`), run one real end-to-end provision from a match (create → poll → join info on the match page + Discord embed → cleanup); confirm the guardrails reject an over-cap config.
- [ ] **Step 4:** finalize `docs/pelican-setup.md` + the `docs/prod-test.md` Pelican section + README link; fill real `pelican_egg_id`s into the `GamesSeeder`.
- [ ] **Step 5: Commit**
```bash
git add compose.yml docs/pelican-setup.md docs/prod-test.md README.md database/seeders/GamesSeeder.php
git commit -m "docs(gameservers): pelican+wings setup, egg spike outcome and prod-test steps"
```

---

## Phase acceptance (M6)

- Feature-test provisioning flow against `FakePelicanClient` (create → poll-retry → Ready join info → cleanup, **plus** the error path → manual mode); a preset start produces exactly one effective config (form mode **and** upload mode tested); a guardrail rejects an over-cap / over-limit start (test); warmup→go flips state + fires the beamer gong; the CS2 telemetry webhook updates a live score (test) and rejects a bad token.
- Manual (T13, real infra): a Minecraft server created from the match context, join info appearing in the Discord embed **and** on the match page; leaderboard shows data from 2 test events; the egg spike outcome recorded.
- Green CI on all six gates; **all new UI verified against the Signalpult design system** (`docs/design.md`) via the `frontend-design` skill + preview; i18n-gate satisfied per task.
- Whole-branch review on **opus** (base = tag `m13`) → one consolidated fix wave → tag `m6`; close/advance the GitHub M6 milestone (#7) + board item; push to `origin`. Update the roadmap M6 section with an "Erkenntnisse M6" block (esp. the Pelican-API + egg-spike findings).

## Deferred / explicitly out of scope for M6

- Custom Docker command / non-Pelican gameservers → **M7.4** (Pelican is the standard path here; the custom-command escape hatch is M7).
- LanCache pre-seeding of vote-winner downloads → **M7.5**.
- Stretch stats (active-hours heatmap, APM where readable, VOD archive, AI auto-news) → optional, clearly after the core leaderboard (roadmap "Stats-Kür").
- Voice-multiprovider / presence / casting overlays → M8/M10 (the OBS overlay reuses M5 scene tech + M3 `BracketView`, planned there).
