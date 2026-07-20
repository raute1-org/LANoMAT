# Steam-Friend Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add a provider-based friend-suggestion source — intersect a user's public Steam friend list with LANoMAT users who have also linked Steam — as an additional signal in the existing `FriendSuggestions` read-model. (The deferred follow-up from the Friends phase.)

**Architecture:** A new best-effort `friendProviderIds()` method on the `LinkedAccountConnector` contract (Steam → `GetFriendList`; Twitch → `[]`), mirroring the existing advisory `ownsApp()` (never throws; private profile / missing key / API failure → `[]`). `FriendSuggestions` gains a `shared_steam_friend` source that resolves the viewer's Steam friends to LANoMAT user ids and merges them into the ranked index, applying the same exclusions. The external Steam call is cached per (user, steamid) to avoid hammering the API on every suggestions load.

**Tech Stack:** PHP 8.4 · Laravel 13 · Laravel HTTP client (`Http::fake` in tests — NEVER a real Steam call) · Pest 4 · Redis cache.

## Global Constraints

- Code / comments / commits / docs in **English**; UI copy German via `lang/de/friends.php`.
- **External systems only through the `LinkedAccountConnector` contract + `FakeLinkedAccountConnector`** — feature tests use `fakeLinkedAccounts()`; the real `SteamConnector` is unit-tested only with `Http::fake` (never a real Steam Web API call).
- The Steam friend lookup is **best-effort and MUST NOT throw**: private profile (HTTP 401), missing API key, network error, or malformed response all resolve to `[]` — exactly like `ownsApp()` resolves to `null`.
- **Conventional Commits** (`feat(friends): …` / `feat(identity): …`); TDD. `composer check` (pint --test, phpstan **level 8**, pest **SEQUENTIAL** — never `--parallel`; on `SQLSTATE[40P01]`/"table does not exist" check `ps aux | grep vendor/bin/pest` for stray processes) green after every task. Frontend gates green for any Vue change.
- New/changed test files use `uses(RefreshDatabase::class)`.
- The same exclusion rules as the existing sources apply: never suggest self, current friends, pending-either-direction, or blocked-either-way.
- No new external provider tokens — Steam linking is OpenID (no token); `GetFriendList` uses the app's Web API key already at `config('services.steam.client_secret')` (env `STEAM_API_KEY`), same key as `ownsApp`.

---

### Task 1: `friendProviderIds()` on the connector (Steam real + Twitch + Fake)

**Files:**
- Modify: `app/Modules/Identity/Contracts/LinkedAccountConnector.php` (add the method)
- Modify: `app/Modules/Identity/Connectors/SteamConnector.php` (real GetFriendList), `app/Modules/Identity/Connectors/TwitchConnector.php` (return `[]`), `app/Modules/Identity/Connectors/FakeLinkedAccountConnector.php` (queued answer + `willReportFriends`)
- Modify: `app/Modules/Identity/Testing/FakeLinkedAccounts.php` (dispatcher `willReportFriends`)
- Test: `tests/Unit/Identity/SteamConnectorTest.php` (extend with `friendProviderIds` cases)

**Interfaces:**
- Produces on `LinkedAccountConnector`: `friendProviderIds(LinkedAccount $account): array` — `@return array<int, string>` provider-native friend ids (Steam → SteamID64 strings). Best-effort; `[]` when the provider has no friend concept (Twitch), the profile is private, the key is missing, or the API failed. **MUST NOT throw.**
- Produces: `SteamConnector::friendProviderIds` — `Http::get('https://api.steampowered.com/ISteamUser/GetFriendList/v1/', ['key'=>$apiKey, 'steamid'=>$account->provider_user_id, 'relationship'=>'friend', 'format'=>'json'])`; parse `friendslist.friends` → each entry's `steamid` (string); guard non-2xx / non-array / malformed → `[]`; wrap everything in try/catch(Throwable) → `[]`. Missing/empty key → `[]` before any call (mirror `ownsApp`).
- Produces: `TwitchConnector::friendProviderIds` → `[]`.
- Produces: `FakeLinkedAccountConnector::willReportFriends(array $ids): void` + `friendProviderIds()` returns the queued array (default `[]`); dispatcher `FakeLinkedAccounts::willReportFriends(LinkedAccountProvider $provider, array $ids): void`.

- [ ] **Step 1: Failing unit test** (extend `SteamConnectorTest.php`, `Http::fake`, never real):

```php
it('returns friend SteamIDs from GetFriendList', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake([
        'api.steampowered.com/*' => Http::response([
            'friendslist' => ['friends' => [
                ['steamid' => '111', 'relationship' => 'friend', 'friend_since' => 1],
                ['steamid' => '222', 'relationship' => 'friend', 'friend_since' => 2],
            ]],
        ]),
    ]);
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '999']);

    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe(['111', '222']);
});

it('returns [] on a private profile (401), missing key, or malformed response', function () {
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '999']);

    config(['services.steam.client_secret' => '']);           // missing key
    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);

    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake(['api.steampowered.com/*' => Http::response([], 401)]); // private
    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);

    Http::fake(['api.steampowered.com/*' => Http::response(['friendslist' => []])]); // malformed
    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);
});
```

- [ ] **Step 2: Run → FAIL** (method absent).
- [ ] **Step 3:** add the contract method; implement in Steam (mirror `ownsApp`'s structure exactly — key guard, try/catch, `$response->successful()`, `is_array` guards), Twitch (`[]`), Fake (`willReportFriends` + queued return); add the dispatcher method.
- [ ] **Step 4: Run → green.** `composer check`.
- [ ] **Step 5: Commit** — `feat(identity): friendProviderIds() connector method (Steam GetFriendList, best-effort)`.

---

### Task 2: Steam-friend source in `FriendSuggestions` (+ cached lookup + label)

**Files:**
- Modify: `app/Modules/Friends/Support/FriendSuggestions.php` (new source + accumulate `shared_steam_friend`)
- Modify: `lang/de/friends.php` (add `reason_steam_friend` label), and the suggestion-row display if reasons are mapped there (check `resources/js/components/friends/SuggestionRow.vue` — it already renders reason labels; ensure the new key is mapped)
- Test: `tests/Unit/Friends/SteamFriendSuggestionsTest.php`

**Interfaces:**
- Consumes: `LinkedAccountConnectors::for(LinkedAccountProvider::Steam)->friendProviderIds(...)` (Task 1); `User::linkedAccount(LinkedAccountProvider::Steam)`; `LinkedAccount` (`provider`, `provider_user_id`, `user_id`).
- Produces in `FriendSuggestions`: a `sharedSteamFriendUserIds(User $user, array $excludedUserIds): Collection<int,int>` source, accumulated with reason key `shared_steam_friend`, wired into `for()` alongside the existing three sources. Logic:
  1. `$steam = $user->linkedAccount(LinkedAccountProvider::Steam)`; if null → empty collection (no suggestions from this source).
  2. `$friendSteamIds = Cache::remember("steam-friends:{$user->id}:{$steam->provider_user_id}", now()->addMinutes(15), fn () => app(LinkedAccountConnectors::class)->for(LinkedAccountProvider::Steam)->friendProviderIds($steam))` — cache ONLY the external SteamID list (the expensive part).
  3. If empty → empty collection. Else query `LinkedAccount::query()->where('provider', Steam)->whereIn('provider_user_id', $friendSteamIds)->whereNotIn('user_id', $excludedUserIds)->pluck('user_id')` → each maps once (a shared count of 1 + the reason).
- The exclusions are applied live (never cached) — friendships/blocks change between the 15-min windows.
- Docblock: extend the `reasons` vocabulary list with `- shared_steam_friend: a mutual Steam friend who also uses LANoMAT.`

- [ ] **Step 1: Failing test** (uses the Fake — never a real Steam call):

```php
// tests/Unit/Friends/SteamFriendSuggestionsTest.php
it('suggests a Steam friend who also linked Steam, excluding non-candidates', function () {
    $me = User::factory()->create();
    LinkedAccount::factory()->for($me)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'ME']);

    $steamFriend = User::factory()->create();
    LinkedAccount::factory()->for($steamFriend)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F1']);
    $alreadyFriend = User::factory()->create();
    LinkedAccount::factory()->for($alreadyFriend)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F2']);
    Friendship::factory()->create(['requester_id' => $me->id, 'addressee_id' => $alreadyFriend->id, 'status' => FriendshipStatus::Accepted]);

    fakeLinkedAccounts()->willReportFriends(LinkedAccountProvider::Steam, ['F1', 'F2', 'UNKNOWN']);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->toContain($steamFriend->id)   // Steam friend + LANoMAT-linked → suggested
        ->not->toContain($alreadyFriend->id)    // already a friend → excluded
        ->not->toContain($me->id);
});

it('adds shared_steam_friend to the reasons of a suggested Steam friend', function () {
    $me = User::factory()->create();
    LinkedAccount::factory()->for($me)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'ME']);
    $f = User::factory()->create();
    LinkedAccount::factory()->for($f)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F1']);
    fakeLinkedAccounts()->willReportFriends(LinkedAccountProvider::Steam, ['F1']);

    $row = app(FriendSuggestions::class)->for($me)->firstWhere('user.id', $f->id);
    expect($row['reasons'])->toContain('shared_steam_friend');
});
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement the source + cache + wire into `for()`; add the German label; ensure `SuggestionRow.vue` maps `shared_steam_friend`. **Step 4:** `composer check` + frontend gates (if the Vue changed).
- [ ] **Step 5: Commit** — `feat(friends): Steam-friend suggestion source (cached, LANoMAT-linked intersection)`.

---

### Task 3: Eliminate the `EntryRoster` N+1 in `FriendSuggestions` + `PresenceProjection`

**Context:** `EntryRoster::usersFor(TournamentEntry)` issues its own `User::whereIn(...)->get()` per call. Two hot-ish read-models call it per entry/match:
- `FriendSuggestions::sharedTournamentUserIds` (this phase's Task 2 leaves it as-is) calls `usersFor` per entry across every shared tournament — but it only needs **user ids**, which already live on the entry (`roster_snapshot[].user_id` / `entry.user_id`), so the `User` query is entirely unnecessary.
- `PresenceProjection::forEvent` calls `EntryRoster::usersForMatch($match)` per live match — it needs **User models** (name/avatar), so batch the resolution across matches.

**Files:**
- Modify: `app/Modules/Tournaments/Support/EntryRoster.php` (add `userIdsFor` + `usersForEntries`; refactor `usersFor`/`usersForMatch`/`usersForTournament` to use them)
- Modify: `app/Modules/Friends/Support/FriendSuggestions.php` (`sharedTournamentUserIds` → `userIdsFor`, no `User` query)
- Modify: `app/Modules/Presence/Support/PresenceProjection.php` (batch live-match roster resolution)
- Test: `tests/Unit/Tournaments/EntryRosterTest.php` (new/extended — batch correctness + query-count), and confirm existing `FriendSuggestionsTest`/`PresenceProjectionTest` stay green

**Interfaces:**
- Produces: `EntryRoster::userIdsFor(TournamentEntry $entry): array<int>` — pure id extraction (the current inline logic in `usersFor`: `roster_snapshot` column `user_id`s, else `array_filter([$entry->user_id])`), NO query. `usersFor` becomes `User::whereIn('id', self::userIdsFor($entry))->get()` (unchanged behavior).
- Produces: `EntryRoster::usersForEntries(Collection<int,TournamentEntry> $entries): Collection<int, User>` — the union of all entries' users in ONE query (`User::whereIn('id', $allUserIds)->get()`), deduped, keyed by user id. Refactor `usersForMatch`/`usersForTournament` to gather their entries then delegate to `usersForEntries` (each becomes exactly one `User` query instead of one-per-entry).
- Changes: `FriendSuggestions::sharedTournamentUserIds` uses `EntryRoster::userIdsFor($entry)` (array of ids) instead of `usersFor(...)->pluck('id')` — same result, zero `User` queries. `PresenceProjection::forEvent` collects all live matches' entries, calls `usersForEntries` ONCE, and assembles each match's `users` from the in-memory result (deduped per match) instead of `usersForMatch` per match.

- [ ] **Step 1: Failing test** — batch correctness + no per-entry query fan-out:

```php
// tests/Unit/Tournaments/EntryRosterTest.php
it('resolves users for many entries in a single query', function () {
    $entries = TournamentEntry::factory()->count(4)->create();   // solo entries
    DB::enableQueryLog();
    $users = EntryRoster::usersForEntries($entries);
    $userQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"users"'));
    expect($userQueries)->toHaveCount(1)                          // ONE query, not 4
        ->and($users)->toHaveCount(4);
});

it('userIdsFor extracts ids without a query (solo + team roster)', function () {
    $solo = TournamentEntry::factory()->create(['user_id' => 7, 'roster_snapshot' => null]);
    DB::enableQueryLog();
    expect(EntryRoster::userIdsFor($solo))->toBe([7]);
    expect(DB::getQueryLog())->toBeEmpty();
});
```
(Adapt factory usage to the real `TournamentEntry` factory — recall solo/team factory states overwrite `.for()`; set `user_id`/`roster_snapshot` explicitly.)

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement `userIdsFor` + `usersForEntries`, refactor the three existing methods, then update `FriendSuggestions` + `PresenceProjection`. Keep every existing behavior identical (dedup semantics, ordering where it matters). **Step 4:** `composer check` + (Presence touches no Vue, so no frontend gates unless a payload shape changed — it should not).
- [ ] **Step 5: Commit** — `perf(tournaments): batch EntryRoster user resolution; drop FriendSuggestions/Presence N+1`.

---

### Task 4: Docs + roadmap + memory note

**Files:**
- Modify: `docs/architecture.md` (extend the "Friends & social" suggestions paragraph: Steam-friend source via `friendProviderIds`, best-effort, cached, intersected with LANoMAT-linked Steam accounts; and note the `EntryRoster` batch that removed the FriendSuggestions/Presence N+1)
- Modify: `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (a short note under the Friends Erkenntnisse that the deferred provider-based suggestion source is now delivered AND the deferred `EntryRoster` N+1 is now fixed for both M10 and Friends)
- Test: none (docs only).

- [ ] **Step 1:** write both. **Step 2:** `composer check` (untouched-green). **Step 3: Commit** — `docs(friends): document the Steam-friend source + EntryRoster batch`.

---

## Self-Review (done at plan-writing time)

- **Spec coverage:** connector method (1), suggestions source + cache + label (2), docs (3). ✓ Matches the settled scope (merged source with a `shared_steam_friend` reason; server-side best-effort; cached).
- **Placeholder scan:** none. ✓
- **Type consistency:** `friendProviderIds(LinkedAccount): array<int,string>` used identically in Task 1 (define) and Task 2 (consume); `willReportFriends` on both the Fake and the dispatcher; reason key `shared_steam_friend` consistent across the read-model, the lang label, and the tests. ✓

## Execution Handoff

Execute via **subagent-driven-development**: fresh implementer per task, `scripts/review-package` + task-reviewer between tasks (opus for Task 1 — the external best-effort Steam call, which must never throw and must handle the private-profile 401), merge ff to `main` (base = tag `friends`, commit `231a1f2`). This is a small additive follow-up to the Friends phase — NO new milestone; note it in the roadmap. Optionally tag, or leave as an increment on `main` (decide at merge).
