# Friends System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A cross-event mutual-friendship system (request → accept), LAN-native friend suggestions, basic blocking, in-app notifications, profile-page integration, and the deferred M10 presence "nur Freunde" filter.

**Architecture:** New cross-user module `app/Modules/Friends/` (like Teams — NOT under the Event aggregate). A `friendships` table holds the request/accept state machine (one row per pair); a `user_blocks` table holds one-directional block records. Actions own every state transition; a `FriendshipPolicy` gates them. `FriendSuggestions` is a pure-ish read-model aggregating existing M1/M2/M3 models (the precedent is M10's `PresenceProjection`). No new Reverb channel — friend events surface via the existing M2 in-app notification bell.

**Tech Stack:** PHP 8.4 · Laravel 13 · Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue · Pest 4 · PostgreSQL 16. Reuses the M2 Notifications module and the M10 Presence module.

## Global Constraints

- Code / comments / commits / docs in **English**; all UI copy in German via `lang/de/` (new `lang/de/friends.php`).
- **Conventional Commits** (`feat(friends): …`); TDD — failing test first; frequent commits. `composer check` (pint --test, phpstan **level 8**, pest **SEQUENTIAL** — never `--parallel`, which hits spurious "table does not exist"/deadlock flakiness on the shared test DB; if a run shows those, check `ps aux | grep vendor/bin/pest` for stray processes first) must be green after every task. Frontend gates (`npm run lint:check`, `format:check`, `types:check`, `build`) green for any Vue change.
- **External systems only through contracts + Fakes** — never a real API in tests. (Friends is DB-only; the only external touchpoint is optional Discord-mirror notifications via the existing `DiscordChannel` + `FakeDiscordClient`.)
- **Every authorization goes through a Policy.** Never trust a client-supplied user id — the acting user is always `auth()->user()`; the other party is resolved from a route-bound `User` model or validated id, and every transition is authorized (only the addressee accepts; only the requester cancels; only a participant removes; only the blocker unblocks).
- New Friends test files use `uses(RefreshDatabase::class)` (the app-wide test group requires per-file declaration).
- **Design system is binding** (`docs/design.md` "Signalpult"): semantic role utilities (no raw hex), rationed amber, all four states (empty/loading/error/normal), visible focus, `prefers-reduced-motion`, AA. **Invoke the `frontend-design` skill before Task 5 and Task 6 (UI).**
- **Friendship model = mutual** (request→accept, symmetric). **Suggestions = LAN-native** (shared events / teams / tournaments only — no external provider calls this phase). **Blocking = included** (block prevents requests + hides both parties in suggestions/filter + removes any existing friendship/pending).
- **Privacy on the public presence channel:** friend status is per-viewer private — it must be delivered ONLY via the authorized `PresencePageController` reload, NEVER in the public `event.{id}` Reverb payload (which stays empty, per M10).

---

## File Structure

New module `app/Modules/Friends/{Models,Actions,Policies,Support,Notifications,Http,Enums}` + tests under `tests/{Feature,Unit}/Friends/`.

- `app/Modules/Friends/Enums/FriendshipStatus.php` — `Pending`, `Accepted`.
- `app/Modules/Friends/Models/Friendship.php`, `app/Modules/Friends/Models/UserBlock.php`.
- `database/migrations/..._create_friendships_table.php`, `..._create_user_blocks_table.php`.
- `database/factories/FriendshipFactory.php`, `UserBlockFactory.php`.
- `app/Modules/Friends/Actions/{SendFriendRequest,RespondToFriendRequest,CancelFriendRequest,RemoveFriend,BlockUser,UnblockUser}.php`.
- `app/Modules/Friends/Policies/FriendshipPolicy.php` (+ block authorization).
- `app/Modules/Friends/Support/FriendService.php` (read helpers: are-friends, pending-between, is-blocked-either-way, friend-user-ids) and `app/Modules/Friends/Support/FriendSuggestions.php` (LAN-native ranked suggestions).
- `app/Modules/Friends/Notifications/{FriendRequestReceived,FriendRequestAccepted}.php`.
- `app/Modules/Friends/Http/FriendsController.php` (+ request/response endpoints).
- `resources/js/pages/Friends/Index.vue` + `resources/js/components/friends/*`.
- `lang/de/friends.php`.
- Modify: `app/Models/User.php` (relations + helpers), `routes/web.php` (friends routes + profile-show integration), the profile-show Vue page (`resources/js/pages/Profile/…`), `app/Modules/Presence/Support/ParticipantPresence.php` + `PresenceProjection.php` + `Http/PresencePageController.php` + `resources/js/pages/Presence/Index.vue` (Task 8), `docs/architecture.md`, roadmap.

---

### Task 1: Schema + models — `friendships` and `user_blocks`

**Files:**
- Create: `app/Modules/Friends/Enums/FriendshipStatus.php`, `app/Modules/Friends/Models/Friendship.php`, `app/Modules/Friends/Models/UserBlock.php`
- Create: migrations `..._create_friendships_table.php`, `..._create_user_blocks_table.php`
- Create: `database/factories/FriendshipFactory.php`, `database/factories/UserBlockFactory.php`
- Modify: `app/Models/User.php` (relations + query helpers)
- Test: `tests/Feature/Friends/FriendshipSchemaTest.php`, `tests/Unit/Friends/FriendshipModelTest.php`

**Interfaces:**
- Produces: `FriendshipStatus` enum (`pending`, `accepted`).
- Produces: `Friendship` model — `$fillable = ['requester_id','addressee_id','status']`; casts `status => FriendshipStatus::class`; `requester(): BelongsTo`, `addressee(): BelongsTo`; scope `betweenUsers($a,$b)` (matches either direction); helper `otherUser(User $me): User`.
- Produces: `UserBlock` model — `$fillable = ['blocker_id','blocked_id']`; `blocker()`/`blocked()` BelongsTo.
- Produces on `User`: `friendships()` (HasMany where requester OR addressee — implement as a query helper `friendshipsQuery()` since Eloquent HasMany can't OR two FKs; expose `acceptedFriends(): Collection<User>`, `incomingRequests(): Collection<Friendship>` (addressee=me, pending), `outgoingRequests(): Collection<Friendship>` (requester=me, pending), `blockedUsers(): Collection<User>`, `hasBlocked(User): bool`, `isBlockedBy(User): bool`).
- Schema: `friendships(id, requester_id FK users cascade, addressee_id FK users cascade, status, timestamps)`, unique(`requester_id`,`addressee_id`), index on `addressee_id,status`. `user_blocks(id, blocker_id FK users cascade, blocked_id FK users cascade, timestamps)`, unique(`blocker_id`,`blocked_id`).

- [ ] **Step 1: Failing schema test**

```php
// tests/Feature/Friends/FriendshipSchemaTest.php
it('enforces a single friendship row per ordered pair', function () {
    $a = User::factory()->create(); $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id]);
    expect(fn () => Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id]))
        ->toThrow(QueryException::class);
});

it('resolves accepted friends in both directions', function () {
    $a = User::factory()->create(); $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => FriendshipStatus::Accepted]);
    expect($a->acceptedFriends()->pluck('id'))->toContain($b->id)
        ->and($b->acceptedFriends()->pluck('id'))->toContain($a->id);
});
```

- [ ] **Step 2: Run → FAIL** (no tables/models).
- [ ] **Step 3:** write enum, migrations, models, factories, and the `User` relations/helpers (per Interfaces).
- [ ] **Step 4: Model unit test** — `betweenUsers` matches both directions; `otherUser` returns the correct side; `hasBlocked`/`isBlockedBy` correct.
- [ ] **Step 5: Run → green.** `composer check`.
- [ ] **Step 6: Commit** — `feat(friends): friendships + user_blocks schema, models, and user relations`.

---

### Task 2: Friend-request lifecycle actions + policy

**Files:**
- Create: `app/Modules/Friends/Actions/{SendFriendRequest,RespondToFriendRequest,CancelFriendRequest,RemoveFriend}.php`
- Create: `app/Modules/Friends/Policies/FriendshipPolicy.php`
- Create: `app/Modules/Friends/Support/FriendService.php`
- Create: `app/Modules/Friends/Exceptions/FriendshipException.php`
- Modify: `app/Providers/AppServiceProvider.php` (register `FriendshipPolicy`)
- Test: `tests/Feature/Friends/FriendRequestFlowTest.php`

**Interfaces:**
- Consumes: `Friendship`, `UserBlock`, `FriendshipStatus` (Task 1).
- Produces: `FriendService` — `areFriends(User,User): bool`, `pendingBetween(User,User): ?Friendship`, `blockedEitherWay(User,User): bool`, `friendUserIds(User): array<int>`.
- Produces: `SendFriendRequest::handle(User $requester, User $addressee): Friendship` — guards (throw `FriendshipException`): not self; not already friends; no pending either direction; neither has blocked the other. **Auto-accept:** if a *reverse* pending request exists (addressee already requested requester), accept it instead of creating a duplicate → returns the now-accepted row. Otherwise create `pending`.
- Produces: `RespondToFriendRequest::handle(User $actor, Friendship $friendship, bool $accept): void` — only the addressee may respond (policy); accept → `status = Accepted`; decline → delete the row.
- Produces: `CancelFriendRequest::handle(User $actor, Friendship $friendship): void` — only the requester, only while pending; deletes.
- Produces: `RemoveFriend::handle(User $actor, User $other): void` — deletes the accepted friendship (either direction) if the actor is a participant.
- Produces: `FriendshipPolicy` — `respond` = actor is addressee && pending; `cancel` = actor is requester && pending; `remove`/`view` = actor is a participant.

- [ ] **Step 1: Failing test** (happy path + guards + auto-accept):

```php
// tests/Feature/Friends/FriendRequestFlowTest.php
it('creates a pending request then accepts it into a mutual friendship', function () {
    $a = User::factory()->create(); $b = User::factory()->create();
    $req = app(SendFriendRequest::class)->handle($a, $b);
    expect($req->status)->toBe(FriendshipStatus::Pending);
    app(RespondToFriendRequest::class)->handle($b, $req, accept: true);
    expect(app(FriendService::class)->areFriends($a, $b))->toBeTrue();
});

it('auto-accepts when the reverse request already exists', function () {
    $a = User::factory()->create(); $b = User::factory()->create();
    app(SendFriendRequest::class)->handle($a, $b);           // a → b pending
    $res = app(SendFriendRequest::class)->handle($b, $a);    // b → a should auto-accept
    expect($res->status)->toBe(FriendshipStatus::Accepted)
        ->and(Friendship::count())->toBe(1);
});

it('refuses a request to self, to an existing friend, or across a block', function () {
    $a = User::factory()->create(); $b = User::factory()->create();
    expect(fn () => app(SendFriendRequest::class)->handle($a, $a))->toThrow(FriendshipException::class);
    UserBlock::factory()->create(['blocker_id' => $b->id, 'blocked_id' => $a->id]);
    expect(fn () => app(SendFriendRequest::class)->handle($a, $b))->toThrow(FriendshipException::class);
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement actions, `FriendService`, `FriendshipException`, policy; register the policy. **Step 4:** green + `composer check`.
- [ ] **Step 5: Commit** — `feat(friends): request/accept/decline/cancel/remove actions with policy`.

---

### Task 3: Blocking

**Files:**
- Create: `app/Modules/Friends/Actions/{BlockUser,UnblockUser}.php`
- Modify: `FriendshipPolicy` (or a small `UserBlockPolicy`) for unblock ownership
- Test: `tests/Feature/Friends/BlockUserTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–2.
- Produces: `BlockUser::handle(User $blocker, User $blocked): UserBlock` — not self; idempotent (return existing block if present); **side effect:** deletes any `Friendship` between them (accepted or pending) in the same transaction.
- Produces: `UnblockUser::handle(User $blocker, User $blocked): void` — deletes the block (blocker-owned only).
- The block-guard in `SendFriendRequest` (Task 2) already consults `FriendService::blockedEitherWay`.

- [ ] **Step 1: Failing test:**

```php
// tests/Feature/Friends/BlockUserTest.php
it('blocking removes any existing friendship and prevents new requests', function () {
    $a = User::factory()->create(); $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => FriendshipStatus::Accepted]);
    app(BlockUser::class)->handle($a, $b);
    expect(app(FriendService::class)->areFriends($a, $b))->toBeFalse()
        ->and(app(FriendService::class)->blockedEitherWay($a, $b))->toBeTrue();
    expect(fn () => app(SendFriendRequest::class)->handle($b, $a))->toThrow(FriendshipException::class);
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement (transaction for the friendship-removal side effect). **Step 4:** green + `composer check`.
- [ ] **Step 5: Commit** — `feat(friends): block/unblock with friendship teardown`.

---

### Task 4: LAN-native friend suggestions (read-model)

**Files:**
- Create: `app/Modules/Friends/Support/FriendSuggestions.php`
- Test: `tests/Unit/Friends/FriendSuggestionsTest.php`

**Interfaces:**
- Consumes: `FriendService`; existing models `EventRegistration` (M2), `TeamMember`/`Team` (M3), `TournamentEntry`/`EntryRoster` (M3) — read via their Eloquent models (the M10 `PresenceProjection` precedent for a cross-module read-model; do NOT issue raw queries against another module's tables).
- Produces: `FriendSuggestions::for(User $user, int $limit = 20): Collection<array{user: User, shared: int, reasons: array<string>}>` — candidate users who share ≥1 LAN context with `$user` (co-registered at the same event, co-member of a team, co-entrant in a tournament), **excluding** self, current friends, users with a pending request either direction, and users blocked either direction. Ranked by shared-context count desc. Pure read (no writes, no IO beyond DB reads).

- [ ] **Step 1: Failing test:**

```php
// tests/Unit/Friends/FriendSuggestionsTest.php
it('suggests co-attendees and excludes self, friends, pending, and blocked', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create();
    $coAttendee = User::factory()->create();
    $friend = User::factory()->create();
    $blocked = User::factory()->create();
    foreach ([$me, $coAttendee, $friend, $blocked] as $u) {
        EventRegistration::factory()->for($event)->for($u)->create();
    }
    Friendship::factory()->create(['requester_id' => $me->id, 'addressee_id' => $friend->id, 'status' => FriendshipStatus::Accepted]);
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $blocked->id]);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->toContain($coAttendee->id)
        ->not->toContain($me->id)->not->toContain($friend->id)->not->toContain($blocked->id);
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement (union the three shared-context sources, group+count, filter exclusions, sort, limit). Guard against N+1 (eager-load the candidate users). **Step 4:** green + `composer check`.
- [ ] **Step 5: Commit** — `feat(friends): LAN-native friend suggestions read-model`.

---

### Task 5: Friends page (Inertia + Vue)

> **Invoke the `frontend-design` skill first**; design against `docs/design.md`; mirror `resources/js/pages/settings/Profile.vue` house style.

**Files:**
- Create: `app/Modules/Friends/Http/FriendsController.php`
- Create: `resources/js/pages/Friends/Index.vue` + `resources/js/components/friends/{FriendRow,RequestRow,SuggestionRow}.vue`
- Create: `lang/de/friends.php`
- Modify: `routes/web.php` (authenticated friends routes), nav (add a "Freunde" entry where the app's primary nav lives)
- Test: `tests/Feature/Friends/FriendsPageTest.php`

**Interfaces:**
- Produces: `FriendsController@index` → Inertia `Friends/Index` with props `friends` (accepted, `{id,name,avatarUrl}`), `incoming` (pending where I'm addressee, `{friendshipId, from:{id,name,avatarUrl}}`), `outgoing` (pending where I'm requester), `suggestions` (from `FriendSuggestions`, `{id,name,avatarUrl,shared,reasons}`), `blocked`. **No email/tokens/private fields** — only display fields.
- Produces: POST/DELETE endpoints wired to the Task 2/3 actions: `friends.request` (POST, addressee id in body), `friends.respond` (PATCH friendship, accept bool), `friends.cancel` (DELETE friendship), `friends.remove` (DELETE, other user), `friends.block` (POST), `friends.unblock` (DELETE). Each authorizes via `FriendshipPolicy` and takes the actor from `auth()->user()`.
- UI: four sections (requests / friends / suggestions / blocked), each with its empty state; calm register; amber (if any) rationed to the incoming-request call-to-action only; visible focus; reduced-motion.

- [ ] **Step 1: Failing test** — page renders sections, no private data leaked:

```php
// tests/Feature/Friends/FriendsPageTest.php
it('shows friends, incoming requests, and suggestions without leaking private fields', function () {
    $me = User::factory()->create(); $friend = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $friend->id, 'addressee_id' => $me->id, 'status' => FriendshipStatus::Accepted]);
    $this->actingAs($me)->get('/friends')
        ->assertInertia(fn ($p) => $p->component('Friends/Index')
            ->has('friends', 1)
            ->where('friends.0.id', $friend->id)
            ->missing('friends.0.email'));
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement controller, routes, Vue components, lang, nav entry. **Step 4:** backend green + 4 frontend gates green + `composer check`.
- [ ] **Step 5: Verify in preview** (start dev server, snapshot `/friends`, confirm the four sections + their empty states; share a screenshot). If the preview server is unstable in-sandbox, fall back to the four green frontend gates + a design-token hand-check, and say so.
- [ ] **Step 6: Commit** — `feat(friends): friends management page`.

---

### Task 6: Profile-page integration (`/users/{user}`)

> **Invoke the `frontend-design` skill first.**

**Files:**
- Modify: `app/Modules/Identity/Http/ProfileController.php` (`show` passes a relationship-state prop), the profile-show Vue page under `resources/js/pages/Profile/…`
- Test: `tests/Feature/Friends/ProfileFriendActionTest.php`

**Interfaces:**
- Consumes: `FriendService`, the Task 2/3 actions + routes.
- Produces: `ProfileController@show` adds a `relationship` prop for the authenticated viewer: one of `self | friends | request_sent | request_received(friendshipId) | none | blocked`. The profile page renders the matching control — Add friend / Pending / Accept-or-decline / Remove friend / Blocked — plus a block/unblock control. Guests (unauthenticated) see none of these. Never expose the target's private fields beyond the existing public profile.

- [ ] **Step 1: Failing test:**

```php
// tests/Feature/Friends/ProfileFriendActionTest.php
it('exposes the correct relationship state on a profile', function () {
    $me = User::factory()->create(); $other = User::factory()->create();
    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn ($p) => $p->where('relationship.state', 'none'));
    app(SendFriendRequest::class)->handle($me, $other);
    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn ($p) => $p->where('relationship.state', 'request_sent'));
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement the `relationship` resolver in `show`, the profile-page controls, German copy. **Step 4:** backend + frontend gates + `composer check`.
- [ ] **Step 5: Verify in preview** (states render per relationship) or documented fallback.
- [ ] **Step 6: Commit** — `feat(friends): friend/block controls on the profile page`.

---

### Task 7: Notifications (request received / accepted)

**Files:**
- Create: `app/Modules/Friends/Notifications/{FriendRequestReceived,FriendRequestAccepted}.php`
- Modify: `SendFriendRequest` (dispatch received-notification to the addressee, unless it auto-accepted), `RespondToFriendRequest` (dispatch accepted-notification to the original requester on accept)
- Modify: notification-category German copy + any category-preference registry (new category `friends`)
- Test: `tests/Feature/Friends/FriendNotificationTest.php`

**Interfaces:**
- Produces: `FriendRequestReceived` + `FriendRequestAccepted` — in-app bell (`database`) notifications following the M2 pattern (mirror the `CheckinOpened`/`DiscordDirectMessage` structure: a `public readonly string $category = 'friends'`, `toDatabase()` with title/body from `lang/de/friends.php`, optional `DiscordChannel` mirror gated by the user's `friends` category preference). Use `Notification::fake()` in tests.
- Wiring: `SendFriendRequest` notifies the addressee on a genuinely-new pending request (NOT on auto-accept); on auto-accept it instead notifies as an acceptance. `RespondToFriendRequest` on accept notifies the requester.

- [ ] **Step 1: Failing test:**

```php
// tests/Feature/Friends/FriendNotificationTest.php
it('notifies the addressee of a new request and the requester on acceptance', function () {
    Notification::fake();
    $a = User::factory()->create(); $b = User::factory()->create();
    $req = app(SendFriendRequest::class)->handle($a, $b);
    Notification::assertSentTo($b, FriendRequestReceived::class);
    app(RespondToFriendRequest::class)->handle($b, $req, accept: true);
    Notification::assertSentTo($a, FriendRequestAccepted::class);
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement notifications, wire dispatch, add the `friends` category + German copy. **Step 4:** green + `composer check`.
- [ ] **Step 5: Commit** — `feat(friends): bell notifications for received/accepted requests`.

---

### Task 8: Presence "nur Freunde" filter (closes the M10 gap)

**Files:**
- Modify: `app/Modules/Presence/Support/ParticipantPresence.php` (+ `userId`), `app/Modules/Presence/Support/PresenceProjection.php` (populate `userId`), `app/Modules/Presence/Http/PresencePageController.php` (decorate `isFriend` per viewer), `resources/js/pages/Presence/Index.vue` (4th filter toggle)
- Test: `tests/Feature/Presence/PresenceFriendsFilterTest.php`, update `tests/Unit/Presence/PresenceProjectionTest.php`

**Interfaces:**
- Consumes: `FriendService::friendUserIds`.
- Produces: `ParticipantPresence` gains `public int $userId` (added to ctor + `toArray()`). The **pure projection stays viewer-agnostic** — it does NOT compute `isFriend`. `PresencePageController` (authorized, per-viewer) decorates each participant in the **Inertia payload only** with `isFriend: bool` = `in_array(userId, friendUserIds(viewer))`; guests get `isFriend: false`. `userId`/`isFriend` are NEVER added to `PresenceUpdated::broadcastWith()` (stays empty) and the beamer scene continues to drop the roster — so no private data reaches the public channel.
- Frontend: a 4th quiet client toggle `friendsOnly` (mirrors `streamsOnly`/`playingOnly`), filtering `participants.filter(p => p.isFriend)`; German `filters.friends_only`.

- [ ] **Step 1: Failing test:**

```php
// tests/Feature/Presence/PresenceFriendsFilterTest.php
it('marks a viewer\'s friends on the presence board and never broadcasts it', function () {
    $viewer = User::factory()->create(); $friend = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $viewer->id, 'addressee_id' => $friend->id, 'status' => FriendshipStatus::Accepted]);
    $event = Event::factory()->create(['status' => EventStatus::Live]);
    // both checked in ... (reuse the presence test setup helpers)
    $this->actingAs($viewer)->get("/events/{$event->id}/presence")
        ->assertInertia(fn ($p) => $p->where(
            fn ($props) => collect($props['presence']['participants'])->firstWhere('userId', $friend->id)['isFriend'] === true
        ));
    // PresenceUpdated still broadcasts an empty payload
    expect((new PresenceUpdated($event->id))->broadcastWith())->toBe([]);
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** add `userId` to the DTO + projection, decorate `isFriend` in the controller, add the toggle + German copy. Update the `PresenceProjectionTest` array-shape assertions for the new `userId` key. **Step 4:** backend + frontend gates + `composer check`.
- [ ] **Step 5: Commit** — `feat(friends): presence "nur Freunde" filter (closes the M10 gap)`.

---

### Task 9: Docs + roadmap Erkenntnisse

**Files:**
- Modify: `docs/architecture.md` (a "Friends & social" section: module, mutual-friendship model, block semantics, LAN-native suggestions, the per-viewer presence `isFriend` decoration + why it never broadcasts)
- Modify: `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (an "Erkenntnisse — Freunde-System" subsection; note it closes the deferred M10 friends-filter; note it is the follow-up phase carved out of M9)
- Test: none (docs only).

- [ ] **Step 1:** write both. **Step 2:** `composer check` (untouched-green). **Step 3: Commit** — `docs(friends): document the friends system + Erkenntnisse`.

---

## Self-Review (done at plan-writing time)

- **Spec coverage:** mutual friendship (1–2), blocking (3), LAN-native suggestions (4), management UI (5), profile integration (6), notifications (7), the deferred M10 presence friends-filter (8), docs (9). ✓ Matches the three settled decisions (mutual+accept, LAN-native, block-now). No external provider friend-lists this phase (deferred, per decision). ✓
- **Placeholder scan:** none — every task has concrete schema/signatures/test code or an explicit "docs only". ✓
- **Type consistency:** `FriendshipStatus`, `Friendship`, `UserBlock`, `FriendService` (`areFriends`/`pendingBetween`/`blockedEitherWay`/`friendUserIds`), `FriendSuggestions::for`, the six actions, and the notification classes are used with the same signatures across tasks. `ParticipantPresence.userId` (Task 8) is the one M10 change. ✓

## Execution Handoff

Execute via **subagent-driven-development**: fresh implementer per task 1→9, `scripts/review-package` + task-reviewer between tasks (opus for the security-sensitive policy/block tasks 2/3 and the privacy-sensitive presence task 8), whole-branch review on **opus** with base = tag **`m9`** (current tip), consolidated fix wave, merge ff to `main`, tag **`friends`**, create + close a GitHub "Freunde-System" milestone, roadmap Erkenntnisse, memory update.
