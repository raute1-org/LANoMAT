# M10 — Präsenz-Basis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A live, beamer-capable **presence view** — "who is here / playing what / where can I still jump in (free slots)" — for an event, aggregating data that already exists (M2 check-in + seating, M3 match/tournament status, M6 game servers) and driven live over Reverb, reusing the M5 infoscreen scene tech.

**Architecture:** A new `app/Modules/Presence/` module whose heart is a **pure, IO-free `PresenceProjection`** (aggregates check-in + seat + current match activity per participant, plus "free slots" = tournaments still open for enrollment with remaining capacity, plus currently-live matches), exhaustively unit-tested with factories before any UI. A participant page and a beamer scene both render that projection; liveness is a single `PresenceUpdated` broadcast on the public `event.{id}` channel (fired on check-in and on match/tournament transitions) that triggers an Inertia partial reload — the same `ScenesUpdated` pattern M5 already uses. No new external systems.

**Tech Stack:** PHP 8.4 · Laravel 13 · Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue · Reverb · Pest 4.

**Scope (this phase = the "base" the user chose):** IN — the per-participant board (here / playing what / seat), free-slots (joinable tournaments), currently-live matches, Reverb liveness, participant page, beamer scene. OUT (deferred) — the **friends** filter (needs M9 friends), the **streams**/casting facet and OBS overlays (M10 second half / streaming), context-sensitive display names (M9). Use `User.name` for now.

## Global Constraints

- Code, comments, commits, docs in **English**; all UI copy in German via `lang/de/` (add `lang/de/presence.php`).
- **Conventional Commits** (`feat(presence): …`). TDD: failing test first wherever there is testable behavior; frequent commits; `composer check` (pint --test, phpstan level 8, pest — runs SEQUENTIALLY, `-d memory_limit=1G`) + the four frontend gates (`npm run lint:check`, `format:check`, `types:check`, `build`) green after **every** task.
- **Modular monolith:** new `app/Modules/Presence/{Support,Http,Events,Listeners,Policies}`; tests mirrored in `tests/{Feature,Unit}/Presence/`. Modules communicate via events + explicit read-projections — **never reach into another module's tables**; read cross-module data through the existing models/projections (`EventRegistration`, `SeatProjection`, `EntryRoster`, `BracketMatchProjection`, `ServerListProjection`), not raw table queries.
- **`Event` is the aggregate root.** Presence is event-scoped. Every authorization through a **Policy**; presence visibility mirrors the existing public event-page gate (`Event::isPubliclyVisible()`), no client-supplied user IDs trusted.
- **Presence read model is pure domain code** (`app/Modules/Presence/Support/`) — no IO, no broadcasting, no request state; exhaustively Pest-tested with factories before any UI/broadcast work (mirrors the M3 bracket-engine discipline).
- **Signalpult design system is binding** (`docs/design.md`): semantic role tokens only (no raw hex), Space Grotesk + JetBrains Mono (mono for machine data — seat labels, counts, times), rationed amber accent, **`LiveIndicator` for anyone currently in a live match**, all four states (empty = "noch niemand eingecheckt", loading, error, normal), calm participant page / **loud beamer scene**, Rams quality floor (responsive, visible focus, `prefers-reduced-motion`, AA contrast). **Invoke the `frontend-design` skill first** for every UI task (10.2, 10.4).
- Public channel `event.{id}` carries **no private data** — the presence payload trigger is a bare signal; the projection is re-fetched via an authorized controller reload.

## Design decisions (locked for this phase)

- **"Free slots"** = tournaments of the event whose `status ∈ {Enrollment, CheckIn}` with **remaining capacity** (a positive `capacity − current_entries`). This is the actionable LAN meaning: "games you can still sign up for." (Free *seats* and free *server* slots are NOT this phase's free-slots facet.)
- **"Playing what"** for a participant = they are in the roster (`EntryRoster::usersForMatch`) of a `GameMatch` whose `status ∈ {Warmup, Ready}` **and** whose tournament is `Live` — surfaced as "{game} · {matchLabel}". Otherwise the participant is "idle" (checked in, not currently in a live match). (`MatchStatus::Live` does not exist; `Ready`+`Warmup` with a live tournament is the "currently playing" signal, consistent with `ScenePayload::upcomingMatchesData`.)
- **Presence liveness** is a single event-scoped `PresenceUpdated` broadcast (bare `.presence.updated` signal). It fires on check-in (new broadcast added to `CheckInRegistration`) and via a listener on `MatchReady`/`MatchWentLive`/`MatchCompleted`/`TournamentStarted` (each maps its tournament → event id). The frontend does an Inertia partial reload of the `presence` prop, mirroring M5's `ScenesUpdated` handling.

## File Structure

**New:**
- `app/Modules/Presence/Support/PresenceProjection.php` — pure aggregator, `forEvent(Event): PresenceBoard`
- `app/Modules/Presence/Support/PresenceBoard.php` + `ParticipantPresence.php` + `FreeSlot.php` + `LiveMatchPresence.php` — readonly DTOs (or one file with nested value objects; keep each focused)
- `app/Modules/Presence/Events/PresenceUpdated.php` — `ShouldBroadcast` on `event.{id}`, name `presence.updated`
- `app/Modules/Presence/Listeners/BroadcastPresenceOnTournamentActivity.php` — re-broadcasts on match/tournament events
- `app/Modules/Presence/Http/PresencePageController.php` — participant page + prop
- `app/Modules/Presence/Policies/PresencePolicy.php` (or reuse the event-visibility gate inline; see 10.2)
- `resources/js/pages/Presence/Index.vue`
- `resources/js/pages/Screen/scenes/ScenePresence.vue` (beamer scene component; match the existing `Screen/scenes/` location found during 10.4)
- `resources/js/types/presence.ts`
- `lang/de/presence.php`
- Tests: `tests/Unit/Presence/PresenceProjectionTest.php`, `tests/Feature/Presence/{PresencePageTest,PresenceBroadcastTest,PresenceSceneTest}.php`

**Modified:**
- `app/Modules/Registration/Actions/CheckInRegistration.php` — dispatch `PresenceUpdated` after stamping `checked_in_at`
- `app/Providers/AppServiceProvider.php` — register the presence listener on the four tournament events
- `app/Modules/Infoscreen/Enums/SceneType.php` — add `Presence` case
- `app/Modules/Infoscreen/Support/ScenePayload.php` — add `presenceData()` builder
- `resources/js/pages/Screen/Show.vue` — map the new scene type to `ScenePresence`
- `routes/web.php` — presence page route
- the event page / participant nav — a link to the presence view

**Interfaces produced (referenced across tasks):**
- `PresenceProjection::forEvent(Event $event): PresenceBoard`
- `PresenceBoard { participants: ParticipantPresence[]; freeSlots: FreeSlot[]; liveMatches: LiveMatchPresence[]; checkedInCount: int }`
- `ParticipantPresence { name: string; avatarUrl: ?string; seatLabel: ?string; activity: ?string /* "{game} · {matchLabel}" or null=idle */; isPlaying: bool }`
- `FreeSlot { tournamentId: int; name: string; game: ?string; openSpots: int }`
- `LiveMatchPresence { matchId: int; game: ?string; label: string; players: string[] }`
- `PresenceUpdated(int $eventId)` broadcasting `.presence.updated` on `event.{id}` (no payload body)

---

### Task 10.1: `PresenceProjection` — pure aggregation core

The IO-free heart. Aggregates check-in + seat + current-match activity per participant, "free slots", and live matches into readonly DTOs. No broadcasting, no HTTP, no request state. Exhaustively unit-tested with factories BEFORE any UI/broadcast task.

**Files:**
- Create: `app/Modules/Presence/Support/PresenceProjection.php`, `PresenceBoard.php`, `ParticipantPresence.php`, `FreeSlot.php`, `LiveMatchPresence.php`
- Test: `tests/Unit/Presence/PresenceProjectionTest.php`

**Interfaces:**
- Consumes (read-only, via existing surfaces): `EventRegistration` (`checked_in_at`, `status`, `user`), `SeatAssignment`/`Seat` (occupant→seat label), `Tournament` (`status`, capacity), `TournamentEntry` (count for capacity), `GameMatch` (`status`, entries), `EntryRoster::usersForMatch`, `Game` name.
- Produces: the DTOs + `PresenceProjection::forEvent(Event): PresenceBoard`.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Presence/PresenceProjectionTest.php`

Cover with factories against a real `Event`:
- Only **checked-in, non-cancelled** registrations appear in `participants` (a pending/cancelled or not-checked-in registration is excluded). `checkedInCount` matches.
- A participant assigned to a seat has `seatLabel` = the seat's label; an unseated checked-in participant has `seatLabel === null`.
- A participant in the roster of a `Warmup`/`Ready` match of a **Live** tournament has `isPlaying === true` and `activity === "{game} · {label}"`; a checked-in participant not in any live match is `isPlaying === false`, `activity === null`.
- `freeSlots` lists a tournament in `Enrollment` with capacity 8 and 3 entries as `openSpots === 5`; excludes a `Live`/`Finished`/`Draft` tournament and one already full (`openSpots` would be 0).
- `liveMatches` lists the live/warmup matches with their `players` (union of both entries' rosters by name).
- Deterministic ordering (e.g. participants by name) so assertions are stable.

Example skeleton:

```php
<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\Presence\Support\PresenceProjection;
// ... factories for Event, EventRegistration, Seat, Tournament, TournamentEntry, GameMatch, Game

it('lists only checked-in non-cancelled participants', function () {
    $event = Event::factory()->create();
    // checked-in confirmed → included; pending/not-checked-in → excluded
    // ... arrange
    $board = PresenceProjection::forEvent($event);
    expect($board->checkedInCount)->toBe(1)
        ->and($board->participants)->toHaveCount(1)
        ->and($board->participants[0]->name)->toBe('Ada');
});
```

- [ ] **Step 2: Run it, verify it fails** — `./vendor/bin/pest tests/Unit/Presence/PresenceProjectionTest.php` → FAIL (classes missing). Confirm the exact factory namespaces first by reading an existing Seating/Tournaments feature test.

- [ ] **Step 3: Implement the DTOs** — small `final readonly` value objects with `toArray(): array` (camelCase keys matching the TS types in 10.2/10.4).

- [ ] **Step 4: Implement `PresenceProjection::forEvent`** — build with eager-loaded queries reusing the documented chains: participants from `EventRegistration::where('event_id',…)->where('status','!=',Cancelled)->whereNotNull('checked_in_at')->with('user','seatAssignment…')`; seat label via the `Seat→assignment→registration` chain (reuse `SeatProjection`'s relation path); activity by indexing live/warmup matches of the event's Live tournaments → `EntryRoster::usersForMatch` → a `user_id → match` map; free slots from tournaments in `{Enrollment,CheckIn}` with `capacity - entries()->count()`. Keep it one focused class; if it grows past one clear responsibility, extract a private query helper but don't split the module.

- [ ] **Step 5: Run tests, verify green** — `./vendor/bin/pest tests/Unit/Presence/PresenceProjectionTest.php` → PASS.

- [ ] **Step 6: Static analysis + commit**

Run: `composer check`.

```bash
git add app/Modules/Presence tests/Unit/Presence
git commit -m "feat(presence): pure PresenceProjection aggregating check-in, seat, activity, free slots"
```

---

### Task 10.2: Presence participant page

A calm participant-facing page rendering the board, with client-side filters (free-slots view, playing-now view). Reachable from the event page. Visibility mirrors the event page's public-visibility gate.

**Files:**
- Create: `app/Modules/Presence/Http/PresencePageController.php`, `resources/js/pages/Presence/Index.vue`, `resources/js/types/presence.ts`, `lang/de/presence.php`
- Modify: `routes/web.php`, the event page nav (link), `app/Modules/Presence/Policies/PresencePolicy.php` if a policy is used
- Test: `tests/Feature/Presence/PresencePageTest.php`

**Interfaces:**
- Consumes: `PresenceProjection` (10.1).
- Produces: route `presence.show` (`/events/{event}/presence`), Inertia prop `presence: PresenceBoard` (via `toArray()`).

- [ ] **Step 1: Invoke the `frontend-design` skill**, design the presence board against `docs/design.md`: a participant list (name + seat in mono + a `LiveIndicator` when `isPlaying`), a "freie Slots" section (joinable tournaments with `openSpots` in mono + a join link to the tournament), a "läuft gerade" live-matches section; empty state "Noch niemand eingecheckt"; filters as quiet toggles ("nur freie Slots", "spielt gerade").

- [ ] **Step 2: Write the failing test** — `PresencePageTest`: for a publicly-visible event with a checked-in seated participant and one open-enrollment tournament, the page renders with `presence.participants`/`presence.freeSlots` populated and correct fields; a **draft/hidden** event returns 404 (mirror `abort_unless($event->isPubliclyVisible(), 404)` used elsewhere); assert the Inertia component + prop shape.

- [ ] **Step 3: Run it, verify it fails** — FAIL (route/controller missing).

- [ ] **Step 4: Implement controller + route** — `PresencePageController::show(Event $event)`: `abort_unless($event->isPubliclyVisible(), 404)` (confirm the exact method name from the existing event page controller), then `Inertia::render('Presence/Index', ['event' => [...], 'presence' => PresenceProjection::forEvent($event)->toArray(), 'labels' => __('presence')])`. Route in `routes/web.php`.

- [ ] **Step 5: Build the Vue page + type + German copy** — `Presence/Index.vue` (`<script setup lang="ts">`, no `<style>`, Tailwind + shadcn-vue, semantic tokens, mono only on seat/counts), `resources/js/types/presence.ts` matching the DTO `toArray()` keys, `lang/de/presence.php`. Add a nav link from the event page.

- [ ] **Step 6: Run it, verify green** — `./vendor/bin/pest tests/Feature/Presence/PresencePageTest.php`.

- [ ] **Step 7: Verify UI** — `preview_start`, navigate to a seeded event's `/presence`, `preview_screenshot` light + dark, confirm empty + populated states, focus, mono only on machine data. (If seeding a full presence state is impractical in preview, rely on the feature test + static design compliance and note it — same deferral basis as M8.)

- [ ] **Step 8: All gates + commit**

Run: `composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build`.

```bash
git add -A
git commit -m "feat(presence): participant presence page (board, free slots, live matches, filters)"
```

---

### Task 10.3: `PresenceUpdated` broadcast + liveness wiring

Make the page live: one event-scoped broadcast fired on check-in and on match/tournament transitions; the frontend partial-reloads the `presence` prop.

**Files:**
- Create: `app/Modules/Presence/Events/PresenceUpdated.php`, `app/Modules/Presence/Listeners/BroadcastPresenceOnTournamentActivity.php`
- Modify: `app/Modules/Registration/Actions/CheckInRegistration.php`, `app/Providers/AppServiceProvider.php`, `resources/js/pages/Presence/Index.vue`
- Test: `tests/Feature/Presence/PresenceBroadcastTest.php`

**Interfaces:**
- Consumes: existing `MatchReady`/`MatchWentLive`/`MatchCompleted`/`TournamentStarted` (each exposes its tournament → `event_id`), `CheckInRegistration`.
- Produces: `PresenceUpdated(int $eventId)` → `.presence.updated` on `event.{id}`.

- [ ] **Step 1: Write the failing test** — `PresenceBroadcastTest` (use `Event::fake([PresenceUpdated::class])`): checking in a registration dispatches `PresenceUpdated` with the right `eventId`; firing `MatchReady` (and one of the others) for a match whose tournament belongs to the event dispatches `PresenceUpdated` for that event. Assert channel name `event.{id}` and broadcast-as `presence.updated` via the event's `broadcastOn`/`broadcastAs` (a small direct unit assertion on the event object).

- [ ] **Step 2: Run it, verify it fails** — FAIL.

- [ ] **Step 3: Implement `PresenceUpdated`** — `implements ShouldBroadcast` (or `ShouldDispatchAfterCommit` like the tournament events — match the M3/M5 convention), `broadcastOn(): Channel` = `new Channel("event.{$this->eventId}")`, `broadcastAs(): string` = `'presence.updated'`, empty `broadcastWith()`.

- [ ] **Step 4: Wire the sources** — in `CheckInRegistration::handle`, after stamping `checked_in_at`, `PresenceUpdated::dispatch($registration->event_id)`. Create `BroadcastPresenceOnTournamentActivity` that, for each of the four events, resolves the tournament's `event_id` and dispatches `PresenceUpdated`; register the four `Event::listen(...)` lines in `AppServiceProvider` (alongside the existing voice/gameserver listeners).

- [ ] **Step 5: Frontend live reload** — in `Presence/Index.vue`, `useEventChannel(eventId, ['.presence.updated'], () => router.reload({ only: ['presence'] }))` (mirror the `ScenesUpdated`/tournament-channel reload pattern).

- [ ] **Step 6: Run tests, verify green** — `./vendor/bin/pest tests/Feature/Presence/PresenceBroadcastTest.php`, then `composer check` + `npm run types:check && npm run build`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(presence): live PresenceUpdated broadcast on check-in + match/tournament activity"
```

---

### Task 10.4: Beamer `Presence` scene

A loud, glanceable presence scene for the beamer, reusing the M5 scene projection + rotation. "Wer ist da / spielt gerade / freie Slots" at a distance.

**Files:**
- Modify: `app/Modules/Infoscreen/Enums/SceneType.php`, `app/Modules/Infoscreen/Support/ScenePayload.php`, `resources/js/pages/Screen/Show.vue`
- Create: `resources/js/pages/Screen/scenes/ScenePresence.vue` (confirm the actual scenes dir from `Show.vue`'s scene map)
- Test: `tests/Feature/Presence/PresenceSceneTest.php`

**Interfaces:**
- Consumes: `PresenceProjection` (10.1), the M5 `SceneType`/`ScenePayload` pattern.
- Produces: `SceneType::Presence`; `ScenePayload::for` returns presence `data` for a Presence scene.

- [ ] **Step 1: Write the failing test** — `PresenceSceneTest`: a `InfoscreenScene` of type `Presence` for an event yields `ScenePayload::for($scene)` with `type === 'presence'` and a `data` payload carrying `participants`/`freeSlots`/`liveMatches` (or a beamer-tailored subset — decide the smallest glanceable set: checked-in count, currently-playing list, free-slots list). Assert the shape.

- [ ] **Step 2: Run it, verify it fails** — FAIL (enum case missing).

- [ ] **Step 3: Add `SceneType::Presence`** — add the case + its label/metadata exactly as sibling cases do (read the enum first; some cases are rotation types, some override-only — Presence is a **rotation** type).

- [ ] **Step 4: Add `presenceData()` to `ScenePayload`** — reuse `PresenceProjection::forEvent($scene->event)`; return the beamer subset. Wire it into the `for()` type switch.

- [ ] **Step 5: Build `ScenePresence.vue`** — invoke `frontend-design` for the **loud beamer** treatment (large type, `LiveIndicator` on currently-playing, high-contrast, glanceable; still semantic tokens, no `<style>`). Map `SceneType.Presence → ScenePresence` in `Screen/Show.vue`'s scene component map. German copy from `lang/de/presence.php`.

- [ ] **Step 6: Run tests, verify green** — `./vendor/bin/pest tests/Feature/Presence/PresenceSceneTest.php`, then `composer check` + all four frontend gates.

- [ ] **Step 7: Verify beamer UI** — `preview_start`, navigate to `/screen/{event}` with a Presence scene enabled (or force it), screenshot; confirm loud/glanceable + live indicator. (Defer if seeding is impractical; note it.)

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(presence): beamer presence scene (SceneType::Presence + ScenePayload + ScenePresence.vue)"
```

---

## Phase close-out (after Task 10.4)

- [ ] **Whole-branch review** on **opus**, base = tag `m8`, via `scripts/review-package m8 HEAD`. Named cross-cutting checks: presence read-model purity (no IO in `Support/`), module boundary (Presence reads other modules only through their models/projections, never raw foreign tables), the `PresenceUpdated` broadcast carries no private data, activity/free-slots semantics match the locked decisions, design-system compliance on both UI surfaces. Consolidate into ONE fix wave; re-review.
- [ ] **`composer check`** + all four frontend gates green; full suite green.
- [ ] **Roadmap sync** — mark the M10 presence-base done in `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`, add an "Erkenntnisse M10 (Präsenz-Basis)" block; note that the **friends filter, streams/casting facet, and OBS overlays remain** (M10 second half, needs M9 friends / streaming).
- [ ] **GitHub sync** — milestone #11 (M10) stays OPEN (only the presence base is done, not casting); add a comment/checklist noting base-presence shipped, casting deferred. Update Board #2.
- [ ] **Tag** — decide with the phase state: if only the base ships, a partial tag like `m10-presence` is reasonable (full `m10` after casting). Push to origin.
- [ ] **Update memory** — handoff (top-line/NEXT), a `m10-presence-decisions.md`, `MEMORY.md` index line.

## Self-Review (against the roadmap)

**Coverage:** roadmap M10 presence view "wer ist da / spielt was / freie Slots / wer streamt" with filters, beamer-capable, Reverb-driven, from M2/M3/M6 data → 10.1 (aggregation) + 10.2 (page + filters) + 10.3 (Reverb) + 10.4 (beamer). "wer streamt" + friends filter explicitly deferred (needs M9/streaming) and called out in scope + close-out. Base view valuable without them — matches the roadmap's own note.

**Type consistency:** `PresenceBoard`/`ParticipantPresence`/`FreeSlot`/`LiveMatchPresence` (10.1) drive the `toArray()` keys read by `presence.ts` (10.2), the scene `data` (10.4), and are re-fetched on `.presence.updated` (10.3). `PresenceUpdated(int $eventId)` consistent across dispatch sites.

**No placeholders:** each step names exact files and the concrete behavior; the two genuinely-open modeling choices (free-slots meaning, "playing" signal) are locked in the Design-decisions section, not left to the implementer.
