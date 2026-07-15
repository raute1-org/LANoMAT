# M4 — Schedule, Catering, Voting, LFG Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Derived just-in-time from the roadmap M4 section (`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`), same format as the M0/M3 detail plans.

**Goal:** The full day-to-day organizer workflow of an event runs in the tool: a schedule (with auto-listed tournaments + ICS export), collective catering orders with cost splitting, live polls, and a Looking-for-Group board — each participant-facing, orga-manageable, and Discord-integrated where the roadmap calls for it.

**Architecture:** Four new modules under `app/Modules/{Schedule,Catering,Voting,Lfg}/`, each following the established module layout (`Actions/`, `Enums/`, `Events/`, `Exceptions/`, `Filament/`, `Http/`, `Models/`, `Policies/`, `Support/`, `Console/`, `Jobs/`, `Listeners/`). `Event` stays the aggregate root; all four modules hang off `event_id`. Cross-module communication is by Laravel events (e.g. tournament create/update → schedule item) and explicit Action calls — never foreign-table writes. Real-time (poll results) uses Reverb on the public `event.{id}` channel, mirroring the M3 `tournament.{id}` pattern. Discord surfaces (`/schedule`, `/lfg`, LFG announcement) reuse the M3 CommandRouter + DiscordOutboxGuard machinery.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5 (Schema API), Inertia v2 + Vue 3 `<script setup lang="ts">` + Tailwind v4 + shadcn-vue (components under `resources/js/components/ui/`, built on Reka UI), Reverb + `@laravel/echo-vue` (`useEchoPublic`), Pest 4, PostgreSQL 16. New dependency: `spatie/icalendar-generator` (NOT yet installed — Task 6 adds it, latest stable verified first).

## Global Constraints

Copied verbatim from the roadmap Global Constraints — every task's requirements implicitly include these:

- Code, comments, commits, docs in **English**; UI copy in **German** via `lang/de/*.php` (no hardcoded strings in components — pass a `labels` prop).
- **Conventional Commits** (`feat(scope): …`). TDD: failing test first wherever there is a testable behavior; frequent commits. Commit message trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- PHP: Pint (Laravel preset), Larastan **level 8**, no `mixed` returns in own code, **enums over magic strings**.
- Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- **Every authorization goes through a Policy** (registered explicitly in `AppServiceProvider::configureAuthorization()`). Never trust client-supplied user IDs — resolve the acting user from `$request->user()`.
- **Actions pattern:** one class per use case, single `handle()` method, returns the domain result type (no `void` where a result is meaningful), `DB::transaction` + parent-row `lockForUpdate()` first for capacity/window checks, throws a module `*Exception` carrying a `translationKey`.
- **Privilege-bearing / state fields are never `$fillable`** (status, paid_at, closed_at, expires_at, seed, lock_version, tallies) — set only inside Actions or `booted()` hooks.
- Uploads to Laravel Storage, never Base64 in the DB.
- Modules communicate via Laravel events + interfaces; never reach into another module's tables.
- External systems (Discord) only via the `DiscordClient` contract + `FakeDiscordClient` in tests; `Http::preventStrayRequests()` is global in `Feature/Discord`,`Unit/Discord`,`Feature/Voice`,`Unit/Voice` — extend it to the new test dirs that touch Discord.
- **Gates that must be green after every task:** `composer check` (pint --test, phpstan L8, pest) **and** `npm run lint:check && npm run format:check && npm run types:check && npm run build`. (Lesson from M3: `composer check` does NOT run Prettier — the frontend gate must be run explicitly.)
- **i18n-gate:** every task adding `lang/de` keys MUST include at least one Feature-test assertion on a translated label (`->where('labels.x', 'Übersetzter Text')` for Inertia pages; `expect(Enum::Case->label())->toBe('…')` for enums). `.env.testing` already sets `APP_LOCALE=de`.

## M4-specific insights folded in (from the M3 branch review — binding for this plan)

- **Type your jsonb — do NOT edit typed values through a raw Filament `KeyValue` field** (deferred insight #9). The Tournaments `settings` KeyValue mangles booleans (`false` → string `'false'`, which is truthy). Catering's `menu` and the poll structure are typed jsonb: model them with **custom casts / DTO arrays** and edit them in Filament with **typed components** (`Repeater` of `TextInput`/`TextInput->numeric()`/`Toggle`), never a bare `KeyValue`. Reads must be type-safe (cast integers/booleans explicitly).
- **Lock-order convention:** for any "check a window/capacity over child rows" (food-order window, poll open-state), lock the **parent aggregate row first** (`FoodOrder`/`Poll`), then read children — a `FOR UPDATE` on empty child rows locks nothing. (M2 lesson.)
- **Outbox dedup for channel-wide announcements** (LFG): reuse `DiscordOutboxGuard::once(dedupKey, kind, closure, channelId:, content:)`; insert-before-send with `sent_at` set after; the `SweepOutboxCommand` replays `sent_at IS NULL` rows older than 5 min. Dedup key pattern `"<domain>-<id>-<kind>"`. Announcement listeners/jobs are `ShouldQueue`.
- **Shared-prop cost:** if a task touches `HandleInertiaRequests`, keep additions lazy (`Inertia::optional(fn () => …)`) and bounded — do not add unbounded queries that load on every page.
- **Broadcasting:** events implement `ShouldBroadcast, ShouldDispatchAfterCommit`; `broadcastOn()` returns `new Channel('event.'.$id)` (public — the poll results view needs no auth, mirroring `tournament.{id}`); `broadcastAs()` uses dot-notation (`'poll.updated'`); document the new public channel in `routes/channels.php` (comment only, no auth callback).
- **Filament v5 specifics as used here:** `Resource::form(Schema $schema)` + `table(Table $table)` delegate to `Schemas/<X>Form::configure()` + `Tables/<X>Table::configure()`; `use Filament\Schemas\Schema;` (NOT `Form`); form components from `Filament\Forms\Components\*`; custom actions `->authorize('policyMethod')` + `->requiresConfirmation()` call `app(Action::class)->handle()` and catch the module exception into `Notification::make()->danger()`. **New module resource folders MUST be registered** in `AdminPanelProvider` via `->discoverResources(in: app_path('Modules/<Mod>/Filament/Resources'), for: 'App\\Modules\\<Mod>\\Filament\\Resources')` — discovery is per-directory and will not find them otherwise.

---

## Task ordering & dependencies

```
Schedule:  T1 → T2 → T3 → T4 → T5
ICS:       T6 (needs T1)
Catering:  T7 → T8 → T9 → T10
Voting:    T11 → T12 → T13 → T14
LFG:       T15 → T16 → T17 → T18 → T19
```
Modules are independent of each other; within a module, tasks are sequential. Recommended execution order: T1–T6 (Schedule+ICS), then T7–T10 (Catering), T11–T14 (Voting), T15–T19 (LFG). Each task ends green on all gates and is committed.

---

# Module: Schedule

Roadmap 4.1 + 4.2. `schedule_items` (`type`, `ref_type`/`ref_id` nullable); tournaments appear automatically via a listener on Tournament create/update; Filament management; participant page with a "Jetzt & gleich" widget; `/schedule` slash command; ICS export.

## Task 1: `schedule_items` migration + Model + Enum + Factory

**Files:**
- Create: `database/migrations/2026_07_15_100000_create_schedule_items_table.php`
- Create: `app/Modules/Schedule/Enums/ScheduleItemType.php`
- Create: `app/Modules/Schedule/Models/ScheduleItem.php`
- Create: `database/factories/ScheduleItemFactory.php`
- Create: `lang/de/schedule.php` (enum labels; page/admin labels added in later tasks)
- Test: `tests/Unit/Schedule/ScheduleItemTest.php`

**Interfaces:**
- Produces:
  - `App\Modules\Schedule\Models\ScheduleItem` — columns `id, event_id, type (ScheduleItemType), title, description nullable, starts_at, ends_at nullable, location nullable, ref_type nullable, ref_id nullable, sort int default 0, timestamps`. Casts: `type => ScheduleItemType::class`, `starts_at`/`ends_at` => `datetime`. Fillable: `event_id, type, title, description, starts_at, ends_at, location, ref_type, ref_id, sort` (all descriptive — no privilege fields here). `event(): BelongsTo`.
  - `App\Modules\Schedule\Enums\ScheduleItemType: string { Custom='custom', Tournament='tournament', Catering='catering', Break='break' }` with `label(): string => __('schedule.type.'.$this->value)`.
  - `ScheduleItemFactory` with `definition()` (type Custom, `starts_at` now()->addHour()) and states `->tournament()`, `->catering()`, `->custom()`.

- [ ] **Step 1: Failing test** — `ScheduleItemTest`: enum labels are German (`expect(ScheduleItemType::Tournament->label())->toBe('Turnier')`); factory creates a row with `type` cast to the enum and `starts_at` an instance of `CarbonInterface`; `ref_type`/`ref_id` nullable.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Schedule/ScheduleItemTest.php` → FAIL (classes missing).

- [ ] **Step 3: Implement** — migration (see schema below), enum, model, factory, `lang/de/schedule.php` with a `type` sub-array (`custom => 'Programmpunkt', tournament => 'Turnier', catering => 'Essen', break => 'Pause'`).

```php
// migration up()
Schema::create('schedule_items', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('event_id')->constrained()->cascadeOnDelete();
    $table->string('type')->default('custom');
    $table->string('title');
    $table->text('description')->nullable();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at')->nullable();
    $table->string('location')->nullable();
    $table->string('ref_type')->nullable();
    $table->unsignedBigInteger('ref_id')->nullable();
    $table->integer('sort')->default(0);
    $table->timestamps();
    $table->index(['event_id', 'starts_at']);
    $table->index(['ref_type', 'ref_id']);
});
```

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Schedule && composer check` → PASS.

- [ ] **Step 5: Commit** — stage the created paths only.
```bash
git add database/migrations/2026_07_15_100000_create_schedule_items_table.php app/Modules/Schedule/Enums/ScheduleItemType.php app/Modules/Schedule/Models/ScheduleItem.php database/factories/ScheduleItemFactory.php lang/de/schedule.php tests/Unit/Schedule/ScheduleItemTest.php
git commit -m "feat(schedule): schedule_items model, type enum and factory"
```

## Task 2: Schedule Actions + auto-list tournaments listener + Policy

**Files:**
- Create: `app/Modules/Schedule/Actions/{UpsertScheduleItem,DeleteScheduleItem,SyncTournamentScheduleItem}.php`
- Create: `app/Modules/Schedule/Listeners/SyncScheduleOnTournamentSaved.php`
- Create: `app/Modules/Schedule/Policies/ScheduleItemPolicy.php`
- Create: `app/Modules/Tournaments/Events/TournamentSaved.php` (if no existing create/update event exists — a plain `Dispatchable` event carrying the `Tournament`)
- Modify: `app/Modules/Tournaments/Models/Tournament.php` (dispatch `TournamentSaved` from a `saved` model event **or** from the CRUD Action; see Step 3 note), `app/Providers/AppServiceProvider.php` (register policy + listener)
- Test: `tests/Feature/Schedule/ScheduleSyncTest.php`, `tests/Unit/Schedule/UpsertScheduleItemTest.php`

**Interfaces:**
- Consumes: `Tournament` (`name`, `starts_at`, `event_id`, `id`).
- Produces:
  - `UpsertScheduleItem::handle(Event $event, array $attributes, ?ScheduleItem $item = null): ScheduleItem` — create or update a custom item (validated attributes from Filament/controller).
  - `DeleteScheduleItem::handle(ScheduleItem $item): void`.
  - `SyncTournamentScheduleItem::handle(Tournament $tournament): ScheduleItem` — idempotently upsert a `type=Tournament` item keyed by `(ref_type='tournament', ref_id=tournament.id)`; mirrors `title`=tournament name, `starts_at`=tournament `starts_at`.
  - `ScheduleItemPolicy`: `viewAny` public (returns true — schedule is public), `create/update/delete => $user->isOrga()`.

- [ ] **Step 1: Failing tests** — `ScheduleSyncTest`: creating/updating a `Tournament` results in exactly one `schedule_items` row with `type=Tournament`, `ref_id=tournament.id`, matching title/starts_at; updating the tournament's `starts_at` updates the same row (no duplicate). `UpsertScheduleItemTest`: creates a custom item; updating changes fields; unit-level (no HTTP).

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Schedule/ScheduleSyncTest.php tests/Unit/Schedule/UpsertScheduleItemTest.php` → FAIL.

- [ ] **Step 3: Implement** — Actions; the listener calls `SyncTournamentScheduleItem`. **Wire the tournament signal**: add `TournamentSaved` and dispatch it from the Tournaments CRUD path (prefer the existing Filament create/edit + any `CreateTournament` Action; if tournaments are only created via Filament, dispatch from the model's `saved` booted hook guarded to avoid firing inside bracket generation — dispatch only when `wasChanged`/`wasRecentlyCreated` on `name`/`starts_at`/`status`). Register in `AppServiceProvider::configureEventListeners()`: `Event::listen(TournamentSaved::class, SyncScheduleOnTournamentSaved::class);` and `Gate::policy(ScheduleItem::class, ScheduleItemPolicy::class);` in `configureAuthorization()`.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Schedule tests/Unit/Schedule && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Schedule/Actions app/Modules/Schedule/Listeners app/Modules/Schedule/Policies app/Modules/Tournaments/Events/TournamentSaved.php app/Modules/Tournaments/Models/Tournament.php app/Providers/AppServiceProvider.php tests/Feature/Schedule tests/Unit/Schedule/UpsertScheduleItemTest.php
git commit -m "feat(schedule): upsert/delete actions and auto-sync tournaments into the schedule"
```

## Task 3: Filament `ScheduleItemResource`

**Files:**
- Create: `app/Modules/Schedule/Filament/Resources/ScheduleItems/ScheduleItemResource.php`, `.../Schemas/ScheduleItemForm.php`, `.../Tables/ScheduleItemsTable.php`, `.../Pages/{ListScheduleItems,CreateScheduleItem,EditScheduleItem}.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (add `discoverResources` for the Schedule module), `lang/de/schedule.php` (`resource`, `fields`, `admin` sub-arrays)
- Test: `tests/Feature/Schedule/ScheduleResourceAccessTest.php`

**Interfaces:**
- Produces: `/admin/schedule-items` CRUD (orga/admin only via panel access + policy). Form: `event_id` (Select, relationship to events, label), `type` (Select of `ScheduleItemType::cases()` mapped to `->label()`), `title`, `description` (Textarea), `starts_at`/`ends_at` (DateTimePicker), `location`, `sort` (numeric). Create/edit call `UpsertScheduleItem` via the page's `handleRecordCreation`/`handleRecordUpdate` OR use default Filament persistence (custom items only; `ref_*` left null). Table: title, type badge, starts_at, event, sorted by `starts_at`; delete action `->authorize('delete')`.

- [ ] **Step 1: Failing test** — `ScheduleResourceAccessTest`: participant `GET /admin/schedule-items` → 403; orga → 200; the list page renders. (Follows `AdminPanelAccessTest` pattern — access + smoke, not field rendering.)

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Schedule/ScheduleResourceAccessTest.php` → FAIL (route missing).

- [ ] **Step 3: Implement** — resource per the `EventResource` pattern (Schema API, delegated Form/Table classes, `getModelLabel`/`getPluralModelLabel` from `lang/de/schedule.php`, `navigationIcon` a Heroicon, `navigationGroup` = `__('schedule.admin.nav_group')`). Add to `AdminPanelProvider`:
```php
->discoverResources(
    in: app_path('Modules/Schedule/Filament/Resources'),
    for: 'App\\Modules\\Schedule\\Filament\\Resources',
)
```

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Schedule && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Schedule/Filament app/Providers/Filament/AdminPanelProvider.php lang/de/schedule.php tests/Feature/Schedule/ScheduleResourceAccessTest.php
git commit -m "feat(schedule): filament ScheduleItemResource with CRUD and panel discovery"
```

## Task 4: Participant schedule page + "Jetzt & gleich" widget

**Files:**
- Create: `app/Modules/Schedule/Http/ScheduleController.php`, `resources/js/pages/Schedule/Index.vue`
- Modify: `routes/web.php` (public route `GET /events/{event:slug}/schedule`), `lang/de/schedule.php` (`page` sub-array)
- Test: `tests/Feature/Schedule/SchedulePageTest.php`

**Interfaces:**
- Consumes: `ScheduleItem`, `Event::isPubliclyVisible()`.
- Produces:
  - Route name `schedule.index`. Controller `show(Event $event): Response` → `abort_unless($event->isPubliclyVisible(), 404)`, loads items ordered by `starts_at, sort`, computes **now/next**: `now` = items whose `[starts_at, ends_at ?? starts_at+1h]` contains `now()`; `next` = the earliest item with `starts_at > now()`. Passes `items` (DTOs: id, type, typeLabel, title, description, startsAt ISO, endsAt ISO|null, location), `now` (array|null), `next` (array|null), `labels` (from `trans('schedule.page')`), `event` (name/slug).
  - `Index.vue`: a highlighted "Jetzt & gleich" card (uses `now`/`next`) atop a chronological list grouped visually by day. Uses shadcn-vue `Card`/`Badge`. All copy from `labels`.

- [ ] **Step 1: Failing test** — `SchedulePageTest`: public GET renders `Schedule/Index` with German `labels.title` = `'Programm'` and the item list; a draft event returns 404; the now/next computation surfaces the correct item given time-travel (`travelTo`).

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Schedule/SchedulePageTest.php` → FAIL.

- [ ] **Step 3: Implement** — controller + route (public group, `{event:slug}` binding) + Vue page + `page` labels (`title => 'Programm'`, `now => 'Jetzt'`, `next => 'Gleich'`, `empty => 'Noch kein Programm'`, …).

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Schedule && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Schedule/Http resources/js/pages/Schedule routes/web.php lang/de/schedule.php tests/Feature/Schedule/SchedulePageTest.php
git commit -m "feat(schedule): public schedule page with now-and-next widget"
```

## Task 5: `/schedule` slash command

**Files:**
- Create: `app/Modules/Discord/Interactions/Commands/ScheduleCommand.php`
- Modify: `app/Modules/Discord/Interactions/CommandRouter.php` (map `'schedule'`), `app/Modules/Discord/Interactions/CommandDefinitions.php` (definition), `lang/de/discord.php` (command copy)
- Test: `tests/Feature/Discord/ScheduleCommandTest.php`

**Interfaces:**
- Consumes: `CommandRouter`, `InteractionPayload`, `InteractionResponse`, `ScheduleItem`, `CurrentEvent`.
- Produces: `/schedule` (no subcommand) → responds with the current event's next few upcoming items (title + time), or a "no schedule / no event" message. Public data only (event must be `isPubliclyVisible()`).

- [ ] **Step 1: Failing test** — `ScheduleCommandTest` (uses `signedInteraction()`/`postInteraction()` helpers + `Http::preventStrayRequests` — add `Feature/Discord` is already covered): posting a `/schedule` application-command interaction returns a type-4 message containing an upcoming item's title; with no visible event returns the German "no event" copy.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Discord/ScheduleCommandTest.php` → FAIL.

- [ ] **Step 3: Implement** — handler (`handle(array $payload): array` → `InteractionResponse::message(...)`), register in `CommandRouter::commandMap()` (`'schedule' => ScheduleCommand::class`), add to `CommandDefinitions::all()` (`name => 'schedule'`, description key), add `discord.commands.schedule.*` copy.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Discord && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Discord/Interactions/Commands/ScheduleCommand.php app/Modules/Discord/Interactions/CommandRouter.php app/Modules/Discord/Interactions/CommandDefinitions.php lang/de/discord.php tests/Feature/Discord/ScheduleCommandTest.php
git commit -m "feat(discord): /schedule slash command listing upcoming programme items"
```

## Task 6: ICS export

**Files:**
- Modify: `composer.json` (add `spatie/icalendar-generator`), `routes/web.php` (public route `GET /events/{event:slug}/schedule.ics`), `app/Modules/Schedule/Http/ScheduleController.php` (add `ics()` method)
- Create: `app/Modules/Schedule/Support/ScheduleCalendar.php` (builds the `Calendar` from items)
- Test: `tests/Feature/Schedule/ScheduleIcsTest.php`

**Interfaces:**
- Produces: route `schedule.ics` returning `text/calendar`; `ScheduleCalendar::for(Event $event): string` builds a VCALENDAR with one VEVENT per schedule item (name = title, starts/ends from `starts_at`/`ends_at ?? starts_at`, description, location).

> **Verify first (2026):** before adding the dependency, check the current stable `spatie/icalendar-generator` version and API via context7/laravel-boost docs (`Calendar::create()->event(Event::create()->…)->get()`), and confirm PHP 8.4 / Laravel 13 compatibility. Pin whatever latest stable resolves; note any deviation in the commit body.

- [ ] **Step 1: Failing test** — `ScheduleIcsTest`: public GET `…/schedule.ics` returns 200, `Content-Type: text/calendar`, body starts with `BEGIN:VCALENDAR` and contains a `SUMMARY:` with an item title; draft event → 404.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Schedule/ScheduleIcsTest.php` → FAIL.

- [ ] **Step 3: Implement** — `composer require spatie/icalendar-generator` (verified version), `ScheduleCalendar`, controller `ics()` returning `response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="schedule.ics"'])`, route.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Schedule && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add composer.json composer.lock app/Modules/Schedule/Support/ScheduleCalendar.php app/Modules/Schedule/Http/ScheduleController.php routes/web.php tests/Feature/Schedule/ScheduleIcsTest.php
git commit -m "feat(schedule): ICS calendar export via spatie/icalendar-generator"
```

---

# Module: Catering

Roadmap 4.3. `food_orders` (`menu jsonb, opens_at, closes_at, status`), `food_order_items` (`selection jsonb, price_cents, paid_at`); `PlaceFoodOrderItem` (only within the window), `CloseFoodOrder` (collective list + cost split); Filament (create window, paid-toggle, totals); `Pages/Catering/Show.vue`.

## Task 7: Catering migrations + Models + Enum + Factories (typed menu)

**Files:**
- Create: `database/migrations/2026_07_15_110000_create_food_orders_table.php`, `2026_07_15_110100_create_food_order_items_table.php`
- Create: `app/Modules/Catering/Enums/FoodOrderStatus.php`, `app/Modules/Catering/Domain/MenuOption.php` (readonly DTO), `app/Modules/Catering/Casts/MenuCast.php`, `app/Modules/Catering/Models/{FoodOrder,FoodOrderItem}.php`
- Create: `database/factories/{FoodOrderFactory,FoodOrderItemFactory}.php`
- Create: `lang/de/catering.php` (enum labels)
- Test: `tests/Unit/Catering/{FoodOrderTest,MenuCastTest}.php`

**Interfaces:**
- Produces:
  - `FoodOrderStatus: string { Draft='draft', Open='open', Closed='closed' }` + `label()`.
  - `MenuOption` (readonly): `string $key, string $name, int $priceCents`. `toArray()`/`fromArray()`.
  - `MenuCast` implements `CastsAttributes` — DB jsonb `array<array{key,name,price_cents}>` ⇄ `list<MenuOption>`. This is the **typed-jsonb** answer to insight #9 (no raw KeyValue).
  - `FoodOrder`: `id, event_id, title, menu (MenuCast), opens_at, closes_at, status (FoodOrderStatus), timestamps`. Fillable: `event_id, title, opens_at, closes_at` (NOT `status`, NOT `menu`-as-string — `menu` is set via the cast through Actions/Filament typed input). `items(): HasMany`, `event(): BelongsTo`. Helper `isOpenNow(): bool` (`status===Open && now()∈[opens_at,closes_at]`).
  - `FoodOrderItem`: `id, food_order_id, user_id, selection (array cast: `{option_key, note?}`), price_cents int, paid_at nullable, timestamps`. Unique `(food_order_id, user_id, option_key)`? — allow multiple items per user (different options), so unique on nothing beyond PK; but prevent exact duplicate via `(food_order_id, user_id, selection->>option_key)` is overkill — keep it simple: no unique, quantity via multiple rows. Fillable: `food_order_id, user_id, selection, price_cents` set in the Action (price copied from the menu, never client-trusted). `paid_at` NOT fillable.
  - Factories with states `->open()`, `->closed()`, menu with 2–3 `MenuOption`s.

- [ ] **Step 1: Failing tests** — `MenuCastTest`: round-trips `MenuOption[]` through set/get; rejects malformed. `FoodOrderTest`: factory row has `menu` as `list<MenuOption>`, `status` enum cast, `isOpenNow()` true within window (time-travel) and false outside/when Draft.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Catering` → FAIL.

- [ ] **Step 3: Implement** — migrations, enum, DTO, cast, models, factories, enum labels (`status.draft => 'Entwurf'`, `open => 'Offen'`, `closed => 'Geschlossen'`).

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Catering && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_15_1101* app/Modules/Catering/Enums app/Modules/Catering/Domain app/Modules/Catering/Casts app/Modules/Catering/Models database/factories/FoodOrder*Factory.php lang/de/catering.php tests/Unit/Catering
git commit -m "feat(catering): food order models with typed menu cast, status enum and factories"
```

## Task 8: Catering Actions + Policy

**Files:**
- Create: `app/Modules/Catering/Actions/{PlaceFoodOrderItem,CancelFoodOrderItem,OpenFoodOrder,CloseFoodOrder}.php`, `app/Modules/Catering/Exceptions/CateringException.php`, `app/Modules/Catering/Support/CostSplit.php`, `app/Modules/Catering/Policies/{FoodOrderPolicy,FoodOrderItemPolicy}.php`
- Modify: `app/Providers/AppServiceProvider.php` (register policies)
- Test: `tests/Unit/Catering/{PlaceFoodOrderItemTest,CloseFoodOrderTest}.php`

**Interfaces:**
- Produces:
  - `PlaceFoodOrderItem::handle(FoodOrder $order, User $user, string $optionKey, ?string $note = null): FoodOrderItem` — `DB::transaction` + `lockForUpdate()` on the `FoodOrder` row first; throw `CateringException::notOpen()` unless `isOpenNow()`; resolve the `MenuOption` by key (throw `unknownOption()` if absent); copy `price_cents` from the menu (never client input); create the item.
  - `CancelFoodOrderItem::handle(FoodOrderItem $item): void` — only while the order is open.
  - `OpenFoodOrder::handle(FoodOrder $order): FoodOrder` / `CloseFoodOrder::handle(FoodOrder $order): FoodOrder` — status transitions (Draft→Open, Open→Closed) guarded; illegal transition throws.
  - `CostSplit::for(FoodOrder $order): array` — returns `['perUser' => [userId => ['name','totalCents','paidCents']], 'grandTotalCents' => int, 'byOption' => [optionKey => ['name','count','totalCents']]]`.
  - Policies: `FoodOrderPolicy` `viewAny` public; `create/update/open/close => isOrga()`. `FoodOrderItemPolicy` `create` = any auth user (window enforced in Action); `delete` = owner or orga.

- [ ] **Step 1: Failing tests** — `PlaceFoodOrderItemTest`: places within window; rejects when Draft/closed/out-of-window (German `translationKey`); price copied from menu even if a bogus price is passed; unknown option throws. `CloseFoodOrderTest`: `CostSplit` sums per-user and grand totals; paid vs unpaid tracked; `CloseFoodOrder` flips status and blocks further placement.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Catering` → FAIL.

- [ ] **Step 3: Implement** — actions, exception (translationKeys under `catering.errors.*`), cost-split, policies; register in `configureAuthorization()`.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Catering && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Catering/Actions app/Modules/Catering/Exceptions app/Modules/Catering/Support app/Modules/Catering/Policies app/Providers/AppServiceProvider.php tests/Unit/Catering
git commit -m "feat(catering): place/cancel/open/close actions with window guard and cost split"
```

## Task 9: Filament `FoodOrderResource` + items + totals

**Files:**
- Create: `app/Modules/Catering/Filament/Resources/FoodOrders/FoodOrderResource.php`, `.../Schemas/FoodOrderForm.php`, `.../Tables/FoodOrdersTable.php`, `.../Pages/{ListFoodOrders,CreateFoodOrder,EditFoodOrder}.php`, `.../RelationManagers/ItemsRelationManager.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover Catering resources), `lang/de/catering.php` (`resource`, `fields`, `admin`)
- Test: `tests/Feature/Catering/CateringResourceAccessTest.php`

**Interfaces:**
- Produces: `/admin/food-orders` CRUD. Form: `event_id`, `title`, `opens_at`/`closes_at` (DateTimePicker), and the **menu as a typed `Repeater`** (fields: `key`, `name`, `price_cents`→numeric) — **not** a KeyValue (insight #9). Open/Close header actions call `OpenFoodOrder`/`CloseFoodOrder` (`->authorize('open'/'close')`, `->requiresConfirmation()`, catch→`Notification::danger`). `ItemsRelationManager`: lists participant selections, a `toggle_paid` record action (`->authorize` orga; toggles `paid_at`), a totals header/summary (grand total + per-user via `CostSplit`).

- [ ] **Step 1: Failing test** — `CateringResourceAccessTest`: participant→403, orga→200 on `/admin/food-orders`.

- [ ] **Step 2: Run red** — → FAIL.

- [ ] **Step 3: Implement** — resource per pattern; menu Repeater; open/close actions; items relation manager; discovery entry.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Catering && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Catering/Filament app/Providers/Filament/AdminPanelProvider.php lang/de/catering.php tests/Feature/Catering/CateringResourceAccessTest.php
git commit -m "feat(catering): filament FoodOrderResource with typed menu repeater, open/close and paid toggle"
```

## Task 10: Participant catering page

**Files:**
- Create: `app/Modules/Catering/Http/CateringController.php`, `resources/js/pages/Catering/Show.vue`
- Modify: `routes/web.php` (public read `GET /events/{event:slug}/catering`; auth `POST /food-orders/{foodOrder}/items`, `DELETE /food-order-items/{item}`), `lang/de/catering.php` (`page`)
- Test: `tests/Feature/Catering/CateringPageTest.php`

**Interfaces:**
- Produces: route `catering.show` renders `Catering/Show` with the current/active order(s) for the event, the typed menu (key/name/price), the user's own items, whether the window is open, and `labels`. POST places an item (via `PlaceFoodOrderItem`, authorize `create` on `FoodOrderItem`); DELETE cancels own item. Flash-based German error on closed window.

- [ ] **Step 1: Failing test** — `CateringPageTest`: public GET renders `Catering/Show` with `labels.title` = `'Essensbestellung'`; authed POST within the window creates the user's item at the menu price; POST when closed redirects back with the German error; draft event → 404.

- [ ] **Step 2: Run red** — → FAIL.

- [ ] **Step 3: Implement** — controller, routes, Vue page (menu list with order buttons, "my order" list with total, window state), `page` labels.

- [ ] **Step 4: Green + gates** — `…/Catering && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Catering/Http resources/js/pages/Catering routes/web.php lang/de/catering.php tests/Feature/Catering/CateringPageTest.php
git commit -m "feat(catering): participant ordering page within the order window"
```

---

# Module: Voting

Roadmap 4.4. `polls/poll_options/poll_votes` (`UNIQUE(poll_id,user_id)`); `CastVote` (only open, once); Filament + `Pages/Polls/Show.vue` with live results (Reverb `event.{id}`).

## Task 11: Voting migrations + Models + Enum + Factories

**Files:**
- Create: `database/migrations/2026_07_15_120000_create_polls_table.php`, `2026_07_15_120100_create_poll_options_table.php`, `2026_07_15_120200_create_poll_votes_table.php`
- Create: `app/Modules/Voting/Enums/PollStatus.php`, `app/Modules/Voting/Models/{Poll,PollOption,PollVote}.php`
- Create: `database/factories/{PollFactory,PollOptionFactory,PollVoteFactory}.php`
- Create: `lang/de/polls.php` (enum labels)
- Test: `tests/Unit/Voting/PollTest.php`

**Interfaces:**
- Produces:
  - `PollStatus: string { Draft='draft', Open='open', Closed='closed' }` + `label()`.
  - `Poll`: `id, event_id, question, status (PollStatus), closes_at nullable, timestamps`. Fillable: `event_id, question, closes_at` (NOT status). `options(): HasMany`, `votes(): HasMany`, `event(): BelongsTo`. Helper `isOpenNow()`.
  - `PollOption`: `id, poll_id, label, sort, timestamps`. `votes(): HasMany`. `tally(): int` (count of votes).
  - `PollVote`: `id, poll_id, poll_option_id, user_id, timestamps`. Migration: `unique(['poll_id','user_id'])`. Fillable set in Action only.
  - Factories with `->open()`, `->closed()`, and a poll-with-options helper.

- [ ] **Step 1: Failing test** — `PollTest`: enum labels German; factory builds poll + options; `unique(poll_id,user_id)` enforced at DB level (second insert for same user throws `QueryException` 23505); `tally()` counts.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Voting` → FAIL.

- [ ] **Step 3: Implement** — migrations, enum, models, factories, labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Voting && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_15_12* app/Modules/Voting/Enums app/Modules/Voting/Models database/factories/Poll*Factory.php lang/de/polls.php tests/Unit/Voting
git commit -m "feat(voting): poll/option/vote models with unique-vote constraint and status enum"
```

## Task 12: `CastVote` action + `PollUpdated` broadcast + Policy

**Files:**
- Create: `app/Modules/Voting/Actions/{CastVote,OpenPoll,ClosePoll}.php`, `app/Modules/Voting/Exceptions/VotingException.php`, `app/Modules/Voting/Events/PollUpdated.php`, `app/Modules/Voting/Support/PollResults.php`, `app/Modules/Voting/Policies/PollPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (register policy), `routes/channels.php` (document `event.{id}` public channel)
- Test: `tests/Unit/Voting/CastVoteTest.php`, `tests/Feature/Voting/PollBroadcastTest.php`

**Interfaces:**
- Produces:
  - `CastVote::handle(Poll $poll, User $user, PollOption $option): PollVote` — `DB::transaction` + `lockForUpdate()` on the `Poll` first; throw `VotingException::notOpen()` unless `isOpenNow()`; `optionBelongsToPoll` guard; insert vote; on `QueryException` 23505 (narrowed) throw `VotingException::alreadyVoted()`. After commit, dispatch `PollUpdated($poll)`.
  - `PollResults::for(Poll $poll): array` — `['pollId','question','totalVotes', 'options' => [['id','label','count','percent']]]`.
  - `PollUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit` — `broadcastOn(): Channel => new Channel('event.'.$poll->event_id)`, `broadcastAs(): 'poll.updated'`, `broadcastWith(): PollResults::for($poll)`.
  - `PollPolicy`: `viewAny` public; `vote` = any auth user (open-state enforced in Action); `create/update/open/close => isOrga()`.

- [ ] **Step 1: Failing tests** — `CastVoteTest`: casts once; second vote by same user → `alreadyVoted`; vote on closed/draft poll → `notOpen`; option from another poll → guard throws. `PollBroadcastTest`: `Event::fake([PollUpdated::class])` (or `Broadcast::fake()`); casting a vote dispatches `PollUpdated` on channel `event.{eventId}` with the tallies in `broadcastWith`.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Voting tests/Feature/Voting/PollBroadcastTest.php` → FAIL.

- [ ] **Step 3: Implement** — actions, exception, results, broadcast event, policy; add the `event.{id}` channel comment to `routes/channels.php` (public, no auth callback — mirrors the tournament channel note).

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Voting tests/Feature/Voting && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Voting/Actions app/Modules/Voting/Exceptions app/Modules/Voting/Events app/Modules/Voting/Support app/Modules/Voting/Policies app/Providers/AppServiceProvider.php routes/channels.php tests/Unit/Voting/CastVoteTest.php tests/Feature/Voting/PollBroadcastTest.php
git commit -m "feat(voting): cast-vote action with once-guard and PollUpdated broadcast on event channel"
```

## Task 13: Filament `PollResource`

**Files:**
- Create: `app/Modules/Voting/Filament/Resources/Polls/PollResource.php`, `.../Schemas/PollForm.php`, `.../Tables/PollsTable.php`, `.../Pages/{ListPolls,CreatePoll,EditPoll}.php`, `.../RelationManagers/OptionsRelationManager.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover Voting resources), `lang/de/polls.php` (`resource`, `fields`, `admin`)
- Test: `tests/Feature/Voting/VotingResourceAccessTest.php`

**Interfaces:**
- Produces: `/admin/polls` CRUD. Form: `event_id`, `question`, `closes_at`, options via a typed `Repeater` (label + sort) OR the `OptionsRelationManager`. Open/Close header actions (`->authorize`, `->requiresConfirmation`, call `OpenPoll`/`ClosePoll`). Table shows question, status badge, total votes.

- [ ] **Step 1: Failing test** — `VotingResourceAccessTest`: participant→403, orga→200.

- [ ] **Step 2: Run red** — → FAIL.

- [ ] **Step 3: Implement** — resource + discovery.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Voting && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Voting/Filament app/Providers/Filament/AdminPanelProvider.php lang/de/polls.php tests/Feature/Voting/VotingResourceAccessTest.php
git commit -m "feat(voting): filament PollResource with options and open/close actions"
```

## Task 14: Participant polls page + live results (Echo)

**Files:**
- Create: `app/Modules/Voting/Http/PollPageController.php`, `resources/js/pages/Polls/Show.vue`, `resources/js/composables/useEventChannel.ts`
- Modify: `routes/web.php` (public `GET /events/{event:slug}/polls`, `GET /polls/{poll}`; auth `POST /polls/{poll}/vote`), `lang/de/polls.php` (`page`)
- Test: `tests/Feature/Voting/PollPageTest.php`

**Interfaces:**
- Consumes: `PollResults`, `useEchoPublic` (via a new `useEventChannel` composable mirroring `useTournamentChannel`).
- Produces: index lists polls for the event; `polls.show` renders `Polls/Show` with `PollResults` DTO, the user's existing vote (if any), open-state, and `labels`. POST casts a vote (authorize `vote`), redirects back. The Vue page subscribes to `event.{eventId}` for `.poll.updated` and updates result bars live (reload the `poll`/`results` prop via `router.reload({ only: [...] })` or patch from the broadcast payload).

- [ ] **Step 1: Failing test** — `PollPageTest`: public GET `polls.show` renders `Polls/Show` with German `labels.title` = `'Abstimmung'` and the options with counts; authed POST casts a vote and redirects; second POST redirects back with the German "already voted" flash; draft event → 404.

- [ ] **Step 2: Run red** — → FAIL.

- [ ] **Step 3: Implement** — controller, routes, `useEventChannel(eventId, ['.poll.updated'], cb)` composable, Vue page (result bars, vote buttons disabled after voting/closed), `page` labels.

- [ ] **Step 4: Green + gates** — `…/Voting && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Voting/Http resources/js/pages/Polls resources/js/composables/useEventChannel.ts routes/web.php lang/de/polls.php tests/Feature/Voting/PollPageTest.php
git commit -m "feat(voting): participant poll page with live results via reverb event channel"
```

---

# Module: LFG (Looking for Group)

Roadmap 4.5. `lfg_posts` (expiry via `expires_at`); CRUD actions + expiry scheduler; `Pages/Lfg/Index.vue`; Discord announcement (outbox dedup); `/lfg create|list` slash command.

## Task 15: `lfg_posts` migration + Model + Factory

**Files:**
- Create: `database/migrations/2026_07_15_130000_create_lfg_posts_table.php`, `app/Modules/Lfg/Models/LfgPost.php`, `database/factories/LfgPostFactory.php`, `lang/de/lfg.php` (base)
- Test: `tests/Unit/Lfg/LfgPostTest.php`

**Interfaces:**
- Produces: `LfgPost`: `id, event_id, user_id, game nullable, title, body nullable, slots_needed int nullable, expires_at, timestamps`. Fillable: `event_id, user_id, game, title, body, slots_needed, expires_at`. `event()`, `user()`. Scope `active()` = `where('expires_at','>',now())`. Factory: `definition()` with `expires_at` now()->addHours(3); states `->expired()`.

- [ ] **Step 1: Failing test** — `LfgPostTest`: factory row; `active()` scope excludes expired posts (time-travel); relationships resolve.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Lfg` → FAIL.

- [ ] **Step 3: Implement** — migration (index `['event_id','expires_at']`), model, factory, base labels.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Lfg && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_15_130000_create_lfg_posts_table.php app/Modules/Lfg/Models/LfgPost.php database/factories/LfgPostFactory.php lang/de/lfg.php tests/Unit/Lfg
git commit -m "feat(lfg): lfg_posts model with active scope and factory"
```

## Task 16: LFG CRUD actions + expiry scheduler + Policy

**Files:**
- Create: `app/Modules/Lfg/Actions/{CreateLfgPost,DeleteLfgPost}.php`, `app/Modules/Lfg/Exceptions/LfgException.php`, `app/Modules/Lfg/Console/PruneExpiredLfgPostsCommand.php`, `app/Modules/Lfg/Policies/LfgPostPolicy.php`
- Modify: `routes/console.php` (schedule prune), `app/Providers/AppServiceProvider.php` (register policy)
- Test: `tests/Unit/Lfg/CreateLfgPostTest.php`, `tests/Feature/Lfg/PruneExpiredLfgPostsTest.php`

**Interfaces:**
- Produces:
  - `CreateLfgPost::handle(Event $event, User $user, array $attributes): LfgPost` — requires the event be `isPubliclyVisible()`/live-ish; sets `expires_at` (default now()+`config` hours or from a validated `duration`); dispatches `LfgPostCreated` (Task 17 consumes it — declare the event here).
  - `DeleteLfgPost::handle(LfgPost $post): void`.
  - `PruneExpiredLfgPostsCommand` signature `lanomat:prune-lfg` — deletes `expires_at <= now()` posts; scheduled `everyFiveMinutes()`.
  - `LfgPostPolicy`: `viewAny` public; `create` = any auth user; `delete` = owner or orga.
  - `App\Modules\Lfg\Events\LfgPostCreated` (`Dispatchable`, carries `LfgPost`).

- [ ] **Step 1: Failing tests** — `CreateLfgPostTest`: creates with computed `expires_at`; dispatches `LfgPostCreated` (`Event::fake`). `PruneExpiredLfgPostsTest`: running `lanomat:prune-lfg` deletes only expired posts.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Lfg tests/Feature/Lfg/PruneExpiredLfgPostsTest.php` → FAIL.

- [ ] **Step 3: Implement** — actions, event, command, policy, scheduler line `Schedule::command('lanomat:prune-lfg')->everyFiveMinutes();`.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Lfg tests/Feature/Lfg && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Lfg/Actions app/Modules/Lfg/Exceptions app/Modules/Lfg/Console app/Modules/Lfg/Events app/Modules/Lfg/Policies routes/console.php app/Providers/AppServiceProvider.php tests/Unit/Lfg tests/Feature/Lfg/PruneExpiredLfgPostsTest.php
git commit -m "feat(lfg): create/delete actions, expiry prune command and policy"
```

## Task 17: LFG Discord announcement (outbox dedup)

**Files:**
- Create: `app/Modules/Lfg/Listeners/AnnounceLfgPost.php`
- Modify: `app/Providers/AppServiceProvider.php` (wire listener), `lang/de/discord.php` (announcement copy)
- Test: `tests/Feature/Lfg/AnnounceLfgPostTest.php`

**Interfaces:**
- Consumes: `LfgPostCreated`, `DiscordClient`, `DiscordOutboxGuard`, `config('services.discord.announce_channel_id')`.
- Produces: `AnnounceLfgPost implements ShouldQueue` — on `LfgPostCreated`, if an announce channel is configured, `guard->once("lfg-{$post->id}-created", 'lfg_created', fn () => $client->sendMessage($channelId, $content), channelId:, content:)`. Content = German LFG copy with title/game/user.

- [ ] **Step 1: Failing test** — `AnnounceLfgPostTest`: `fakeDiscord()`; creating a post (dispatch `LfgPostCreated`, run the listener) sends exactly one message to the announce channel; running it twice with the same post sends only once (outbox dedup). Add `Feature/Lfg` to the `Http::preventStrayRequests()` group in `tests/Pest.php`.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Lfg/AnnounceLfgPostTest.php` → FAIL.

- [ ] **Step 3: Implement** — listener, wiring `Event::listen(LfgPostCreated::class, AnnounceLfgPost::class)`, discord copy `discord.lfg.announcement`.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Lfg && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Lfg/Listeners app/Providers/AppServiceProvider.php lang/de/discord.php tests/Feature/Lfg/AnnounceLfgPostTest.php tests/Pest.php
git commit -m "feat(lfg): dedup-guarded discord announcement on new LFG post"
```

## Task 18: Participant LFG page

**Files:**
- Create: `app/Modules/Lfg/Http/LfgController.php`, `resources/js/pages/Lfg/Index.vue`
- Modify: `routes/web.php` (public `GET /events/{event:slug}/lfg`; auth `POST /events/{event:slug}/lfg`, `DELETE /lfg/{lfgPost}`), `lang/de/lfg.php` (`page`)
- Test: `tests/Feature/Lfg/LfgPageTest.php`

**Interfaces:**
- Produces: `lfg.index` renders `Lfg/Index` with active posts (DTO: id, game, title, body, slotsNeeded, user name, expiresAt, `mine` bool), a create form, and `labels`. POST creates (via `CreateLfgPost`, authorize `create`); DELETE removes own post (authorize `delete`).

- [ ] **Step 1: Failing test** — `LfgPageTest`: public GET renders `Lfg/Index` with German `labels.title` = `'Mitspieler finden'` and only active posts; authed POST creates a post; owner DELETE removes it; non-owner DELETE → 403; draft event → 404.

- [ ] **Step 2: Run red** — → FAIL.

- [ ] **Step 3: Implement** — controller, routes, Vue page (post cards + create form), `page` labels.

- [ ] **Step 4: Green + gates** — `…/Lfg && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Lfg/Http resources/js/pages/Lfg routes/web.php lang/de/lfg.php tests/Feature/Lfg/LfgPageTest.php
git commit -m "feat(lfg): participant LFG board with create and delete"
```

## Task 19: `/lfg create|list` slash command

**Files:**
- Create: `app/Modules/Discord/Interactions/Commands/LfgCommand.php`
- Modify: `app/Modules/Discord/Interactions/CommandRouter.php` (map `'lfg'`), `CommandDefinitions.php` (definition with `create`/`list` subcommands; `create` has `title` + optional `game` string options), `lang/de/discord.php`
- Test: `tests/Feature/Discord/LfgCommandTest.php`

**Interfaces:**
- Consumes: `InteractionPayload::mappedUser()`/`subcommand()`/`subcommandOption()`, `CreateLfgPost`, `LfgPost`, `CurrentEvent`.
- Produces: `/lfg list` → message of active posts for the current event; `/lfg create title:<> [game:<>]` → resolves the Discord user via `discord_id` (reject if unmapped with a German prompt to link), calls `CreateLfgPost`, returns a confirmation. Never trusts a client user id — only the `discord_id`→`User` mapping.

- [ ] **Step 1: Failing test** — `LfgCommandTest`: `/lfg list` returns a message listing an active post; `/lfg create` from a mapped user creates a post and confirms; from an unmapped Discord user returns the German "link your account" message and creates nothing.

- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Feature/Discord/LfgCommandTest.php` → FAIL.

- [ ] **Step 3: Implement** — handler with `match(subcommand)`, router map, definitions, copy.

- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Discord && composer check` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Discord/Interactions/Commands/LfgCommand.php app/Modules/Discord/Interactions/CommandRouter.php app/Modules/Discord/Interactions/CommandDefinitions.php lang/de/discord.php tests/Feature/Discord/LfgCommandTest.php
git commit -m "feat(discord): /lfg create|list slash command bound to the account mapping"
```

---

## Phase acceptance (M4)

- Feature tests per module cover window/deadline boundaries (catering window, poll open-state, LFG expiry), double-submission (second vote, duplicate registration semantics), and expiry pruning.
- Manual: a pizza collective order with 3 test users including cost split (Filament totals view); a live poll updating across two browser tabs via Reverb; an LFG post announced once in the Discord test channel.
- Green CI on all six gates; i18n-gate satisfied per task; then the **whole-branch review** (fable, base `m3`) → one consolidated fix wave → tag `m4`, per `superpowers:subagent-driven-development` and `superpowers:finishing-a-development-branch`.
- Update the roadmap M4 section with an "Erkenntnisse M4" block and close/advance the GitHub M4 milestone + board item.

## Deferred / explicitly out of scope for M4

- Reverb `allowed_origins '*'` lockdown → M5 prod (5.6).
- The typed-jsonb lesson is applied locally (MenuCast, typed Repeaters); a general reusable "typed settings" abstraction is not built here (YAGNI) — revisit if a third typed-jsonb surface appears.
- LFG "slots filled" / join mechanics beyond a free-text `slots_needed` — not in the roadmap; leave to a later phase if requested.
