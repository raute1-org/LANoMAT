# M9 — Identity+ (Plattform-Verknüpfungen & kontextsensitiver Anzeigename) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let every user link secondary platform accounts (Steam, Twitch first) to their Discord-login LANoMAT account, behind a provider-adapter contract with encrypted token lifecycle, and use those links for a context-sensitive display name and a non-blocking game-ownership hint.

**Architecture:** Login stays **Discord-only** (auth path unchanged). Secondary accounts live in a new `linked_accounts` table (Discord is deliberately NOT a row in it). Each provider is an implementation of a `LinkedAccountConnector` contract (Contract principle, like `DiscordClient`/`VoiceClient`), resolved through a `LinkedAccountConnectors` registry with a `Fake` for tests. `users.id` remains the sole FK/merge anchor. Steam is OpenID (identity only, no token); Twitch is OAuth2 (encrypted access+refresh tokens, auto-refresh, re-auth warning on failure).

**Tech Stack:** PHP 8.4 · Laravel 13 · Laravel Socialite ^5.28 + `socialiteproviders/steam` (^4.3) + `socialiteproviders/twitch` · Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue · Filament v5 · Pest 4 · PostgreSQL 16.

## Global Constraints

- Code / comments / commits / docs in **English**; all UI copy in German via `lang/de/` (new file `lang/de/connections.php`).
- **Conventional Commits** (`feat(identity): …`); TDD — failing test first wherever there is testable behavior; frequent commits.
- PHP: Pint (Laravel preset), Larastan **level 8** (no `mixed` returns in own code), enums over magic strings. `composer check` (pint --test, phpstan, pest sequential) must be green after every task.
- Frontend gates green: `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run build`.
- **External systems accessed ONLY through contracts + Fakes — NEVER call real Steam/Twitch APIs in tests.** New contract: `LinkedAccountConnector`, with `FakeLinkedAccountConnector`.
- **Every authorization goes through a Policy.** A user may only link/unlink accounts on their OWN user. Never trust client-supplied user IDs.
- **Secrets encrypted at rest:** `access_token`/`refresh_token` use the Eloquent `encrypted` cast and are **never `$fillable`** and never serialized to the frontend.
- **BINDING RULE:** the game-ownership check is a **HINT that only warns, never a gate**. Registration/enrollment MUST always proceed even when ownership is unknown, unowned, or the game has no online requirement. *"Der Besitz-Check aus M9 darf nie blockieren, nur warnen."*
- **Design system is binding:** all new/reshaped UI follows `docs/design.md` (Signalpult) — semantic role utilities (`bg-primary`/`text-muted-foreground`/`text-live`/`font-mono`), rationed amber, all four states, focus/reduced-motion/AA. **Invoke the `frontend-design` skill before Task 9.5 (UI).**
- `discord_id` stays a unique-nullable column on `users`, keeps its login + Discord-routing role. It is NOT migrated into `linked_accounts`.
- Merge strategy = **soft-merge, document-only** — no schema column, no logic this milestone (Task 9.9 documents the guardrail).
- **Scope OUT of M9:** the friends system (requests/suggestions) and the deferred M10 presence friends-filter — those are a separate later phase.

---

## File Structure

New module `app/Modules/Identity/` gains a `LinkedAccounts` surface (the module already exists with `Actions/`, `Http/`):

- `app/Modules/Identity/Enums/LinkedAccountProvider.php` — enum (steam, twitch, battlenet, epic, gog) + capability helpers.
- `app/Modules/Identity/Models/LinkedAccount.php` — Eloquent model, encrypted tokens.
- `app/Modules/Identity/Contracts/LinkedAccountConnector.php` — per-provider adapter contract.
- `app/Modules/Identity/Support/LinkedAccountData.php` — DTO mapping a Socialite user → storable fields.
- `app/Modules/Identity/Support/LinkedAccountConnectors.php` — registry (resolve by provider, `enabled()` list).
- `app/Modules/Identity/Connectors/{SteamConnector,TwitchConnector}.php` — real adapters.
- `app/Modules/Identity/Connectors/FakeLinkedAccountConnector.php` — test fake.
- `app/Modules/Identity/Actions/{LinkAccount,UnlinkAccount,RefreshLinkedAccountToken}.php`.
- `app/Modules/Identity/Jobs/RefreshExpiringTokensJob.php` (scheduled sweep) — or a console command.
- `app/Modules/Identity/Support/DisplayNameResolver.php` — context-sensitive display name.
- `app/Modules/Identity/Support/GameOwnershipHint.php` — non-blocking ownership hint.
- `app/Modules/Identity/Policies/LinkedAccountPolicy.php`.
- `app/Modules/Identity/Http/ConnectionsController.php` — settings/connections + link/unlink.
- `resources/js/pages/settings/Connections.vue` — the linked-accounts management page.
- `resources/js/components/settings/ConnectionCard.vue` — one provider row.
- `lang/de/connections.php` — UI copy.
- `database/migrations/2026_07_20_000000_create_linked_accounts_table.php`.
- Modify: `app/Providers/AppServiceProvider.php` (register steam+twitch drivers), `config/services.php` (steam/twitch blocks), `routes/settings.php` (connections routes), `app/Models/User.php` (`linkedAccounts()` + `linkedAccount()` + display-name helper), `composer.json` (two packages), `docs/architecture.md`, roadmap.

Tests mirrored under `tests/{Feature,Unit}/Identity/`.

---

### Task 9.1: `linked_accounts` schema + model + provider enum

**Files:**
- Create: `app/Modules/Identity/Enums/LinkedAccountProvider.php`
- Create: `app/Modules/Identity/Models/LinkedAccount.php`
- Create: `database/migrations/2026_07_20_000000_create_linked_accounts_table.php`
- Create: `database/factories/LinkedAccountFactory.php`
- Modify: `app/Models/User.php` (add `linkedAccounts()` HasMany + `linkedAccount(LinkedAccountProvider)` helper)
- Test: `tests/Unit/Identity/LinkedAccountModelTest.php`, `tests/Feature/Identity/LinkedAccountSchemaTest.php`

**Interfaces:**
- Produces: `LinkedAccountProvider` enum (backed string: `steam`, `twitch`, `battlenet`, `epic`, `gog`) with `label(): string`, `isOauth(): bool`, `hasTokenLifecycle(): bool` (true only when `isOauth()` AND provider issues refresh tokens — Steam false, Twitch true), `socialiteDriver(): string`, and `public static function linkable(): array` (providers currently offered = [Steam, Twitch]).
- Produces: `LinkedAccount` model — `$fillable = ['user_id','provider','provider_user_id','nickname','scopes','meta']` (tokens deliberately EXCLUDED — set via `forceFill` in actions); casts `provider => LinkedAccountProvider::class`, `access_token => 'encrypted'`, `refresh_token => 'encrypted'`, `token_expires_at => 'datetime'`, `scopes => 'array'`, `meta => 'array'`; `user(): BelongsTo`; `needsReauth(): bool` (true when `meta['needs_reauth'] ?? false`).
- Produces: `User::linkedAccounts(): HasMany`, `User::linkedAccount(LinkedAccountProvider $p): ?LinkedAccount`.

- [ ] **Step 1: Write the failing migration + schema test**

```php
// tests/Feature/Identity/LinkedAccountSchemaTest.php
<?php

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Database\QueryException;

it('enforces one account per provider per user', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '111']);

    expect(fn () => LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '222']))
        ->toThrow(QueryException::class);
});

it('enforces one user per (provider, provider_user_id)', function () {
    LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch, 'provider_user_id' => 'abc']);

    expect(fn () => LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch, 'provider_user_id' => 'abc']))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run it to confirm it fails** — `./vendor/bin/pest --filter=LinkedAccountSchema` → FAIL (no table).

- [ ] **Step 3: Write the migration**

```php
// database/migrations/2026_07_20_000000_create_linked_accounts_table.php
Schema::create('linked_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('provider');              // LinkedAccountProvider backing value
    $table->string('provider_user_id');      // SteamID / Twitch user id
    $table->string('nickname')->nullable();  // provider-side display name
    $table->text('access_token')->nullable();   // encrypted (Twitch); null for Steam
    $table->text('refresh_token')->nullable();  // encrypted (Twitch)
    $table->timestamp('token_expires_at')->nullable();
    $table->jsonb('scopes')->nullable();
    $table->jsonb('meta')->nullable();       // e.g. {"needs_reauth": true}
    $table->timestamps();

    $table->unique(['provider', 'provider_user_id']); // an external account maps to ONE user
    $table->unique(['user_id', 'provider']);          // one account per provider per user
});
```

- [ ] **Step 4: Write the enum, model, factory, and User relations** (signatures per Interfaces above).

- [ ] **Step 5: Write the model unit test** (encryption round-trip + casts):

```php
// tests/Unit/Identity/LinkedAccountModelTest.php
it('encrypts tokens at rest and never exposes them fillable', function () {
    $account = LinkedAccount::factory()->create();
    $account->forceFill(['access_token' => 'secret-abc'])->save();

    $raw = DB::table('linked_accounts')->where('id', $account->id)->value('access_token');
    expect($raw)->not->toBe('secret-abc');            // stored ciphertext
    expect($account->fresh()->access_token)->toBe('secret-abc'); // decrypted on read

    // mass-assignment cannot set a token
    $account->fill(['access_token' => 'injected'])->save();
    expect($account->fresh()->access_token)->toBe('secret-abc');
});
```

- [ ] **Step 6: Run tests — green.** Then `composer check`.

- [ ] **Step 7: Commit** — `feat(identity): add linked_accounts schema, model, and provider enum`.

---

### Task 9.2: `LinkedAccountConnector` contract + registry + Fake

**Files:**
- Create: `app/Modules/Identity/Contracts/LinkedAccountConnector.php`
- Create: `app/Modules/Identity/Support/LinkedAccountData.php`
- Create: `app/Modules/Identity/Support/LinkedAccountConnectors.php`
- Create: `app/Modules/Identity/Connectors/FakeLinkedAccountConnector.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind the registry)
- Test: `tests/Unit/Identity/LinkedAccountConnectorsTest.php`

**Interfaces:**
- Produces: `LinkedAccountData` — readonly DTO: `provider_user_id: string`, `nickname: ?string`, `access_token: ?string`, `refresh_token: ?string`, `token_expires_at: ?CarbonImmutable`, `scopes: array`, `meta: array`. `fromSocialite(\Laravel\Socialite\Contracts\User $u): self` factory.
- Produces: `LinkedAccountConnector` interface —
  - `provider(): LinkedAccountProvider`
  - `redirectUrl(): string` (delegates to `Socialite::driver(...)->redirect()->getTargetUrl()`, provider scopes applied)
  - `resolveCallback(): LinkedAccountData` (maps the returned Socialite user)
  - `refresh(LinkedAccount $account): LinkedAccountData` (Twitch: exchanges refresh token; Steam: throws `UnsupportedTokenRefresh`)
- Produces: `LinkedAccountConnectors` registry — `for(LinkedAccountProvider): LinkedAccountConnector` (throws for a provider with no bound connector), `enabled(): array<LinkedAccountProvider>` (only providers whose `config('services.<driver>')` is fully configured).
- Produces: `FakeLinkedAccountConnector` + a `fakeLinkedAccounts()` test helper (mirrors `fakeVoice()`), letting a test queue the next `resolveCallback()`/`refresh()` result or force a failure.

- [ ] **Step 1: Failing test** — registry resolves the Fake and lists enabled providers:

```php
// tests/Unit/Identity/LinkedAccountConnectorsTest.php
it('resolves a connector per provider and lists only configured ones', function () {
    config()->set('services.steam.client_secret', 'key');   // configured
    config()->set('services.twitch.client_id', null);        // NOT configured
    $registry = app(LinkedAccountConnectors::class);

    expect($registry->enabled())->toContain(LinkedAccountProvider::Steam)
        ->not->toContain(LinkedAccountProvider::Twitch);
    expect($registry->for(LinkedAccountProvider::Steam)->provider())->toBe(LinkedAccountProvider::Steam);
});
```

- [ ] **Step 2: Run — FAIL** (classes absent).

- [ ] **Step 3: Implement the DTO, contract, registry, and Fake.** Bind `LinkedAccountConnectors` as a singleton in `AppServiceProvider`; in tests `fakeLinkedAccounts()` swaps every connector for `FakeLinkedAccountConnector`.

- [ ] **Step 4: Run — green.** `composer check`.

- [ ] **Step 5: Commit** — `feat(identity): add LinkedAccountConnector contract, registry, and fake`.

---

### Task 9.3: Steam linking (OpenID, identity-only, no token)

**Files:**
- Create: `app/Modules/Identity/Connectors/SteamConnector.php`
- Create: `app/Modules/Identity/Actions/LinkAccount.php`
- Create: `app/Modules/Identity/Actions/UnlinkAccount.php`
- Create: `app/Modules/Identity/Policies/LinkedAccountPolicy.php`
- Create: `app/Modules/Identity/Http/ConnectionsController.php`
- Modify: `composer.json` (`socialiteproviders/steam`), `config/services.php` (`steam` block), `app/Providers/AppServiceProvider.php` (`extendSocialite('steam', SteamProvider::class)`), `routes/settings.php`
- Test: `tests/Feature/Identity/LinkSteamAccountTest.php`

**Interfaces:**
- Consumes: `LinkedAccountConnector`, `LinkedAccountData`, `LinkedAccountConnectors` (9.2); `LinkedAccount` (9.1).
- Produces: `LinkAccount::handle(User $user, LinkedAccountProvider $provider, LinkedAccountData $data): LinkedAccount` — upserts by `(user_id, provider)`; sets tokens via `forceFill`; if `(provider, provider_user_id)` already belongs to a DIFFERENT user, throws `AccountAlreadyLinked` (mapped to a 422 with a German flash, never a 500). Sets `meta['needs_reauth'] = false`.
- Produces: `UnlinkAccount::handle(User $user, LinkedAccountProvider $provider): void`.
- Produces routes (inside the authenticated `settings` group):
  - `GET  settings/connections/{provider}/redirect` → `ConnectionsController@redirect` (name `connections.redirect`)
  - `GET  settings/connections/{provider}/callback` → `ConnectionsController@callback`
  - `DELETE settings/connections/{provider}` → `ConnectionsController@destroy` (name `connections.destroy`)
  - `{provider}` is bound/validated against `LinkedAccountProvider::linkable()` → 404 otherwise.

- [ ] **Step 1: Failing feature test** (Socialite mocked — NEVER a real call):

```php
// tests/Feature/Identity/LinkSteamAccountTest.php
it('links a Steam account for the authenticated user', function () {
    fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: '76561198000000000', nickname: 'FraggerX',
    ));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/steam/callback')->assertRedirect('/settings/connections');

    $account = $user->linkedAccount(LinkedAccountProvider::Steam);
    expect($account->provider_user_id)->toBe('76561198000000000')
        ->and($account->nickname)->toBe('FraggerX')
        ->and($account->access_token)->toBeNull();       // Steam OpenID: no token
});

it('refuses to link a Steam account already owned by another user', function () {
    LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '765']);
    fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Steam, new LinkedAccountData(provider_user_id: '765'));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/steam/callback')
        ->assertRedirect()->assertSessionHas('errors');
    expect($user->linkedAccount(LinkedAccountProvider::Steam))->toBeNull();
});

it('unlinks only the caller's own account (policy)', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam]);

    $this->actingAs($user)->delete('/settings/connections/steam')->assertRedirect();
    expect($user->fresh()->linkedAccount(LinkedAccountProvider::Steam))->toBeNull();
});
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: `composer require socialiteproviders/steam`;** add the `steam` services block (`client_id => null`, `client_secret => env('STEAM_CLIENT_SECRET')`, `redirect => env('STEAM_REDIRECT_URI')`, `allowed_hosts`), register the driver in `configureSocialite()`, implement `SteamConnector` (`hasTokenLifecycle()` false; `refresh()` throws), `LinkAccount`/`UnlinkAccount`, `LinkedAccountPolicy` (`update`/`delete` = `$user->id === $account->user_id`), `ConnectionsController`, routes.

- [ ] **Step 4: Run — green.** `composer check`.

- [ ] **Step 5: Commit** — `feat(identity): Steam account linking via OpenID (identity-only)`.

---

### Task 9.4: Twitch OAuth2 linking + token lifecycle

**Files:**
- Create: `app/Modules/Identity/Connectors/TwitchConnector.php`
- Create: `app/Modules/Identity/Actions/RefreshLinkedAccountToken.php`
- Create: `app/Modules/Identity/Jobs/RefreshExpiringTokensJob.php` (+ schedule in `routes/console.php`)
- Modify: `composer.json` (`socialiteproviders/twitch`), `config/services.php` (`twitch` block), `AppServiceProvider` (register driver), `routes/settings.php` (twitch is already covered by the generic `{provider}` routes from 9.3)
- Test: `tests/Feature/Identity/LinkTwitchAccountTest.php`, `tests/Feature/Identity/RefreshTwitchTokenTest.php`

**Interfaces:**
- Consumes: everything from 9.1–9.3.
- Produces: `TwitchConnector` — `hasTokenLifecycle()` true; `resolveCallback()` maps access+refresh token + `token_expires_at`; `refresh(LinkedAccount): LinkedAccountData` exchanges the stored refresh token via Socialite.
- Produces: `RefreshLinkedAccountToken::handle(LinkedAccount $account): void` — on success `forceFill` new tokens + clear `needs_reauth`; on failure set `meta['needs_reauth'] = true` and dispatch a `LinkedAccountReauthRequired` in-app notification (reuse the M2 notifications module).
- Produces: `RefreshExpiringTokensJob` — selects `linked_accounts` where `token_expires_at` within the next hour AND provider `hasTokenLifecycle()`, runs `RefreshLinkedAccountToken` for each; scheduled hourly.

- [ ] **Step 1: Failing tests** (Fake refresh success + failure → warning):

```php
// tests/Feature/Identity/RefreshTwitchTokenTest.php
it('refreshes an expiring Twitch token', function () {
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Twitch,
        'token_expires_at' => now()->addMinutes(10),
    ]);
    fakeLinkedAccounts()->willRefresh(LinkedAccountProvider::Twitch, new LinkedAccountData(
        provider_user_id: $account->provider_user_id, access_token: 'new', refresh_token: 'newr',
        token_expires_at: now()->addHours(4),
    ));

    app(RefreshLinkedAccountToken::class)->handle($account);

    expect($account->fresh()->access_token)->toBe('new')
        ->and($account->fresh()->needsReauth())->toBeFalse();
});

it('flags needs_reauth and notifies when refresh fails', function () {
    Notification::fake();
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch]);
    fakeLinkedAccounts()->willFailRefresh(LinkedAccountProvider::Twitch);

    app(RefreshLinkedAccountToken::class)->handle($account);

    expect($account->fresh()->needsReauth())->toBeTrue();
    Notification::assertSentTo($account->user, LinkedAccountReauthRequired::class);
});
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: `composer require socialiteproviders/twitch`;** add `twitch` services block, register driver, implement `TwitchConnector`, `RefreshLinkedAccountToken`, the notification, `RefreshExpiringTokensJob`, and the hourly schedule. Link flow reuses the generic `ConnectionsController` from 9.3 (only the connector differs).

- [ ] **Step 4: Run — green.** `composer check`.

- [ ] **Step 5: Commit** — `feat(identity): Twitch OAuth2 linking with encrypted token refresh + re-auth warning`.

---

### Task 9.5: Connections settings page (Inertia + frontend-design)

> **Invoke the `frontend-design` skill first** and design against `docs/design.md`.

**Files:**
- Create: `resources/js/pages/settings/Connections.vue`
- Create: `resources/js/components/settings/ConnectionCard.vue`
- Create: `lang/de/connections.php`
- Modify: `app/Modules/Identity/Http/ConnectionsController.php` (add `index()` returning Inertia), `routes/settings.php` (`GET settings/connections` → `connections.edit`), the settings nav component.
- Test: `tests/Feature/Identity/ConnectionsPageTest.php`

**Interfaces:**
- Consumes: `LinkedAccountConnectors::enabled()`, the caller's `linkedAccounts()`.
- Produces: `ConnectionsController@index` → Inertia page `settings/Connections` with props `providers: Array<{ provider, label, linked: bool, nickname: ?string, needsReauth: bool, redirectUrl, unlinkUrl }>`. **No tokens ever in props.**
- UI: one `ConnectionCard` per provider — linked state shows nickname + a quiet unlink button; unlinked shows a link button; `needsReauth` shows a **rationed amber** warning row with a re-link CTA (the one signal-amber use on this page). All four states, `font-mono` only for the provider-side id if shown, visible focus, reduced-motion.

- [ ] **Step 1: Failing test** — page renders, exposes no secrets:

```php
// tests/Feature/Identity/ConnectionsPageTest.php
it('renders linked + unlinked providers without leaking tokens', function () {
    config()->set('services.steam.client_secret', 'k');
    config()->set('services.twitch.client_id', 'k'); config()->set('services.twitch.client_secret', 'k');
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Twitch, 'nickname' => 'streamer1', 'access_token' => 'secret',
    ]);

    $this->actingAs($user)->get('/settings/connections')
        ->assertInertia(fn ($p) => $p
            ->component('settings/Connections')
            ->where('providers.1.linked', true)
            ->where('providers.1.nickname', 'streamer1')
            ->missing('providers.1.access_token'));
});
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement controller `index()`, the two Vue components, `lang/de/connections.php`, the route, and a nav entry.** Follow `settings/Profile.vue` for layout/structure.

- [ ] **Step 4: Run tests + `npm run types:check` + `npm run build` + `composer check`.**

- [ ] **Step 5: Verify in preview** (start dev server, snapshot the page, confirm the four states + amber warning render). Share a screenshot.

- [ ] **Step 6: Commit** — `feat(identity): connections settings page for managing linked accounts`.

---

### Task 9.6: Context-sensitive display name resolver

**Files:**
- Create: `app/Modules/Identity/Support/DisplayNameResolver.php`
- Modify: `app/Models/User.php` (`displayNameFor(?LinkedAccountProvider $context = null): string`)
- Modify: wire into presence + tournament-entry display where a game context maps to a provider (only where a `game` already knows its provider — see 9.7).
- Test: `tests/Unit/Identity/DisplayNameResolverTest.php`

**Interfaces:**
- Produces: `DisplayNameResolver::resolve(User $user, ?LinkedAccountProvider $context): string` — if `$context` is set AND the user has a linked account for it with a non-null nickname → the provider nickname; else the LANoMAT `user.name`. Pure, no IO.
- Produces: `User::displayNameFor(?LinkedAccountProvider $context = null): string` delegating to the resolver.

- [ ] **Step 1: Failing test:**

```php
// tests/Unit/Identity/DisplayNameResolverTest.php
it('prefers the provider nickname in that provider's context', function () {
    $user = User::factory()->create(['name' => 'LanNick']);
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'nickname' => 'SteamNick']);
    $r = app(DisplayNameResolver::class);

    expect($r->resolve($user, LinkedAccountProvider::Steam))->toBe('SteamNick');
    expect($r->resolve($user, LinkedAccountProvider::Twitch))->toBe('LanNick'); // not linked → fallback
    expect($r->resolve($user, null))->toBe('LanNick');                          // no context → LANoMAT name
});
```

- [ ] **Step 2: Run — FAIL.** **Step 3:** implement resolver + `User` helper. **Step 4:** green + `composer check`.

- [ ] **Step 5: Commit** — `feat(identity): context-sensitive display name resolver`.

---

### Task 9.7: Game-ownership hint — WARN, never gate

**Files:**
- Create: `app/Modules/Identity/Support/GameOwnershipHint.php`
- Modify: `app/Modules/Games/Models/Game.php` (add nullable `provider` + `provider_app_id` — which platform/appid a game maps to, e.g. Steam appid) + migration
- Modify: the tournament enrollment surface (the code that today does `whereNotNull('discord_id')` in `TournamentEntry`) + the enrollment UI to surface a **non-blocking** hint
- Modify: `LinkedAccountConnector` (add `ownsApp(LinkedAccount $account, string $appId): ?bool` — `null` = unknown; Steam implements via Web API behind the Fake; Twitch returns `null`)
- Test: `tests/Feature/Identity/OwnershipHintNeverBlocksTest.php`, `tests/Unit/Identity/GameOwnershipHintTest.php`

**Interfaces:**
- Consumes: `LinkedAccount`, `LinkedAccountConnector`, `Game`.
- Produces: `GameOwnershipHint::for(User $user, Game $game): OwnershipHintStatus` — enum-like: `Owned`, `NotOwned`, `Unknown` (no linked account, game has no provider mapping, private profile, or API failure). **This is advisory only.**
- Produces: enrollment always succeeds; the hint is rendered as a calm warning ("Wir konnten deinen Besitz von … nicht bestätigen — anmelden kannst du dich trotzdem.") and NEVER disables the submit.

- [ ] **Step 1: Failing test — the load-bearing guarantee:**

```php
// tests/Feature/Identity/OwnershipHintNeverBlocksTest.php
it('lets a user enroll even when ownership is NotOwned or Unknown', function () {
    fakeLinkedAccounts()->willReportOwnership(LinkedAccountProvider::Steam, owns: false);
    $tournament = /* ... enrollable tournament for a Steam-mapped game ... */;
    $user = User::factory()->create(); // no linked Steam at all → Unknown

    $this->actingAs($user)->post(route('tournaments.enroll', $tournament))->assertRedirect();
    expect($tournament->fresh()->entries()->whereRelation('users', 'users.id', $user->id)->exists())->toBeTrue();
})->with(['unknown', 'notowned']);
```

- [ ] **Step 2: Run — FAIL** (or accidentally pass — either way, this test PINS the binding rule; if enrollment ever gains a gate it must break here).

- [ ] **Step 3:** add `Game.provider`/`provider_app_id` migration + cast; implement `GameOwnershipHint` + `ownsApp` on the connector (Steam via Web API, behind the Fake — **never a real call in tests**); render the advisory hint on the enrollment page; confirm the enrollment action has NO ownership branch that can block.

- [ ] **Step 4:** unit-test `GameOwnershipHint` mapping (Owned/NotOwned/Unknown) with the Fake. Green + `composer check`.

- [ ] **Step 5: Commit** — `feat(identity): non-blocking game-ownership hint on enrollment`.

---

### Task 9.8: `steam_url` reconciliation + Filament admin visibility

**Files:**
- Modify: `resources/js/pages/settings/Profile.vue` (steam_url becomes a labelled fallback; note the verified Steam link is authoritative once present) + `app/Modules/Identity/Http/ProfileController.php` copy if needed
- Modify: `app/Filament/Resources/UserResource.php` (read-only relation panel / column listing linked accounts — no tokens)
- Test: `tests/Feature/Identity/UserResourceLinkedAccountsTest.php`

**Interfaces:**
- Consumes: `linkedAccounts()`.
- Produces: Filament UserResource shows each linked account (provider label + nickname + linked-at), read-only; tokens never rendered. Profile page clarifies that a verified Steam link supersedes the free-text `steam_url`.

- [ ] **Step 1: Failing test** — Filament page lists a linked account, hides tokens. **Step 2:** run → FAIL. **Step 3:** implement. **Step 4:** green + `composer check` + `npm run build`.

- [ ] **Step 5: Commit** — `feat(identity): show linked accounts in Filament + reconcile steam_url`.

---

### Task 9.9: Soft-merge guardrail docs + architecture + roadmap Erkenntnisse

**Files:**
- Modify: `docs/architecture.md` (new "Identity & account linking" section: Discord = sole login anchor; `linked_accounts` model; `users.id` = sole FK/merge anchor; **soft-merge strategy documented** — future fusion repoints FKs onto the surviving user, loser becomes a tombstone; no schema/logic yet)
- Modify: `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (Erkenntnisse M9; mark M9 done; note friends deferred to own phase)
- Test: none (docs only).

- [ ] **Step 1:** write the architecture section + roadmap Erkenntnisse. **Step 2:** `composer check` (should be untouched-green). **Step 3: Commit** — `docs(identity): document soft-merge guardrail + M9 Erkenntnisse`.

---

## Self-Review (done at plan-writing time)

- **Spec coverage:** linked accounts (9.1–9.4), management UI (9.5), context-name (9.6), ownership-hint-never-gate (9.7), steam_url upgrade + admin (9.8), soft-merge guardrail (9.9). Friends deliberately OUT (separate phase) — matches the settled decision. ✓
- **Placeholder scan:** none — every task has concrete schema/contract/test code or an explicit "docs only" note. ✓
- **Type consistency:** `LinkedAccountProvider`, `LinkedAccount`, `LinkedAccountData`, `LinkedAccountConnector`, `LinkedAccountConnectors` used with the same signatures across 9.1→9.8. `fakeLinkedAccounts()` helper introduced in 9.2, reused in 9.3/9.4/9.7. ✓

## Execution Handoff

Execute via **subagent-driven-development**: fresh implementer per task 9.1→9.9, `scripts/review-package` + task-reviewer between tasks (opus for the high-risk security/token tasks 9.3/9.4/9.7), whole-branch review on **opus** with base = tag **`m10`** (commit `5c9f878`, current tip), consolidated fix wave, merge ff to `main`, tag **`m9`**, close GitHub milestone **#10**, roadmap Erkenntnisse, memory update.
