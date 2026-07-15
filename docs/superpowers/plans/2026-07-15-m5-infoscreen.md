# M5 — Infoscreen (+ Helper role, notification triggers, prod deployment) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Derived just-in-time from the roadmap M5 section (`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`) and the M5 kickoff brief (`docs/superpowers/plans/2026-07-15-m5-kickoff.md`), same format as the M0/M3/M4 detail plans.

**Goal:** A beamer-grade fullscreen scene rotation for an event (`/screen/{event}`), live-steerable by orga **and helpers**, that reuses the M2/M3/M4 UI (bracket, schedule, seatmap); a winner moment on finals; three one-click notification triggers plus schedule favorites wired through the M2 bell (Discord amplifies, never replaces); show-moment + operations tiles (tombola, status, orga-ping); and a production deployment (FrankenPHP app image + compose `prod` profile).

**Architecture:** One new module `app/Modules/Infoscreen/` (scenes, the public screen page, the override broadcast, the show-moment scenes) following the established module layout (`Actions/`, `Casts/`, `Console/`, `Domain/`, `Enums/`, `Events/`, `Exceptions/`, `Filament/`, `Http/`, `Listeners/`, `Models/`, `Policies/`, `Support/`). A new **`Role::Helper`** tier lands first (Task 1) so every trigger/approval surface in M5 is multi-handed from the start. Scene data is aggregated server-side by a `ScreenController` and rotated client-side; live pushes ride the existing **public `event.{id}` Reverb channel** via a single new broadcast event `SceneOverride` (`broadcastAs 'scene.override'`), consumed by the existing `useEventChannel` composable. Notification work extends the M2 Notifications module + `DiscordChannel` carrier — the **in-app bell is the source of truth, Discord the mirror per preference**. Cross-module reuse is by controller-side DTO assembly + Laravel events; never foreign-table writes. Prod deployment adds a FrankenPHP `app` image and a compose `prod` profile (app/queue/reverb/scheduler) and folds in the Reverb `allowed_origins` lockdown + the M4 `refreshFormData` follow-up.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5 (Schema API), Inertia v2 + Vue 3 `<script setup lang="ts">` + Tailwind v4 + shadcn-vue (`resources/js/components/ui/`, Reka UI), Reverb + `@laravel/echo-vue` (`useEchoPublic` via `useEventChannel`), `bacon/bacon-qr-code` (already installed, M2), Pest 4, PostgreSQL 16, Redis, Docker Compose. New runtime dependency in Task 14 only: **FrankenPHP** (via `dunglas/frankenphp` base image — verify latest stable before pinning). No new PHP Composer package is required for scenes; confetti is a self-contained Vue component (no external JS lib — verify before adding one).

## Global Constraints

Copied verbatim from the roadmap Global Constraints + M5 kickoff binding conventions — every task's requirements implicitly include these:

- Code, comments, commits, docs in **English**; UI copy in **German** via `lang/de/*.php` (no hardcoded strings in components — pass a `labels` prop).
- **Conventional Commits** (`feat(scope): …`). TDD: failing test first wherever there is a testable behavior; frequent commits. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. **Stage only task-specific paths** (never `git add -A`).
- PHP: Pint (Laravel preset), Larastan **level 8**, no `mixed` returns in own code, **enums over magic strings**. Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- **Every authorization goes through a Policy** (registered in `AppServiceProvider::configureAuthorization()` via `Gate::policy(...)`). Never trust client-supplied user IDs — resolve the acting user from `$request->user()`; Discord users via `discord_id`.
- **Actions pattern:** one class per use case, single `handle()`, returns the domain result type. For any capacity/window/transition check: `DB::transaction` + parent-row `lockForUpdate()` **first**, then read children. Throw a module `*Exception` carrying a `translationKey`. `QueryException` catches narrowed to SQLSTATE `23505`.
- **Privilege-bearing / state fields are never `$fillable`** (`role`, `enabled`, `sort` where orga-owned, `drawn_at`, `checked_in_at`, override state, tombola winner). Set only inside Actions / `booted()` hooks / `forceFill`. **Factories bypass `$fillable`** — tests that need non-fillable fields use the factory, not `create()`.
- **Typed jsonb** via a `CastsAttributes` cast + readonly DTO, edited in Filament with **typed components** (`Repeater`/`Select`/`TextInput`), never a bare `KeyValue` (M3 insight #9 / M4 `MenuCast` precedent). Non-fillable typed fields persist via `handleRecordCreation`/`handleRecordUpdate` + `mutateFormDataBeforeFill` overrides.
- **Broadcasting:** events implement `ShouldBroadcast, ShouldDispatchAfterCommit`; public channel `new Channel('event.'.$id)`; `broadcastAs()` dot-notation; document the channel in `routes/channels.php` (comment only, no auth callback — no private data in the payload). Frontend via the existing `useEventChannel(eventId, ['.x.y'], cb)` composable.
- **Discord verstärkt, ersetzt nie:** every trigger/announcement that goes to Discord is ALSO readable/operable on the site. The **in-app notification (bell) is the truth**, the Discord DM is the mirror per the user's `notification_prefs`. New channel-wide announcements reuse the direct `DiscordClient::sendMessage()` + `DiscordOutboxGuard::once()` path (not the per-user notification carrier).
- Uploads to Laravel Storage (`storage/app/public`), never Base64 in the DB.
- External systems (Discord) only via the `DiscordClient` contract + `FakeDiscordClient` in tests; `Http::preventStrayRequests()` is global in `Feature/Discord`,`Unit/Discord`,`Feature/Voice`,`Unit/Voice`,`Feature/Lfg` — extend it to any new test dir that touches Discord.
- **Gates green after every task:** `composer check` (pint --test, phpstan L8, `pest -d memory_limit=1G`) **and** `npm run lint:check && npm run format:check && npm run types:check && npm run build`. (`composer check` runs neither Prettier nor the JS build — the frontend gate must be run explicitly for any task touching `.vue`/`.ts`.)
- **i18n-gate:** every task adding `lang/de` keys MUST include ≥1 Feature-test assertion on a translated label (`->where('labels.x', 'Deutscher Text')` for Inertia pages; `expect(Enum::Case->label())->toBe('…')` for enums). `.env.testing` already sets `APP_LOCALE=de`.

## M5-specific facts folded in (from the recon of the current tree — binding for this plan)

- **Role layer:** `app/Enums/Role.php` has `Admin`/`Orga`/`Participant` (no methods). `User::isAdmin()`, `User::isOrga()` = `in_array($role, [Admin, Orga], true)`, `User::canAccessPanel()` = `isOrga()`. `Gate::before` (in `AppServiceProvider::configureAuthorization()`) grants **admin only**. `EnsureRole` middleware (`app/Http/Middleware/EnsureRole.php`) does `match (Role::from($role)) { … }`, aliased `role:` in routes. `UserFactory` has `->admin()`/`->orga()` states; default `Role::Participant`. The `role` column is a plain `string` default `'participant'` — **adding `Helper` needs no migration**.
- **Broadcasting:** `routes/channels.php` documents `tournament.{id}` and `event.{id}` as **public, comment-only** (no auth callback). `PollUpdated` (`app/Modules/Voting/Events/PollUpdated.php`) is the `event.{id}` precedent (`ShouldBroadcast, ShouldDispatchAfterCommit`, `broadcastOn → Channel('event.'.$eventId)`, `broadcastAs → 'poll.updated'`, `broadcastWith → PollResults::for(...)`). `MatchCompleted` broadcasts on `tournament.{id}` only and carries `['tournament_id','match_id','status','winner_entry_id']` — **it does not reach `event.{id}`**, so the winner moment needs a listener that re-broadcasts a `SceneOverride` on `event.{id}`. Finals detection: `GameMatch.bracket === Bracket::Finals` and `next_match_id === null`, or `tournament.status === TournamentStatus::Finished` with a `winner_entry_id`. `useEventChannel<T>(eventId, ['.scene.override'], cb)` already exists (`resources/js/composables/useEventChannel.ts`); `configureEcho({ broadcaster: 'reverb' })` in `resources/js/app.ts`.
- **Reusable scene sources:** `resources/js/components/bracket/BracketView.vue` props `{ matches: BracketMatchDto[], myEntryId, matchStatusLabels, reportLabels, bracketLabels }` (ResizeObserver-driven, scales to container — pass `myEntryId: null` on the beamer). `BracketMatchDto` in `resources/js/types/tournaments.ts`. Schedule: `ScheduleController::show` returns `items/now/next/labels` (DTO in `resources/js/types/schedule.ts`; `now`/`next` computed by `currentItem()`/`nextItem()`). Seating: `SeatingController::index` returns `seats: SeatDto[]` (`{id,label,x,y,occupant}`); the SVG grid lives in `resources/js/pages/Event/Seating.vue` (CELL=64, viewBox from max x/y). QR: `App\Modules\Registration\Support\QrCode::svg(string $token): string` (bacon → SVG string, injected `v-html`). Matches query: `GameMatch` (table `matches`), `MatchStatus` enum (`Pending/Ready/Reported/Disputed/Completed`); "upcoming" = `status = Ready`.
- **Filament v5:** `Resource::form(Schema $schema)`/`table(Table $table)` delegate to `Schemas/<X>Form::configure()` + `Tables/<X>Table::configure()`; `use Filament\Schemas\Schema;` (NOT `Form`); form components `Filament\Forms\Components\*`; actions `Filament\Actions\*`; `Filament\Notifications\Notification`. Header actions: `->authorize('verb')->requiresConfirmation()->action(fn ($record) => try { app(Action::class)->handle(...) } catch (ModuleException $e) { Notification::make()->title(__($e->translationKey))->danger()->send(); return; } Notification::make()->success()->send();)`. Reorder-by-column: `->reorderable('sort')` on the Table (not yet used anywhere — this is its first use). Inline boolean: `Filament\Tables\Columns\ToggleColumn::make('enabled')`. Typed jsonb precedent: Catering `MenuCast`/`MenuOption`/`Repeater` + `CreateFoodOrder::handleRecordCreation` + `EditFoodOrder::mutateFormDataBeforeFill`/`handleRecordUpdate`. **New module resource folders MUST be registered** in `AdminPanelProvider` via `->discoverResources(in: app_path('Modules/Infoscreen/Filament/Resources'), for: 'App\\Modules\\Infoscreen\\Filament\\Resources')`.
- **Deployment:** No app/queue/scheduler compose services and no app Dockerfile exist; `reverb` runs on a throwaway `php:8.4-cli` image (comment already says "replace with FrankenPHP in M5.6"). `compose.yml` has no profiles. `config/reverb.php` hardcodes `'allowed_origins' => ['*']` (no env). `routes/console.php` schedules `lanomat:send-reminders`, `lanomat:sweep-discord-outbox`, `lanomat:tournament-tick`, `lanomat:prune-lfg`. `composer run dev` = `php artisan dev` (serve + queue:listen + pail + vite; **no reverb**). `lanomat:install --admin-discord-id=` migrates + promotes/creates admin. README documents dev only.
- **Notifications (M2.9) — exact shape (confirmed against `app/Modules/Notifications/`):**
  - **Bell:** `HandleInertiaRequests` shares `unreadNotifications` via `Inertia::optional(fn () => $request->user()?->unreadNotifications()->take(15)->get()->map(...))` — the bell reads `data['title']` + `data['body']`. Mark-read via `NotificationController::markAsRead` (`POST /notifications/{notification}/read`). `NotificationBell.vue` takes a `labels` prop.
  - **Notification `data` shape is exactly `['category' => string, 'title' => string, 'body' => string]`** — return this from `toDatabase(object $notifiable): array`. Add any extra keys after these three, but the bell only renders title/body.
  - **Preference gating:** `App\Modules\Notifications\Support\NotificationPreferences::wants(User $user, string $category): bool` — reads `users.notification_prefs` jsonb (`'array'` cast), **defaults to `true`** (empty prefs = all categories on). In `via(object $notifiable): array`, return `[]` to suppress. Existing categories: `registration`, `discord`. **New M5 categories to add:** `schedule` (favorites/reminders), `catering` (food-ready), `checkin`, `match` (match/server-ready), `orga_ping`.
  - **Two established shapes — pick per notification:** (a) a **database-only** user notification extends `Illuminate\Notifications\Notification`, `via()` returns `['database']` (or `[]` when the prefs category is off), `toDatabase()` returns the `category/title/body` array (see `RegistrationConfirmed`). (b) a **Discord DM** notification implements `toDiscord(object $notifiable): string` and `via()` returns `[DiscordChannel::class]` with a public `string $category` property (see `DiscordDirectMessage`). The `DiscordChannel` (`app/Modules/Discord/Channels/DiscordChannel.php`) is prefs-aware and requires `User::discord_id`. **For "bell is truth, Discord mirrors" M5 notifications: a single notification may implement BOTH `toDatabase()` and `toDiscord()` and return `via() => ['database', DiscordChannel::class]`** — `DiscordChannel::send()` guards on `method_exists(..., 'toDiscord')` + `discord_id` + `wants(user, category)`, so the database entry always lands and the Discord DM only mirrors when the category pref is on and the user is linked.
  - **Dispatch:** `$user->notify(new X(...))` for one, `Notification::send($users, new X(...))` for many.
  - **Channel-wide announcements** (not user-addressed) use `DiscordClient::sendMessage($channelId, $content)` + `DiscordOutboxGuard::once($dedupKey, $kind, fn () => …, channelId:, content:)` (insert-before-send dedup, replayed by `lanomat:sweep-discord-outbox`). Test with `FakeDiscordClient` (`assertMessageSent`/`assertDmSent`/`assertNothingSent`) bound via `app()->instance(DiscordClient::class, $fake)`; `Notification::fake()` for the bell.

---

## Task ordering & dependencies

```
Role:        T1 (5.0) — lands first, unblocks helper-operable triggers
Scenes core: T2 (5.1) → T3 (5.1 Filament) → T4 (5.2 shell + SceneOverride) → T5 (5.3 reuse) → T6 (5.3 qr+sponsors)
Live push:   T7 (5.4 winner)  needs T4;  T8 (5.5 show-now)  needs T4
Triggers:    T9 (5.7 favorites)  needs T1;  T10 (5.7 triggers)  needs T1 + T8
Show/ops:    T11 (5.8 tombola)  needs T4;  T12 (5.8 status tile)  needs T4 + T8;  T13 (5.8 orga-ping)  needs T1
Deployment:  T14 (5.6)  last — prod profile, allowed_origins lockdown, refreshFormData fix, deploy docs
```
Recommended execution order: T1 → T2 → T3 → T4 → T5 → T6 → T7 → T8 → T9 → T10 → T11 → T12 → T13 → T14. Each task ends green on all gates and is committed.

---

# Task 1: `Role::Helper` tier + policies (roadmap 5.0)

A privilege tier between `participant` and `orga`: may fire triggers/announcements, do QR check-in, and grant approvals — **no** admin panel, **no** config. `canAccessPanel()` stays `isOrga()`; the helper gets targeted `can` rules. `Gate::before` stays admin-only.

**Files:**
- Modify: `app/Enums/Role.php` (add `Helper`), `app/Models/User.php` (add `isHelper()`), `app/Http/Middleware/EnsureRole.php` (add the `Helper` case), `database/factories/UserFactory.php` (add `->helper()` state), `app/Modules/Registration/Policies/RegistrationPolicy.php` (check-in verb → `isHelper()`), `lang/de/roles.php` (create — role labels)
- Create: `lang/de/roles.php`
- Test: `tests/Unit/Identity/RoleTest.php` (extend), `tests/Feature/Registration/CheckInPolicyTest.php` (or extend the existing check-in test)

**Interfaces:**
- Consumes: `Role`, `User`.
- Produces:
  - `App\Enums\Role: string { Admin='admin', Orga='orga', Helper='helper', Participant='participant' }` + `label(): string => __('roles.'.$this->value)`.
  - `User::isHelper(): bool => in_array($this->role, [Role::Admin, Role::Orga, Role::Helper], true)`. **Semantics: helper-or-above.** `isOrga()` stays `[Admin, Orga]` (a helper is NOT an orga — no `/admin`). `canAccessPanel()` unchanged.
  - `EnsureRole` match arm: `Role::Helper => $user?->isHelper() ?? false`. Route alias `role:helper`.
  - `UserFactory::helper(): static => $this->state(['role' => Role::Helper])`.
  - Check-in policy verb (`checkIn`/`store`) now permits `isHelper()` instead of `isOrga()`.

- [ ] **Step 1: Failing tests** — extend `RoleTest`: `expect(Role::Helper->label())->toBe('Helfer')`; helper `isHelper()` true, `isOrga()` false, `isAdmin()` false; admin & orga `isHelper()` true; participant `isHelper()` false. `CheckInPolicyTest`: a `->helper()` user `can('checkIn'/'store', …)` (or the exact policy verb the M2.5 check-in uses) → true; a plain participant → false; helper still 403 on `/admin` (`->get('/admin')->assertForbidden()`).

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Identity/RoleTest.php tests/Feature/Registration/CheckInPolicyTest.php` → FAIL.

- [ ] **Step 3: Implement** — add `case Helper = 'helper';` (ordered between `Orga` and `Participant`), the `label()` method + `lang/de/roles.php` (`admin => 'Administrator', orga => 'Orga', helper => 'Helfer', participant => 'Teilnehmer'`), `isHelper()`, the `EnsureRole` arm, the factory state, and switch the check-in policy verb to `isHelper()`. **Do not** touch `Gate::before` or `canAccessPanel()`.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Identity tests/Feature/Registration && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Enums/Role.php app/Models/User.php app/Http/Middleware/EnsureRole.php database/factories/UserFactory.php app/Modules/Registration/Policies/RegistrationPolicy.php lang/de/roles.php tests/Unit/Identity/RoleTest.php tests/Feature/Registration/CheckInPolicyTest.php
git commit -m "feat(identity): add helper role tier between participant and orga"
```

---

# Task 2: `infoscreen_scenes` migration + Model + `SceneType` enum + typed `SceneConfig` cast + Factory (roadmap 5.1)

**Files:**
- Create: `database/migrations/2026_07_16_100000_create_infoscreen_scenes_table.php`
- Create: `app/Modules/Infoscreen/Enums/SceneType.php`, `app/Modules/Infoscreen/Domain/SceneConfig.php` (readonly DTO), `app/Modules/Infoscreen/Casts/SceneConfigCast.php`, `app/Modules/Infoscreen/Models/InfoscreenScene.php`
- Create: `database/factories/InfoscreenSceneFactory.php`
- Create: `lang/de/infoscreen.php` (enum labels only in this task)
- Test: `tests/Unit/Infoscreen/{InfoscreenSceneTest,SceneConfigCastTest}.php`

**Interfaces:**
- Produces:
  - `SceneType: string { Bracket='bracket', UpcomingMatches='upcoming_matches', Schedule='schedule', Announcement='announcement', Seatmap='seatmap', PaymentQr='payment_qr', Sponsors='sponsors', Tombola='tombola', Status='status' }` + `label(): string => __('infoscreen.type.'.$this->value)`.
  - `SceneConfig` (readonly): a **flat, permissive typed bag** keyed by the union of all scene-type options — `?int $tournamentId, ?string $headline, ?string $body, ?string $qrPayload, ?string $qrCaption` plus `list<string> $sponsorLogoPaths` (default `[]`). `toArray(): array` (drop nulls/empties), `fromArray(array $data): self`. Keep it one DTO (YAGNI: a per-type class hierarchy is not worth it for ≤2 fields each) — each scene component reads only the keys it needs.
  - `SceneConfigCast implements CastsAttributes` — DB jsonb `object` ⇄ `SceneConfig`. `get()` tolerates `null`/`[]` (→ empty `SceneConfig`); `set()` `json_encode(...->toArray(), JSON_THROW_ON_ERROR)`.
  - `InfoscreenScene`: `id, event_id, type (SceneType), config (SceneConfigCast), duration_sec int default 15, sort int default 0, enabled bool default true, timestamps`. **Fillable: `event_id, type, duration_sec`** (descriptive-only). **NOT fillable: `config` (typed via cast), `sort` (orga-owned via reorder), `enabled` (orga-owned via toggle)** — set through Actions/Filament overrides. `event(): BelongsTo`. Scope `enabledOrdered()` = `where('enabled', true)->orderBy('sort')->orderBy('id')`.
  - `InfoscreenSceneFactory`: `definition()` (type Announcement, `duration_sec` 15, `enabled` true, `config` a `SceneConfig` with `headline`/`body`); states `->announcement()`, `->bracket(int $tournamentId)`, `->schedule()`, `->seatmap()`, `->sponsors(array $paths)`, `->disabled()`, `->sort(int $n)`.

- [ ] **Step 1: Failing tests** — `SceneConfigCastTest`: round-trips a `SceneConfig` through set/get; `null`/`[]` decode to an empty `SceneConfig`; unknown keys ignored. `InfoscreenSceneTest`: enum labels German (`expect(SceneType::Bracket->label())->toBe('Turnierbaum')`); factory row has `type` cast to enum, `config` an instance of `SceneConfig`, `enabled` bool; `enabledOrdered()` excludes disabled and sorts by `sort` then `id`.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Infoscreen` → FAIL.

- [ ] **Step 3: Implement** — migration (below), enum, DTO, cast, model, factory, `lang/de/infoscreen.php` `type` sub-array (`bracket => 'Turnierbaum', upcoming_matches => 'Nächste Matches', schedule => 'Programm', announcement => 'Ansage', seatmap => 'Sitzplan', payment_qr => 'Bezahl-QR', sponsors => 'Sponsoren', tombola => 'Tombola', status => 'Statusanzeige'`).

```php
// migration up()
Schema::create('infoscreen_scenes', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('event_id')->constrained()->cascadeOnDelete();
    $table->string('type');
    $table->jsonb('config')->nullable();
    $table->unsignedInteger('duration_sec')->default(15);
    $table->integer('sort')->default(0);
    $table->boolean('enabled')->default(true);
    $table->timestamps();
    $table->index(['event_id', 'enabled', 'sort']);
});
```

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Infoscreen && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_16_100000_create_infoscreen_scenes_table.php app/Modules/Infoscreen/Enums app/Modules/Infoscreen/Domain app/Modules/Infoscreen/Casts app/Modules/Infoscreen/Models database/factories/InfoscreenSceneFactory.php lang/de/infoscreen.php tests/Unit/Infoscreen
git commit -m "feat(infoscreen): scene model with type enum and typed config cast"
```

---

# Task 3: Filament `InfoscreenSceneResource` — reorder, enable toggle, typed config (roadmap 5.1)

**Files:**
- Create: `app/Modules/Infoscreen/Filament/Resources/InfoscreenScenes/InfoscreenSceneResource.php`, `.../Schemas/InfoscreenSceneForm.php`, `.../Tables/InfoscreenScenesTable.php`, `.../Pages/{ListInfoscreenScenes,CreateInfoscreenScene,EditInfoscreenScene}.php`
- Create: `app/Modules/Infoscreen/Policies/InfoscreenScenePolicy.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover Infoscreen resources), `app/Providers/AppServiceProvider.php` (register policy), `lang/de/infoscreen.php` (`resource`, `fields`, `admin`)
- Test: `tests/Feature/Infoscreen/InfoscreenResourceAccessTest.php`

**Interfaces:**
- Produces: `/admin/infoscreen-scenes` CRUD (orga/admin via panel access + policy). Form: `event_id` (Select relationship), `type` (Select of `SceneType::cases()` → `->label()`), `duration_sec` (`TextInput->numeric()->integer()->minValue(3)`), and the **typed `config`** rendered per selected type via conditionally-`->visible(fn (Get $get) => $get('type') === …)` components: `TextInput`/`Textarea` for `headline`/`body` (Announcement/Status), `Select` for `tournamentId` (Bracket/UpcomingMatches), `TextInput` for `qrPayload`/`qrCaption` (PaymentQr), a `Repeater` of `FileUpload` for `sponsorLogoPaths` (Sponsors, `->disk('public')->directory('sponsors')`). Table: `type` badge, `duration_sec`, `enabled` as `ToggleColumn::make('enabled')` (inline toggle → persists via the cast-safe update), `event`, `->reorderable('sort')` + `->defaultSort('sort')`; edit/delete row actions `->authorize(...)`. Config is non-fillable → `CreateInfoscreenScene::handleRecordCreation` / `EditInfoscreenScene::{mutateFormDataBeforeFill,handleRecordUpdate}` marshal the flat form keys into a `SceneConfig` (mirror the `CreateFoodOrder`/`EditFoodOrder` pattern). `InfoscreenScenePolicy`: `viewAny/create/update/delete/reorder => isOrga()`.

- [ ] **Step 1: Failing test** — `InfoscreenResourceAccessTest`: participant `GET /admin/infoscreen-scenes` → 403; helper → 403 (no panel); orga → 200 and the list renders a seeded scene's `type` label. A Livewire test asserts the `config` round-trips through the cast: fill the Announcement `headline`, `->call('save')`, `->assertHasNoFormErrors()`, then `$scene->fresh()->config->headline` matches.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/InfoscreenResourceAccessTest.php` → FAIL (route missing).

- [ ] **Step 3: Implement** — resource per the `FoodOrderResource` pattern (Schema API, delegated Form/Table, `getModelLabel`/`getPluralModelLabel` + `navigationGroup` from `lang/de/infoscreen.php`, a Heroicon `navigationIcon`); `->reorderable('sort')`; `ToggleColumn`; the config marshalling overrides; register policy in `configureAuthorization()`; add to `AdminPanelProvider`:
```php
->discoverResources(
    in: app_path('Modules/Infoscreen/Filament/Resources'),
    for: 'App\\Modules\\Infoscreen\\Filament\\Resources',
)
```

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Filament app/Modules/Infoscreen/Policies app/Providers/Filament/AdminPanelProvider.php app/Providers/AppServiceProvider.php lang/de/infoscreen.php tests/Feature/Infoscreen/InfoscreenResourceAccessTest.php
git commit -m "feat(infoscreen): filament scene resource with drag-reorder, enable toggle and typed config"
```

---

# Task 4: `SceneOverride` broadcast + screen shell + rotation engine + `SceneAnnouncement` (roadmap 5.2, part of 5.3)

The public beamer page: dark, no auth, no navigation, client-side rotation through enabled scenes, subscribed to `event.{id}` for a `scene.override` push (show a scene now for a duration, then resume) and a `scenes.updated` reload signal. This task also ships the simplest scene (`SceneAnnouncement`) to prove the loop end-to-end, and defines the `SceneOverride` event that Tasks 7/8/10/12 will dispatch.

**Files:**
- Create: `app/Modules/Infoscreen/Events/SceneOverride.php`, `app/Modules/Infoscreen/Support/ScenePayload.php` (builds the per-scene DTO), `app/Modules/Infoscreen/Http/ScreenController.php`
- Create: `resources/js/pages/Screen/Show.vue`, `resources/js/composables/useSceneRotation.ts`, `resources/js/components/scenes/SceneFrame.vue`, `resources/js/components/scenes/SceneAnnouncement.vue`, `resources/js/types/infoscreen.ts`
- Modify: `routes/web.php` (public `GET /screen/{event:slug}`), `routes/channels.php` (document the `scene.override`/`scenes.updated` events on the existing public `event.{id}` channel), `lang/de/infoscreen.php` (`screen` sub-array)
- Test: `tests/Feature/Infoscreen/{ScreenPageTest,SceneOverrideBroadcastTest}.php`

**Interfaces:**
- Consumes: `InfoscreenScene`, `Event::isPubliclyVisible()`, `useEventChannel`.
- Produces:
  - `SceneOverride implements ShouldBroadcast, ShouldDispatchAfterCommit` — constructor `(public int $eventId, public array $scene)` where `$scene` is a `ScenePayload` array `{ id?, type, durationSec, config, data }`. `broadcastOn(): Channel => new Channel('event.'.$this->eventId)`; `broadcastAs(): 'scene.override'`; `broadcastWith(): ['scene' => $this->scene]`.
  - `ScenePayload::for(InfoscreenScene $scene): array` — returns `{ id, type: type->value, durationSec, config: config->toArray(), data: … }` where `data` is filled by later tasks (Task 5/6); in this task `data` is `[]` for every type except the ones this task renders (Announcement needs none). **Kept as the single scene→wire projection so the controller, the override event, and the winner/status producers never drift.**
  - `ScreenController::show(Event $event): Response` → `abort_unless($event->isPubliclyVisible(), 404)`; `scenes = InfoscreenScene::where('event_id',$event->id)->enabledOrdered()->get()->map(ScenePayload::for(...))`; renders `Screen/Show` with `scenes`, `event` (`{id,name,slug}`), `labels` (`trans('infoscreen.screen')`). Route name `screen.show`.
  - `useSceneRotation(scenes, options)` — a composable owning the current scene index + a timer that advances by the current scene's `durationSec`; exposes `current` (ref) and an `override(scene, ms)` method that interrupts, shows the pushed scene, then resumes rotation. Empty `scenes` → a neutral idle card (from `labels`).
  - `Screen/Show.vue` — full-viewport dark shell (`min-h-screen bg-black text-white`), no `AppLayout` (renders bare), `<Head>` title from `labels`. Subscribes `useEventChannel(event.id, ['.scene.override'], p => rotation.override(p.scene, p.scene.durationSec*1000))` and `useEventChannel(event.id, ['.scenes.updated'], () => router.reload({ only: ['scenes'] }))`. Renders the current scene inside `SceneFrame` (a chrome wrapper) by mapping `type` → component (a `<component :is>` registry; unknown type → nothing).
  - `SceneAnnouncement.vue` props `{ config: { headline?: string; body?: string }, labels }` — centered big headline + body.

- [ ] **Step 1: Failing tests** — `ScreenPageTest`: public GET `/screen/{slug}` (no auth) renders `Screen/Show` with German `labels.idle` and the enabled scenes in `sort` order; disabled scenes absent; a **draft** event → 404; the response is reachable without a logged-in user. `SceneOverrideBroadcastTest`: `Event::fake([SceneOverride::class])` (or `Broadcast::fake()`); dispatching `new SceneOverride($event->id, ScenePayload::for($scene))` targets channel `event.{eventId}` with `broadcastAs 'scene.override'` and the scene in `broadcastWith`.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/ScreenPageTest.php tests/Feature/Infoscreen/SceneOverrideBroadcastTest.php` → FAIL.

- [ ] **Step 3: Implement** — event, `ScenePayload`, controller + public route (outside the `auth` group), Vue shell + rotation composable + `SceneFrame` + `SceneAnnouncement` + `infoscreen.ts` types, `screen` labels (`title => 'Infoscreen', idle => 'Bereit', …`), and the `routes/channels.php` comment addition (document `scene.override` + `scenes.updated` as public `event.{id}` events, no private payload).

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Events app/Modules/Infoscreen/Support app/Modules/Infoscreen/Http resources/js/pages/Screen resources/js/composables/useSceneRotation.ts resources/js/components/scenes resources/js/types/infoscreen.ts routes/web.php routes/channels.php lang/de/infoscreen.php tests/Feature/Infoscreen
git commit -m "feat(infoscreen): public screen shell, rotation engine and scene override broadcast"
```

---

# Task 5: Reuse scenes — `SceneBracket`, `SceneUpcomingMatches`, `SceneSchedule`, `SceneSeatmap` (roadmap 5.3)

Fill `ScenePayload`'s `data` for the data-backed scene types and render them by reusing the M2/M3/M4 components at beamer size.

**Files:**
- Create: `resources/js/components/scenes/{SceneBracket,SceneUpcomingMatches,SceneSchedule,SceneSeatmap}.vue`
- Modify: `app/Modules/Infoscreen/Support/ScenePayload.php` (fill `data` per type), `app/Modules/Infoscreen/Http/ScreenController.php` (eager-load the sources), `resources/js/pages/Screen/Show.vue` (register the new components in the type registry), `resources/js/types/infoscreen.ts`, `lang/de/infoscreen.php` (`screen` labels for these scenes)
- Test: `tests/Feature/Infoscreen/ScenePayloadTest.php`

**Interfaces:**
- Consumes: `GameMatch`/`MatchStatus`, `BracketMatchDto` projection (reuse the Tournaments controller's `matchDto()` shape — extract it to a shared `App\Modules\Tournaments\Support\BracketMatchProjection` if it is currently private to the controller, so both the tournament page and the scene share one projection), `ScheduleItem` + `ScheduleController`'s item/now/next helpers, `Seat` + `SeatingController`'s seat mapping.
- Produces:
  - `ScenePayload::for` `data` by type: **Bracket** → `{ matches: BracketMatchDto[] }` for `config.tournamentId` (all matches, ordered round/position); **UpcomingMatches** → `{ matches: BracketMatchDto[] }` filtered to `status = Ready` for that tournament; **Schedule** → `{ items, now, next }` (reuse the schedule projection for the scene's event); **Seatmap** → `{ seats: SeatDto[] }` for the event.
  - `SceneBracket.vue` props `{ data: { matches }, labels }` → renders `BracketView` with `myEntryId: null` and the German bracket/status/report label dicts (pass `trans('tournaments.*')` through the controller into `labels`), scaled to fill the frame.
  - `SceneUpcomingMatches.vue` props `{ data: { matches }, labels }` → a large card list of ready matches (`slot1 vs slot2`, round).
  - `SceneSchedule.vue` props `{ data: { items, now, next }, labels }` → beamer variant of the schedule now/next.
  - `SceneSeatmap.vue` props `{ data: { seats }, labels }` → the SVG grid (reuse the CELL-based layout from `Event/Seating.vue`, read-only, larger cells).

- [ ] **Step 1: Failing test** — `ScenePayloadTest`: for a bracket scene, `ScenePayload::for` returns `data.matches` with the tournament's matches; for an upcoming-matches scene, only `Ready` matches; for a schedule scene, `data.now`/`data.next` reflect time-travel; for a seatmap scene, `data.seats` carry `{id,label,x,y,occupant}`. Guard against N+1 (assert a bounded query count with `DB::listen` or `assertDatabasecount`-style sampling is optional; at minimum eager-load).

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/ScenePayloadTest.php` → FAIL.

- [ ] **Step 3: Implement** — fill `ScenePayload`, eager-load in the controller, build the four Vue scene components (reusing `BracketView` and the seat SVG), register them in the `Screen/Show.vue` type registry, extend `infoscreen.ts`, add `screen` labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Support/ScenePayload.php app/Modules/Infoscreen/Http/ScreenController.php resources/js/components/scenes resources/js/pages/Screen/Show.vue resources/js/types/infoscreen.ts lang/de/infoscreen.php tests/Feature/Infoscreen/ScenePayloadTest.php
git commit -m "feat(infoscreen): bracket, upcoming-matches, schedule and seatmap scenes reusing existing UI"
```

---

# Task 6: `ScenePaymentQr` + `SceneSponsors` (roadmap 5.3)

**Files:**
- Create: `resources/js/components/scenes/{ScenePaymentQr,SceneSponsors}.vue`
- Modify: `app/Modules/Infoscreen/Support/ScenePayload.php` (PaymentQr → server-rendered QR SVG; Sponsors → public storage URLs for logo paths), `app/Modules/Infoscreen/Http/ScreenController.php`, `resources/js/pages/Screen/Show.vue` (registry), `resources/js/types/infoscreen.ts`, `lang/de/infoscreen.php`
- Test: `tests/Feature/Infoscreen/ScenePaymentQrTest.php`

**Interfaces:**
- Consumes: `App\Modules\Registration\Support\QrCode::svg(string $token): string` (reused for any payload string — it is content-agnostic).
- Produces:
  - `ScenePayload::for` `data`: **PaymentQr** → `{ qrSvg: QrCode::svg($config->qrPayload ?? ''), caption: $config->qrCaption }` (empty payload → no `qrSvg`); **Sponsors** → `{ logos: string[] }` = `Storage::disk('public')->url($path)` for each `config->sponsorLogoPaths`.
  - `ScenePaymentQr.vue` props `{ data: { qrSvg?, caption? }, config, labels }` → a large centered QR (`v-html="data.qrSvg"`) + caption (payment/contribution info, mirrors the v1 display-wall).
  - `SceneSponsors.vue` props `{ data: { logos }, labels }` → a responsive logo grid (`max-h-…`, `object-contain`).

- [ ] **Step 1: Failing test** — `ScenePaymentQrTest`: a payment-qr scene yields `data.qrSvg` containing `<svg` for the configured payload and passes the caption; a sponsors scene yields `data.logos` as public URLs for the uploaded paths; an empty QR payload yields no `qrSvg`.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/ScenePaymentQrTest.php` → FAIL.

- [ ] **Step 3: Implement** — fill `ScenePayload` (inject `QrCode`), build both Vue scenes, register in the type registry, extend types + labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Support/ScenePayload.php app/Modules/Infoscreen/Http/ScreenController.php resources/js/components/scenes resources/js/pages/Screen/Show.vue resources/js/types/infoscreen.ts lang/de/infoscreen.php tests/Feature/Infoscreen/ScenePaymentQrTest.php
git commit -m "feat(infoscreen): payment-QR and sponsors scenes"
```

---

# Task 7: Winner moment — finals listener → `SceneOverride` + confetti overlay (roadmap 5.4)

`MatchCompleted` rides `tournament.{id}` only. A queued listener detects a decisive finals result and re-broadcasts a `SceneOverride` (a synthetic `winner` scene) onto the tournament's **event** channel, driving a confetti + "WINNER" overlay on the screen.

**Files:**
- Create: `app/Modules/Infoscreen/Listeners/BroadcastWinnerMoment.php`, `resources/js/components/scenes/SceneWinner.vue`, `resources/js/components/scenes/ConfettiOverlay.vue`
- Modify: `app/Providers/AppServiceProvider.php` (wire the listener to `MatchCompleted`), `app/Modules/Infoscreen/Enums/SceneType.php` (add `Winner='winner'` — a synthetic, non-configurable override-only type; not offered in the Filament create form), `resources/js/pages/Screen/Show.vue` (register `SceneWinner`), `lang/de/infoscreen.php` (winner copy)
- Test: `tests/Feature/Infoscreen/WinnerMomentTest.php`

**Interfaces:**
- Consumes: `MatchCompleted` (carries the `GameMatch`), `GameMatch` (`bracket`, `next_match_id`, `winner_entry_id`, `tournament`), `Tournament` (`event_id`, `winner_entry_id`, `status`), the entry→display-name resolution used on the tournament page.
- Produces:
  - `BroadcastWinnerMoment implements ShouldQueue` — on `MatchCompleted`: return early unless the match is decisive (`match.bracket === Bracket::Finals && match.next_match_id === null`, **or** the parent `tournament.status === TournamentStatus::Finished` with a set `winner_entry_id`); resolve the winner's display name (team/user) and dispatch `new SceneOverride($tournament->event_id, [ 'type' => 'winner', 'durationSec' => 12, 'config' => [], 'data' => ['winner' => $name, 'tournament' => $tournament->name] ])`. Idempotent per finals result (a repeat `MatchCompleted` for the same decided final may re-fire the overlay — acceptable for a beamer; do not persist state).
  - `SceneWinner.vue` props `{ data: { winner, tournament }, labels }` → big "WINNER" + name, wraps `ConfettiOverlay`.
  - `ConfettiOverlay.vue` — a self-contained canvas/DOM confetti animation, **no external JS dependency** (verify none is already vendored before writing; if a first-party-quality tiny lib is clearly warranted, confirm current maintenance before adding). Respects `prefers-reduced-motion`.

- [ ] **Step 1: Failing test** — `WinnerMomentTest`: `Event::fake([SceneOverride::class])`; firing `MatchCompleted` for a **decisive finals** `GameMatch` dispatches one `SceneOverride` on `event.{eventId}` with `data.winner` = the champion's name and `type === 'winner'`; firing it for a **non-final** completed match dispatches nothing.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/WinnerMomentTest.php` → FAIL.

- [ ] **Step 3: Implement** — listener + wiring (`Event::listen(MatchCompleted::class, BroadcastWinnerMoment::class)` in `configureEventListeners()`), the synthetic `Winner` enum case (exclude it from the Filament `type` Select options), the two Vue components, registry entry, labels (`winner => 'Sieger!', …`).

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Listeners app/Modules/Infoscreen/Enums/SceneType.php app/Providers/AppServiceProvider.php resources/js/components/scenes resources/js/pages/Screen/Show.vue lang/de/infoscreen.php tests/Feature/Infoscreen/WinnerMomentTest.php
git commit -m "feat(infoscreen): winner moment overlay via finals-detecting broadcast listener"
```

---

# Task 8: Orga/Helfer remote "Sofort einblenden" (roadmap 5.5)

A one-click "show now" for a configured scene, from the Filament resource (orga) **and** a helper-reachable control surface (helper). Both funnel through one Action that broadcasts `SceneOverride`. This is the trigger target reused by Tasks 10 & 12.

**Files:**
- Create: `app/Modules/Infoscreen/Actions/ShowSceneNow.php`, `app/Modules/Infoscreen/Exceptions/InfoscreenException.php`, `app/Modules/Infoscreen/Http/ScreenControlController.php`, `resources/js/pages/Screen/Control.vue`
- Modify: `app/Modules/Infoscreen/Filament/Resources/InfoscreenScenes/Tables/InfoscreenScenesTable.php` (add a `show_now` row action), `app/Modules/Infoscreen/Policies/InfoscreenScenePolicy.php` (add `showNow => isHelper()`), `routes/web.php` (helper control routes), `lang/de/infoscreen.php` (`control` sub-array)
- Test: `tests/Feature/Infoscreen/ShowSceneNowTest.php`

**Interfaces:**
- Produces:
  - `ShowSceneNow::handle(InfoscreenScene $scene, ?int $durationSec = null): void` — build `ScenePayload::for($scene)` (override `durationSec` if given), `event(new SceneOverride($scene->event_id, $payload))`. Throw `InfoscreenException::sceneDisabled()` if the scene is disabled (guard; a disabled scene shouldn't be pushed) — or allow it and document; **decision: allow** (an orga may want a one-off push of an otherwise-off scene) → no disabled guard, keep the Action minimal.
  - `InfoscreenScenePolicy::showNow(User $user): bool => $user->isHelper()`.
  - Filament table row action `show_now`: `->authorize('showNow')->requiresConfirmation()->action(fn (InfoscreenScene $r) => app(ShowSceneNow::class)->handle($r))` + success `Notification`.
  - `ScreenControlController::index(Event $event)` renders `Screen/Control` (a helper/orga list of the event's scenes with a "show now" button each); `show(Request, InfoscreenScene)` calls `ShowSceneNow` (authorize `showNow`). Routes under `middleware(['auth','role:helper'])`, names `screen.control` / `screen.control.show`.
  - `Screen/Control.vue` props `{ event, scenes: {id,type,typeLabel}[], labels }` — buttons POST to `screen.control.show`.

- [ ] **Step 1: Failing test** — `ShowSceneNowTest`: `Event::fake([SceneOverride::class])`; a `->helper()` user POSTing `screen.control.show` for a scene dispatches one `SceneOverride` on `event.{id}`; a plain participant → 403; the Filament `show_now` row action (Livewire) as orga dispatches the override; `ShowSceneNow` with a `durationSec` override reflects it in the payload.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/ShowSceneNowTest.php` → FAIL.

- [ ] **Step 3: Implement** — Action, exception, policy verb, Filament row action, control controller + routes + Vue page, `control` labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Actions app/Modules/Infoscreen/Exceptions app/Modules/Infoscreen/Http/ScreenControlController.php app/Modules/Infoscreen/Filament app/Modules/Infoscreen/Policies resources/js/pages/Screen/Control.vue routes/web.php lang/de/infoscreen.php tests/Feature/Infoscreen/ShowSceneNowTest.php
git commit -m "feat(infoscreen): show-now action for orga (filament) and helpers (control page)"
```

---

# Task 9: Schedule favorites — star, reminder, change-alarm (roadmap 5.7, part 1)

A per-user favorite star on each schedule item → a personal schedule, a reminder before start, and a change-alarm to affected users (participants + favoriters) when a favorited item's time changes.

**Files:**
- Create: `database/migrations/2026_07_16_110000_create_schedule_item_favorites_table.php`, `app/Modules/Schedule/Models/ScheduleItemFavorite.php`, `database/factories/ScheduleItemFavoriteFactory.php`
- Create: `app/Modules/Schedule/Actions/{FavoriteScheduleItem,UnfavoriteScheduleItem}.php`, `app/Modules/Schedule/Notifications/{ScheduleItemStartingSoon,ScheduleItemChanged}.php`, `app/Modules/Schedule/Console/SendScheduleRemindersCommand.php`, `app/Modules/Schedule/Listeners/AlarmScheduleItemChanged.php`, `app/Modules/Schedule/Events/ScheduleItemTimeChanged.php`
- Modify: `app/Modules/Schedule/Models/ScheduleItem.php` (`favorites()` + a guarded `saved` hook dispatching `ScheduleItemTimeChanged` when `starts_at` changed), `app/Modules/Schedule/Http/ScheduleController.php` (add `mine` flag per item + POST/DELETE favorite endpoints or a dedicated controller), `resources/js/pages/Schedule/Index.vue` (star button), `routes/web.php` (auth favorite routes), `routes/console.php` (schedule the reminder command), `app/Providers/AppServiceProvider.php` (wire listener), `lang/de/schedule.php` (favorite copy + notification copy)
- Test: `tests/Unit/Schedule/FavoriteScheduleItemTest.php`, `tests/Feature/Schedule/{ScheduleReminderTest,ScheduleChangeAlarmTest}.php`

**Interfaces:**
- Produces:
  - `ScheduleItemFavorite`: `id, schedule_item_id, user_id, reminded_at nullable, timestamps`; `unique(['schedule_item_id','user_id'])`. Fillable set in Action only; `reminded_at` non-fillable.
  - `FavoriteScheduleItem::handle(ScheduleItem $item, User $user): ScheduleItemFavorite` (idempotent — `firstOrCreate`, narrowed 23505). `UnfavoriteScheduleItem::handle(ScheduleItem $item, User $user): void`.
  - `ScheduleItemStartingSoon` / `ScheduleItemChanged` — user notifications under the new `schedule` category. Each implements both `toDatabase()` (returns `['category' => 'schedule', 'title' => __('schedule.notify.*.title'), 'body' => __('schedule.notify.*.body', [...])]`) and `toDiscord()` (a one-line German string), with `via()` returning `['database', DiscordChannel::class]` (bell always lands; Discord DM mirrors per the `schedule` pref via `NotificationPreferences::wants`). Add `schedule` to the notifications category set.
  - `SendScheduleRemindersCommand` signature `lanomat:send-schedule-reminders` — for favorites whose item starts within the lead window (e.g. 15 min) and `reminded_at IS NULL`, notify the user and stamp `reminded_at` (dedup); scheduled `everyMinute()`.
  - `ScheduleItemTimeChanged` (`Dispatchable`, carries the `ScheduleItem`) — dispatched from the model `saved` hook **only** when `wasChanged('starts_at')` (mirror the M4 `TournamentSaved` guard to avoid loops). `AlarmScheduleItemChanged implements ShouldQueue` notifies each favoriter with `ScheduleItemChanged`.
  - `ScheduleController` item DTO gains `mine: bool` (favorited by the current user); the star POSTs `FavoriteScheduleItem` / DELETEs `UnfavoriteScheduleItem` (authorize: any auth user, own favorite only for delete).

- [ ] **Step 1: Failing tests** — `FavoriteScheduleItemTest`: favorite is idempotent; unique per (item,user); unfavorite removes it. `ScheduleReminderTest`: with time-travel, `lanomat:send-schedule-reminders` sends exactly one `ScheduleItemStartingSoon` to a favoriter inside the lead window and stamps `reminded_at` (second run sends nothing). `ScheduleChangeAlarmTest`: changing an item's `starts_at` dispatches `ScheduleItemTimeChanged` and the listener notifies favoriters with `ScheduleItemChanged`; changing only the `title` does **not** alarm.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Schedule/FavoriteScheduleItemTest.php tests/Feature/Schedule/ScheduleReminderTest.php tests/Feature/Schedule/ScheduleChangeAlarmTest.php` → FAIL.

- [ ] **Step 3: Implement** — migration, model, factory, actions, notifications (verify category), command + `Schedule::command('lanomat:send-schedule-reminders')->everyMinute();`, event + guarded hook + listener wiring, controller `mine` + favorite routes, the star UI, labels. Add `Feature/Schedule` to `Http::preventStrayRequests()` group if the Discord mirror is exercised.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Schedule tests/Feature/Schedule && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_16_110000_create_schedule_item_favorites_table.php app/Modules/Schedule database/factories/ScheduleItemFavoriteFactory.php resources/js/pages/Schedule/Index.vue routes/web.php routes/console.php app/Providers/AppServiceProvider.php lang/de/schedule.php tests/Unit/Schedule/FavoriteScheduleItemTest.php tests/Feature/Schedule/ScheduleReminderTest.php tests/Feature/Schedule/ScheduleChangeAlarmTest.php
git commit -m "feat(schedule): favorites with start reminders and change alarms through the bell"
```

---

# Task 10: Three one-click triggers (roadmap 5.7, part 2)

Helper/orga one-click triggers, wiring the M2 bell × M4 catering × Task 8 infoscreen: **"Essen ist da"** (notify all buyers of a food order + push the food scene/announcement to the beamer), **"Match/Server bereit" also into the bell** (currently Discord-only), **"Check-in öffnet"**. Bell = truth, Discord = mirror.

**Files:**
- Create: `app/Modules/Infoscreen/Actions/{TriggerFoodReady,TriggerCheckinOpen}.php`, notifications `app/Modules/Catering/Notifications/FoodOrderReady.php`, `app/Modules/Registration/Notifications/CheckinOpened.php`, `app/Modules/Tournaments/Notifications/MatchReadyBell.php`
- Modify: the "Match/Server bereit" path — extend the existing `MatchReady` handling (`app/Modules/Tournaments/Listeners/*` that today posts to Discord) to **also** notify the two rosters' users with `MatchReadyBell` (database + Discord-DM mirror), so the bell carries what previously lived only in the Discord channel; `app/Modules/Infoscreen/Http/ScreenControlController.php` (add `foodReady`/`checkinOpen` trigger endpoints) or a dedicated `TriggerController`; `resources/js/pages/Screen/Control.vue` (trigger buttons); `routes/web.php` (helper routes); `lang/de/*` (catering, registration, tournaments, infoscreen copy)
- Test: `tests/Feature/Infoscreen/TriggersTest.php`, `tests/Feature/Tournaments/MatchReadyBellTest.php`

**Interfaces:**
- Produces:
  - `TriggerFoodReady::handle(FoodOrder $order, User $actor): void` — authorize helper; `Notification::send($buyers, new FoodOrderReady($order))` where `$buyers` = distinct users with a `FoodOrderItem` in that order; then push the beamer via `ShowSceneNow` on the event's food/announcement scene **or** a synthetic announcement `SceneOverride` ("Essen ist da!"). Idempotency not required (an orga may re-announce).
  - `TriggerCheckinOpen::handle(Event $event, User $actor): void` — authorize helper; notify the event's confirmed registrants with `CheckinOpened`.
  - `FoodOrderReady` (category `catering`), `CheckinOpened` (category `checkin`), `MatchReadyBell` (category `match`) — each implements `toDatabase()` (the `['category','title','body']` array) and `toDiscord()`, `via()` `['database', DiscordChannel::class]` (bell always lands; Discord DM mirrors per that category's pref). `MatchReadyBell` `body` carries the match URL + Mumble voice link already present in the Discord embed. Add the three new categories to the notifications set.
  - Helper-reachable trigger buttons on `Screen/Control.vue`; endpoints under `role:helper`.
  - **Fold-in (Discord verstärkt):** while touching the announce path, also surface the M2.11 registration-open announcement in the bell (a `RegistrationOpened` database notification to the event's registrants) — small addition, same "amplify not replace" theme; keep the existing direct-`sendMessage` Discord broadcast as the mirror.

- [ ] **Step 1: Failing tests** — `TriggersTest`: `Notification::fake()` + `Event::fake([SceneOverride::class])`; a helper firing "Essen ist da" notifies exactly the order's buyers (not other users) with `FoodOrderReady` **and** dispatches a `SceneOverride`; "Check-in öffnet" notifies confirmed registrants with `CheckinOpened`; a participant → 403. `MatchReadyBellTest`: when `MatchReady` fires, the two rosters' users receive a database `MatchReadyBell` (the bell now carries it, not just Discord); prefs opt-out suppresses the Discord mirror but not the database entry.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/TriggersTest.php tests/Feature/Tournaments/MatchReadyBellTest.php` → FAIL.

- [ ] **Step 3: Implement** — actions, three notifications, extend the `MatchReady` listener to also fan out `MatchReadyBell`, trigger endpoints + buttons + routes, the registration-open bell fold-in, all German copy.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen tests/Feature/Tournaments tests/Feature/Catering tests/Feature/Registration && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Actions app/Modules/Catering/Notifications app/Modules/Registration/Notifications app/Modules/Tournaments/Notifications app/Modules/Tournaments/Listeners app/Modules/Infoscreen/Http resources/js/pages/Screen/Control.vue routes/web.php lang/de tests/Feature/Infoscreen/TriggersTest.php tests/Feature/Tournaments/MatchReadyBellTest.php
git commit -m "feat(triggers): food-ready, match-ready-to-bell and check-in-open triggers (bell is truth, discord mirrors)"
```

---

# Task 11: Tombola scene + draw show (roadmap 5.8, part 1)

Each checked-in participant automatically gets a lot; orga maintains prizes; the draw plays as a beamer show (same show mechanic slated for the game-vote wheel).

**Files:**
- Create: `database/migrations/2026_07_16_120000_create_tombola_prizes_table.php`, `2026_07_16_120100_create_tombola_draws_table.php`
- Create: `app/Modules/Infoscreen/Models/{TombolaPrize,TombolaDraw}.php`, `database/factories/{TombolaPrizeFactory,TombolaDrawFactory}.php`, `app/Modules/Infoscreen/Actions/DrawTombola.php`, `app/Modules/Infoscreen/Filament/Resources/TombolaPrizes/*` (CRUD), `resources/js/components/scenes/SceneTombola.vue`
- Modify: `app/Modules/Infoscreen/Support/ScenePayload.php` (Tombola scene data: prizes + latest draw), `app/Modules/Infoscreen/Http/ScreenControlController.php` (a "draw" trigger), `app/Modules/Infoscreen/Policies/InfoscreenScenePolicy.php` (`drawTombola => isHelper()`), `app/Providers/Filament/AdminPanelProvider.php` (already discovers the module — the new resource folder is under the same `for:` namespace root, confirm discovery covers it), `resources/js/pages/Screen/Show.vue` + `Control.vue`, `lang/de/infoscreen.php`
- Test: `tests/Unit/Infoscreen/DrawTombolaTest.php`, `tests/Feature/Infoscreen/TombolaDrawShowTest.php`

**Interfaces:**
- Produces:
  - `TombolaPrize`: `id, event_id, title, sort, timestamps`; `TombolaDraw`: `id, event_id, tombola_prize_id, registration_id, drawn_at, timestamps` (`registration_id` = the winning checked-in registration; non-fillable, set in Action).
  - `DrawTombola::handle(Event $event, TombolaPrize $prize): TombolaDraw` — `DB::transaction` + lock the event row; eligible pool = checked-in registrations not yet drawn for this event; **pick without `Math.random()` bias concerns server-side** using `inRandomOrder()->first()` (DB-side randomness — deterministic-test note: the test asserts membership in the pool + uniqueness, not a specific winner); throw `InfoscreenException::noEligibleEntrants()` if empty; create the draw; broadcast a `SceneOverride` (`type => 'tombola'`, `data => { prize, winner }`) so the draw reveals on the beamer.
  - `ScenePayload::for` Tombola data → `{ prizes: [...], lastDraw: {prize, winner}|null }`.
  - `SceneTombola.vue` props `{ data, labels }` → a reveal animation for the latest draw (reuse `ConfettiOverlay`), otherwise a prize board.
  - Filament `TombolaPrizeResource` (orga CRUD of prizes, reorderable `sort`); a helper "draw next" trigger on `Screen/Control.vue`.

- [ ] **Step 1: Failing tests** — `DrawTombolaTest`: draws only from checked-in registrations; never repeats a winner within an event; empty pool → `noEligibleEntrants`; the drawn `registration_id` is non-fillable (set by the Action). `TombolaDrawShowTest`: `Event::fake([SceneOverride::class])`; a helper drawing dispatches a `tombola` `SceneOverride` with the prize + winner; a participant → 403.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Infoscreen/DrawTombolaTest.php tests/Feature/Infoscreen/TombolaDrawShowTest.php` → FAIL.

- [ ] **Step 3: Implement** — migrations, models, factories, Action, Filament prize resource (confirm it is discovered), the scene component + payload, the draw trigger + policy, labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Infoscreen tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_16_1200* app/Modules/Infoscreen database/factories/Tombola*Factory.php resources/js/components/scenes/SceneTombola.vue resources/js/pages/Screen app/Providers/Filament/AdminPanelProvider.php lang/de/infoscreen.php tests/Unit/Infoscreen/DrawTombolaTest.php tests/Feature/Infoscreen/TombolaDrawShowTest.php
git commit -m "feat(infoscreen): tombola prizes and draw-as-a-show beamer scene"
```

---

# Task 12: Status-announcement tile + auto-override on outage (roadmap 5.8, part 2)

An operations status tile (internet / server load / voice) with an auto-override that pops "Internet down, Orga weiß Bescheid" onto the beamer when a fault is flagged — sparing the 20 simultaneous questions.

**Files:**
- Create: `database/migrations/2026_07_16_130000_create_status_signals_table.php`, `app/Modules/Infoscreen/Models/StatusSignal.php`, `app/Modules/Infoscreen/Enums/StatusLevel.php`, `database/factories/StatusSignalFactory.php`, `app/Modules/Infoscreen/Actions/SetStatusSignal.php`, `resources/js/components/scenes/SceneStatus.vue`
- Modify: `app/Modules/Infoscreen/Support/ScenePayload.php` (Status scene data), `app/Modules/Infoscreen/Http/ScreenControlController.php` (set-status endpoint), `app/Modules/Infoscreen/Policies/InfoscreenScenePolicy.php` (`setStatus => isHelper()`), `resources/js/pages/Screen/{Show,Control}.vue`, `lang/de/infoscreen.php`
- Test: `tests/Feature/Infoscreen/StatusSignalTest.php`

**Interfaces:**
- Produces:
  - `StatusLevel: string { Ok='ok', Degraded='degraded', Down='down' }` + `label()`.
  - `StatusSignal`: `id, event_id, component (string: 'internet'|'servers'|'voice'), level (StatusLevel), message nullable, timestamps` — latest row per (event, component) is current.
  - `SetStatusSignal::handle(Event $event, string $component, StatusLevel $level, ?string $message, User $actor): StatusSignal` — authorize helper; upsert the component's signal; **when `level !== Ok`, auto-broadcast a `SceneOverride`** (`type => 'status'`, `data => { component, level, message }`, a longer `durationSec`) so the outage notice pops immediately; when returning to `Ok`, broadcast a `scenes.updated` reload (clears the override on next rotation).
  - `ScenePayload::for` Status data → `{ signals: [{component, level, message}] }` (all components' current levels).
  - `SceneStatus.vue` props `{ data, labels }` → a traffic-light tile; on `down`, the reassurance copy.
  - Helper set-status controls on `Screen/Control.vue`.

- [ ] **Step 1: Failing test** — `StatusSignalTest`: `Event::fake([SceneOverride::class])`; a helper setting `internet=down` upserts the signal **and** dispatches a `status` `SceneOverride` with the message; setting it back to `ok` dispatches no outage override (a `scenes.updated` reload instead, or nothing); a participant → 403; enum labels German.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/StatusSignalTest.php` → FAIL.

- [ ] **Step 3: Implement** — migration, enum, model, factory, Action, scene payload + component, endpoint + policy + control UI, labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_16_130000_create_status_signals_table.php app/Modules/Infoscreen resources/js/components/scenes/SceneStatus.vue resources/js/pages/Screen database/factories/StatusSignalFactory.php lang/de/infoscreen.php tests/Feature/Infoscreen/StatusSignalTest.php
git commit -m "feat(infoscreen): operations status tile with auto-override on outage"
```

---

# Task 13: Orga-Ping (roadmap 5.8, part 3)

A participant "call orga" button → a notification to orga/helper with the caller's seat + up to three optional words. No ticket system — just the ping.

**Files:**
- Create: `app/Modules/Infoscreen/Actions/PingOrga.php`, `app/Modules/Infoscreen/Notifications/OrgaPinged.php`, `app/Modules/Infoscreen/Http/OrgaPingController.php`
- Modify: `routes/web.php` (auth `POST /events/{event:slug}/ping-orga`), a participant-facing button (e.g. on the seating page `resources/js/pages/Event/Seating.vue` or the event page), `lang/de/infoscreen.php`
- Test: `tests/Feature/Infoscreen/OrgaPingTest.php`

**Interfaces:**
- Produces:
  - `PingOrga::handle(Event $event, User $caller, ?string $words): void` — validate `$words` in the Action (≤3 words / ≤40 chars — Action-level, not just FormRequest, per the M4 lesson); resolve the caller's seat label via the M2 seat assignment (nullable); `Notification::send($orgaAndHelpers, new OrgaPinged($caller, $seatLabel, $words))` where recipients = users with `role ∈ {orga, admin, helper}` for the event context. `OrgaPinged` (category `orga_ping`) implements `toDatabase()` (`['category' => 'orga_ping', 'title' => …, 'body' => __('infoscreen.orga_ping.body', ['name' => …, 'seat' => …, 'words' => …])]`) and `toDiscord()`, `via()` `['database', DiscordChannel::class]`.
  - `OrgaPingController::store(Request, Event)` — authorize any auth participant; rate-limit (Laravel `throttle`) to avoid spam.
  - A "Orga rufen" button + optional 3-word input on a participant page.

- [ ] **Step 1: Failing test** — `OrgaPingTest`: `Notification::fake()`; a participant POSTing a ping notifies all orga+helper users (not other participants) with `OrgaPinged` carrying the caller's seat label + words; words over the limit → validation error (German); an unauthenticated request → redirect/401; throttling returns 429 after the cap.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Infoscreen/OrgaPingTest.php` → FAIL.

- [ ] **Step 3: Implement** — Action (with word validation + seat resolution), notification, controller + throttled route, the button UI, labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Infoscreen && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Infoscreen/Actions/PingOrga.php app/Modules/Infoscreen/Notifications/OrgaPinged.php app/Modules/Infoscreen/Http/OrgaPingController.php routes/web.php resources/js/pages/Event/Seating.vue lang/de/infoscreen.php tests/Feature/Infoscreen/OrgaPingTest.php
git commit -m "feat(infoscreen): orga-ping button notifying orga and helpers with seat and three words"
```

---

# Task 14: Production deployment — FrankenPHP app image, compose `prod` profile, allowed_origins lockdown, refreshFormData fix, deploy docs (roadmap 5.6)

**Files:**
- Create: `docker/Dockerfile` (FrankenPHP `app` image), `docker/frankenphp/Caddyfile` (if the base image needs it), `.dockerignore` (if absent)
- Modify: `compose.yml` (add a `prod` profile with `app`, `queue`, `scheduler` services + a prod `reverb`; keep dev services default-profile), `config/reverb.php` (`allowed_origins` from env), `.env.example` (finalize prod keys: `REVERB_ALLOWED_ORIGINS`, `REVERB_SCHEME=https` note, `APP_ENV`/`APP_DEBUG` prod guidance), `README.md` (a "Production deployment" section), the M4 `refreshFormData` follow-up in `EditFoodOrder`/`EditPoll` **and** the pre-existing `EditTournament`/`StartTournament` (common small fix: `$record->refresh()` after the transition action, or have the Action return the held instance)
- Test: `tests/Feature/Deployment/ReverbOriginsTest.php` (config-level assertion), plus a smoke of the existing suite

**Interfaces:**
- Produces:
  - `docker/Dockerfile` — **verify the current stable FrankenPHP tag before pinning** (`dunglas/frankenphp` with PHP 8.4); installs the PHP extensions the app needs (`pdo_pgsql`, `redis`, `sodium`, `intl`, `zip`, `gd` if QR/image needs it), copies the app, runs `composer install --no-dev --optimize-autoloader` + `npm ci && npm run build`, caches config/routes/views. Serves via FrankenPHP (Octane optional — **verify** whether to add `laravel/octane` + FrankenPHP runtime or use FrankenPHP's native Laravel mode; follow current official Laravel deployment docs and note the choice in the commit body).
  - `compose.yml` `prod` profile: `app` (the image, depends on postgres+redis, healthcheck on `/up`), `queue` (`php artisan queue:work`), `scheduler` (`php artisan schedule:work` — runs the four scheduled commands + the new `lanomat:send-schedule-reminders`), `reverb` (prod image, not the throwaway CLI). Dev services stay in the default profile so `docker compose up -d` is unchanged; prod runs via `docker compose --profile prod up`. `mumble-admin` bound internal-only in prod (no host port publish under the prod profile).
  - `config/reverb.php`: `'allowed_origins' => explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '*'))` (default `'*'` for dev; prod sets the real hosts) — folds in the M3 insight.
  - The `refreshFormData` stale-status fix applied uniformly so Filament edit pages show the fresh `status` after a locked-instance transition action without a manual reload.
  - README "Production deployment": `.env` prod values, `docker compose --profile prod up -d`, `docker compose --profile prod exec app php artisan lanomat:install --admin-discord-id=<id>` verified in-container, TLS/reverse-proxy note (deferred to M7 Traefik but referenced).

> **Verify first (2026):** before writing the Dockerfile, check the current official FrankenPHP + Laravel deployment guidance (context7/laravel-boost + `frankenphp` docs) — base image tag, whether Octane is recommended, worker mode, and the `/up` healthcheck route. Pin whatever latest stable resolves; note deviations from this plan in the commit body.

- [ ] **Step 1: Failing test** — `ReverbOriginsTest`: with `REVERB_ALLOWED_ORIGINS='https://lan.example'` in the env, `config('reverb.apps.apps.0.allowed_origins')` equals `['https://lan.example']`; default (unset) yields `['*']`. (Config-level — no container needed in CI.)

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Deployment/ReverbOriginsTest.php` → FAIL.

- [ ] **Step 3: Implement** — the env-driven `allowed_origins`, the Dockerfile + Caddyfile, the compose `prod` profile, `.env.example` finalization, the `refreshFormData` fix across the four edit pages, README section. **Verify the FrankenPHP image builds** (`docker build -f docker/Dockerfile .`) and that `docker compose --profile prod config` is valid (do not require a full prod bring-up in CI — a build + config validation + the config test suffices; the full `--profile prod up` bring-up is the manual acceptance step).

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS (full suite — this touches shared config + Filament edit pages).

- [ ] **Step 5: Commit**
```bash
git add docker/Dockerfile docker/frankenphp compose.yml config/reverb.php .env.example README.md app/Modules/Catering/Filament/Resources/FoodOrders/Pages/EditFoodOrder.php app/Modules/Voting/Filament/Resources/Polls/Pages/EditPoll.php app/Modules/Tournaments/Filament/Resources/Tournaments/Pages/EditTournament.php tests/Feature/Deployment/ReverbOriginsTest.php
git commit -m "feat(deploy): frankenphp app image, prod compose profile, reverb origin lockdown and stale-status fix"
```

---

## Phase acceptance (M5)

- The screen runs 30 min stable in a kiosk browser through all scene types (`/screen/{event}`, no auth, dark, no nav); a "Sofort einblenden" push appears in < 2 s; a trigger ("Essen ist da") lands in the **bell AND** on the beamer.
- Feature tests per surface: screen renders public + draft-404; `SceneOverride` broadcast (winner, show-now, food-ready, tombola, status); favorites reminder/change-alarm (time-travel); triggers notify exactly the right recipients (buyers/registrants/rosters) with the bell as truth and Discord mirroring per prefs; helper role gates check-in + triggers but not `/admin`; orga-ping carries seat + words.
- `docker compose --profile prod up` yields a runnable system (manual acceptance); `docker compose --profile prod config` valid + the FrankenPHP image builds in CI; Reverb `allowed_origins` honors `REVERB_ALLOWED_ORIGINS`.
- Green CI on all six gates; i18n-gate satisfied per task; then the **whole-branch review on `opus`** (base = tag `m4`) → one consolidated fix wave → tag `m5`, per `superpowers:subagent-driven-development` and `superpowers:finishing-a-development-branch`.
- Update the roadmap M5 section with an "Erkenntnisse M5" block; close/advance the GitHub M5 milestone + board item; push to `origin`.

## Deferred / explicitly out of scope for M5

- Traefik reverse proxy + TLS termination → M7.1 (referenced from the deploy docs, not built here).
- Own Docker registry for the FrankenPHP image → M7.2.
- Game-vote wheel (shares the tombola show mechanic) → M4-Voting extension / R2 backlog (only the reusable draw-reveal component lands here).
- Presence live-view, casting/OBS overlays (reuse M5 scene tech) → M10.
- Jukebox now-playing scene → M11 (reuses this scene technique + `event.{id}`).
- A generic "typed settings" abstraction beyond `SceneConfigCast` — YAGNI; revisit only if a fourth typed-jsonb surface appears.
