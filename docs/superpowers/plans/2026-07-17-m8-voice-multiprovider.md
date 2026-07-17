# M8 — Voice-Multiprovider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generalize the single-backend Mumble voice integration into a provider-agnostic `VoiceClient` abstraction that runs Mumble **and** TeamSpeak simultaneously, mirroring the whole channel tree on every active backend, so a team can switch backends instantly (the target channel already exists) and the per-team choice only decides the highlighted join link.

**Architecture:** A `VoiceProvider` enum + generic `VoiceChannel` value object replace the Mumble-specific types; the `MumbleClient` contract becomes `VoiceClient` with two concretes (`HttpMumbleClient`, `HttpTeamSpeakClient`) each backed by a thin REST sidecar. A `VoiceProviders` registry resolves the set of active clients keyed by provider, and the three provisioning jobs fan out over that set, persisting channel IDs **per provider**. Join links are built per provider (`mumble://`, `ts3server://`); the match page and Discord embed show all providers, highlighting the team's default. TeamSpeak's sidecar and the real-infra parts follow the M6/M7 "code + config + docs now, real-verify later" split (mode A).

**Tech Stack:** PHP 8.4 · Laravel 13 · Filament v5 · Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue · Pest 4 · Docker Compose · Mumble Ice-REST sidecar (exists) · TeamSpeak ServerQuery-REST sidecar (new, Python/FastAPI mirroring `docker/mumble-admin/`)

## Global Constraints

- Code, comments, commits, docs in **English**; all UI copy in German via `lang/de/` (add a `lang/de/voice.php` group).
- **Conventional Commits** (`feat(voice): …`, `feat(teamspeak): …`, `refactor(voice): …`). TDD: failing test first wherever there is testable behavior; frequent commits; `composer check` (pint --test, phpstan level 8, pest) + the four frontend gates (`npm run lint:check`, `format:check`, `types:check`, `build`) green after **every** task.
- **External systems only via contracts + Fakes.** `VoiceClient` is the only door to Mumble/TeamSpeak. NEVER hit a real voice server or real sidecar in tests. `HttpTeamSpeakClient` is tested exclusively via `Http::fake()` + `Http::preventStrayRequests()`, exactly like `HttpMumbleClient`.
- **Every authorization through a Policy.** No client-supplied user IDs trusted. Privilege/state fields never `$fillable`.
- **Uploads to Laravel Storage on the PRIVATE disk**, authorized-download route only — never a public URL, never Base64 in the DB (voice-client installers reuse the M7.3 storage conventions).
- **Signalpult design system is binding** (`docs/design.md`): semantic role tokens only (no raw hex), Space Grotesk + JetBrains Mono (mono for machine data — host:port only), rationed amber accent, `LiveIndicator` for live state, all four states (empty/loading/error/normal), Rams quality floor (responsive, visible focus, `prefers-reduced-motion`, AA contrast). **Invoke the `frontend-design` skill first** for every UI task (8.6, 8.7) and design against `docs/design.md`.
- **Mode A (this phase):** everything code-testable is built now against Fakes; the TeamSpeak sidecar image, its compose service, and any real SSH/registry/live-occupant data are written as code + config + docs and real-verified later on real hardware. Mark deferred items with a `// M8-infra-later:` note and a docs line.
- **No PHP-version-capped dependency:** `planetteamspeak/ts3-php-framework` (latest 1.3.0) declares PHP 8.1/8.2/8.3 only and would break `composer install` on PHP 8.4. Do **not** add it. TeamSpeak is reached through the `docker/teamspeak-admin/` ServerQuery-REST sidecar so the PHP side stays dependency-free and `Http::fake`-testable — the same decision shape as M3's "purpose-built sidecar over unmaintained murmur-rest".

## File Structure

**Renamed / generalized (Task 8.1):**
- `app/Modules/Voice/Contracts/MumbleClient.php` → `VoiceClient.php` (interface `VoiceClient`)
- `app/Modules/Voice/Domain/MumbleChannel.php` → `VoiceChannel.php` (class `VoiceChannel`)
- `app/Modules/Voice/HttpMumbleClient.php` (keeps name; now `implements VoiceClient`)
- `app/Modules/Voice/Testing/FakeMumbleClient.php` (keeps name; now `implements VoiceClient`)

**New:**
- `app/Modules/Voice/Domain/VoiceProvider.php` — string enum (`Mumble`, `TeamSpeak`) with `label()`, connection accessors
- `app/Modules/Voice/VoiceProviders.php` — registry resolving active `VoiceClient` instances keyed by `VoiceProvider`
- `app/Modules/Voice/HttpTeamSpeakClient.php` — `implements VoiceClient`, HTTP client for the ts-admin sidecar
- `app/Modules/Voice/Support/VoiceJoinLink.php` — provider-aware join-link builder
- `app/Modules/Voice/Models/VoiceClientInstaller.php` — orga-managed client installer (private disk)
- `app/Modules/Voice/Policies/VoiceClientInstallerPolicy.php`
- `app/Modules/Voice/Filament/Resources/VoiceClientInstallerResource.php` (+ pages)
- `app/Modules/Voice/Http/VoiceSetupController.php` — participant "Voice einrichten" page + installer download
- `resources/js/pages/Voice/Setup.vue`
- `docker/teamspeak-admin/{app.py,Dockerfile,requirements.txt,README.md}` — ServerQuery-REST sidecar
- `lang/de/voice.php`
- migrations: add `voice_provider` to `teams`; create `voice_client_installers`

**Modified:**
- `config/services.php` — add `voice` (default_provider, providers) + `teamspeak` blocks
- `app/Providers/AppServiceProvider.php` — bind `VoiceClient` (default = Mumble concrete), register `VoiceProviders`, keep the three `Event::listen` lines
- `app/Modules/Voice/Jobs/{ProvisionTournamentVoiceJob,ProvisionMatchVoiceJob,CleanupTournamentVoiceJob}.php` — fan out over active providers, per-provider persistence
- `app/Modules/Teams/Models/Team.php` — `voice_provider` cast + fillable (preference, not privilege)
- `app/Modules/Tournaments/Http/TournamentPageController.php` — `voiceLinksFor()` returns per-provider list
- `app/Modules/Discord/Support/MatchEmbed.php` — list all providers, highlight default
- `resources/js/types/tournaments.ts` — `MatchVoiceLink` → list of `{provider,label,url,isDefault}`
- `resources/js/pages/Tournaments/Show.vue` (or the match panel component) — render provider links
- `tests/Pest.php` — `fakeMumble()` kept; add `fakeVoice()` returning a per-provider fake map
- `docker-compose.yml` / prod profile — add `teamspeak` + `teamspeak-admin` services (prod profile, mode A)

**Interfaces produced (referenced across tasks):**
- `VoiceChannel(int $id, string $name, ?int $parentId, bool $temporary, int $occupants = 0)`
- `VoiceProvider: Mumble='mumble', TeamSpeak='teamspeak'`
- `VoiceClient::{createChannel,renameChannel,deleteChannel,listChannels,provider}` (+ `listOccupants` added in 8.8)
- `VoiceProviders::active(): array<string, VoiceClient>` (keyed by provider value) and `VoiceProviders::for(VoiceProvider): VoiceClient`
- `VoiceJoinLink::for(VoiceProvider $p, string $channelName): string`
- persisted shape `tournaments.settings['voice'][<provider>] = ['tournament_channel_id'=>int,'team_channel_ids'=>int[]]`
- persisted shape `matches.voice_channels[<provider>] = ['entry1_channel_id'=>int,'entry2_channel_id'=>int]`

---

### Task 8.1: Generalize the contract — `VoiceClient`, `VoiceChannel`, `VoiceProvider`

Foundational mechanical refactor: rename the Mumble-specific contract and value object to provider-agnostic names, add the provider enum, and add a `provider()` accessor to the contract so a client knows which backend it is. No behavior change; every existing Voice test stays green after renames.

**Files:**
- Rename: `app/Modules/Voice/Contracts/MumbleClient.php` → `app/Modules/Voice/Contracts/VoiceClient.php`
- Rename: `app/Modules/Voice/Domain/MumbleChannel.php` → `app/Modules/Voice/Domain/VoiceChannel.php`
- Create: `app/Modules/Voice/Domain/VoiceProvider.php`
- Modify: `app/Modules/Voice/HttpMumbleClient.php`, `app/Modules/Voice/Testing/FakeMumbleClient.php`, `app/Modules/Voice/Support/MumbleJoinLink.php`, all three Jobs, `app/Providers/AppServiceProvider.php`, `app/Modules/Discord/Support/MatchEmbed.php`, `app/Modules/Tournaments/Http/TournamentPageController.php`, `tests/Pest.php`
- Test: `tests/Unit/Voice/VoiceProviderTest.php` (new); existing Voice tests updated for renamed symbols

**Interfaces:**
- Consumes: nothing new.
- Produces: `VoiceProvider` enum; `VoiceChannel` value object (adds `int $occupants = 0` for 8.8, defaulted so 8.1 stays no-behavior); `VoiceClient` interface with all four existing methods **plus** `public function provider(): VoiceProvider;`.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Voice/VoiceProviderTest.php`

```php
<?php

declare(strict_types=1);

use App\Modules\Voice\Domain\VoiceProvider;

it('exposes a stable string value and a German label for each provider', function () {
    expect(VoiceProvider::Mumble->value)->toBe('mumble')
        ->and(VoiceProvider::TeamSpeak->value)->toBe('teamspeak')
        ->and(VoiceProvider::Mumble->label())->toBe('Mumble')
        ->and(VoiceProvider::TeamSpeak->label())->toBe('TeamSpeak');
});

it('lists the providers marked active in config', function () {
    config(['services.voice.providers' => ['mumble', 'teamspeak']]);
    expect(VoiceProvider::active())->toBe([VoiceProvider::Mumble, VoiceProvider::TeamSpeak]);

    config(['services.voice.providers' => ['mumble']]);
    expect(VoiceProvider::active())->toBe([VoiceProvider::Mumble]);
});
```

- [ ] **Step 2: Run it, verify it fails** — `./vendor/bin/pest --filter=VoiceProvider` → FAIL (class not found).

- [ ] **Step 3: Create `VoiceProvider`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Voice\Domain;

enum VoiceProvider: string
{
    case Mumble = 'mumble';
    case TeamSpeak = 'teamspeak';

    public function label(): string
    {
        return match ($this) {
            self::Mumble => 'Mumble',
            self::TeamSpeak => 'TeamSpeak',
        };
    }

    /**
     * The providers enabled for this installation, in config order.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('services.voice.providers', ['mumble']);

        return array_values(array_filter(
            array_map(static fn (string $value): ?self => self::tryFrom($value), $configured),
        ));
    }
}
```

- [ ] **Step 4: Rename `MumbleChannel` → `VoiceChannel`, add `occupants`**

`git mv app/Modules/Voice/Domain/MumbleChannel.php app/Modules/Voice/Domain/VoiceChannel.php`, then:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Voice\Domain;

/**
 * A voice channel as reported by a provider's admin sidecar
 * (Mumble: docker/mumble-admin; TeamSpeak: docker/teamspeak-admin).
 */
final readonly class VoiceChannel
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $parentId,
        public bool $temporary,
        public int $occupants = 0,
    ) {}
}
```

- [ ] **Step 5: Rename `MumbleClient` → `VoiceClient`, add `provider()`**

`git mv app/Modules/Voice/Contracts/MumbleClient.php app/Modules/Voice/Contracts/VoiceClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Voice\Contracts;

use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;

interface VoiceClient
{
    public function provider(): VoiceProvider;

    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): VoiceChannel;

    public function renameChannel(int $channelId, string $name): void;

    public function deleteChannel(int $channelId): void;

    /**
     * @return array<int, VoiceChannel>
     */
    public function listChannels(): array;
}
```

- [ ] **Step 6: Update the two concretes + join link + jobs + providers**

In `HttpMumbleClient`: `implements VoiceClient`, return `VoiceChannel`, add `public function provider(): VoiceProvider { return VoiceProvider::Mumble; }`, swap `MumbleChannel`→`VoiceChannel` imports. In `FakeMumbleClient`: same, plus `public function provider(): VoiceProvider { return VoiceProvider::Mumble; }`. In `MumbleJoinLink`, jobs, `MatchEmbed`, `TournamentPageController`, `AppServiceProvider`: replace `MumbleClient`→`VoiceClient` and `MumbleChannel`→`VoiceChannel` type-hints/imports. In `tests/Pest.php`, keep `fakeMumble()` but bind against `VoiceClient::class`.

Run: `rg -l 'MumbleClient|MumbleChannel' app tests` → only expected historical strings (docblocks) remain; fix all type usages.

- [ ] **Step 7: Run the full Voice suite + static analysis**

Run: `./vendor/bin/pest tests/Feature/Voice tests/Unit/Voice --filter=Voice` then `composer check`. Expected: all green (renames are behavior-preserving; the new enum test passes).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(voice): generalize MumbleClient contract to VoiceClient + VoiceProvider"
```

---

### Task 8.2: Provider registry + `voice` config + `fakeVoice()` helper

Introduce a registry that resolves the set of **active** `VoiceClient` instances (keyed by provider value) from config, and the config block that lists active providers. This is what the jobs will fan out over. Default single-instance binding of `VoiceClient` stays (Mumble) so nothing else breaks.

**Files:**
- Create: `app/Modules/Voice/VoiceProviders.php`
- Modify: `config/services.php`, `app/Providers/AppServiceProvider.php`, `tests/Pest.php`
- Test: `tests/Feature/Voice/VoiceProvidersTest.php`

**Interfaces:**
- Consumes: `VoiceProvider`, `VoiceClient` (8.1).
- Produces: `VoiceProviders::active(): array<string, VoiceClient>` (keys = provider `->value`), `VoiceProviders::for(VoiceProvider): VoiceClient`; test helper `fakeVoice(): array<string, FakeVoiceClient>`.

- [ ] **Step 1: Add config** — `config/services.php`

```php
'voice' => [
    'default_provider' => env('VOICE_DEFAULT_PROVIDER', 'mumble'),
    'providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VOICE_PROVIDERS', 'mumble')),
    ))),
],

'teamspeak' => [
    'host' => env('TEAMSPEAK_HOST', 'localhost'),
    'port' => env('TEAMSPEAK_PORT', 9987),
    'rest_url' => env('TEAMSPEAK_ADMIN_REST_URL', 'http://teamspeak-admin:8000'),
    'token' => env('TEAMSPEAK_ADMIN_TOKEN'),
],
```

(Keep the existing `mumble` block as-is.)

- [ ] **Step 2: Write the failing test** — `tests/Feature/Voice/VoiceProvidersTest.php`

```php
<?php

declare(strict_types=1);

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\HttpTeamSpeakClient;
use App\Modules\Voice\VoiceProviders;

it('resolves only the providers listed as active, keyed by value', function () {
    config(['services.voice.providers' => ['mumble', 'teamspeak']]);

    $active = app(VoiceProviders::class)->active();

    expect(array_keys($active))->toBe(['mumble', 'teamspeak'])
        ->and($active['mumble'])->toBeInstanceOf(HttpMumbleClient::class)
        ->and($active['teamspeak'])->toBeInstanceOf(HttpTeamSpeakClient::class);
});

it('resolves a single provider when only one is active', function () {
    config(['services.voice.providers' => ['mumble']]);

    $active = app(VoiceProviders::class)->active();

    expect(array_keys($active))->toBe(['mumble']);
});

it('returns the concrete client for a given provider', function () {
    expect(app(VoiceProviders::class)->for(VoiceProvider::TeamSpeak))
        ->toBeInstanceOf(VoiceClient::class);
});
```

- [ ] **Step 3: Run it, verify it fails** — `./vendor/bin/pest --filter=VoiceProviders` → FAIL (class not found — `HttpTeamSpeakClient` arrives in 8.3; for 8.2 stub it minimally so the registry compiles, see note).

> Note: create a minimal `HttpTeamSpeakClient` stub in this task (constructor + `provider()` returning `VoiceProvider::TeamSpeak` + method bodies throwing `RuntimeException('not implemented')`) so the registry resolves; 8.3 fills in the real bodies + tests. This keeps 8.2 independently green.

- [ ] **Step 4: Create `VoiceProviders`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Voice;

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceProvider;
use Illuminate\Contracts\Container\Container;

final readonly class VoiceProviders
{
    public function __construct(private Container $app) {}

    /**
     * @return array<string, VoiceClient>
     */
    public function active(): array
    {
        $clients = [];
        foreach (VoiceProvider::active() as $provider) {
            $clients[$provider->value] = $this->for($provider);
        }

        return $clients;
    }

    public function for(VoiceProvider $provider): VoiceClient
    {
        return match ($provider) {
            VoiceProvider::Mumble => new HttpMumbleClient(
                (string) config('services.mumble.rest_url'),
                (string) config('services.mumble.ice_secret'),
            ),
            VoiceProvider::TeamSpeak => new HttpTeamSpeakClient(
                (string) config('services.teamspeak.rest_url'),
                (string) config('services.teamspeak.token'),
            ),
        };
    }
}
```

- [ ] **Step 5: Register + minimal stub + fake helper**

In `AppServiceProvider::register()` keep the existing `VoiceClient` bind (default Mumble) and add `$this->app->singleton(VoiceProviders::class);`. Create the `HttpTeamSpeakClient` stub. In `tests/Pest.php` add:

```php
/**
 * Bind an in-memory fake for every active voice provider and return them keyed by value.
 *
 * @return array<string, FakeVoiceClient>
 */
function fakeVoice(array $providers = ['mumble', 'teamspeak']): array
{
    config(['services.voice.providers' => $providers]);

    $fakes = [];
    foreach ($providers as $value) {
        $fakes[$value] = new FakeVoiceClient(VoiceProvider::from($value));
    }

    app()->bind(VoiceProviders::class, fn () => new class($fakes) extends VoiceProviders {
        /** @param array<string, FakeVoiceClient> $fakes */
        public function __construct(private array $fakes)
        {
            parent::__construct(app());
        }

        public function active(): array
        {
            return $this->fakes;
        }

        public function for(VoiceProvider $provider): VoiceClient
        {
            return $this->fakes[$provider->value];
        }
    });

    return $fakes;
}
```

> Rename `FakeMumbleClient` → `FakeVoiceClient` (provider-parameterized: constructor takes a `VoiceProvider`, `provider()` returns it) as part of this task so one fake serves both backends. Keep `fakeMumble()` as a thin wrapper returning `fakeVoice(['mumble'])['mumble']` for the existing single-provider tests.

- [ ] **Step 6: Run tests + static analysis** — `./vendor/bin/pest --filter="VoiceProviders|Voice"` then `composer check`. Green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(voice): active-provider registry + voice/teamspeak config + fakeVoice helper"
```

---

### Task 8.3: `HttpTeamSpeakClient` + TeamSpeak ServerQuery-REST sidecar

Fill in the real TeamSpeak HTTP client (mirrors `HttpMumbleClient`: transient-failure retry, immediate throw on client errors), tested only via `Http::fake()`. Write the `docker/teamspeak-admin/` sidecar (FastAPI wrapping a TeamSpeak ServerQuery connection) + Dockerfile + compose services as **config/docs** (real run deferred, mode A).

**Files:**
- Modify: `app/Modules/Voice/HttpTeamSpeakClient.php` (fill in the 8.2 stub)
- Create: `docker/teamspeak-admin/{app.py,Dockerfile,requirements.txt,README.md}`
- Modify: `docker-compose.yml` (prod profile: `teamspeak` + `teamspeak-admin` services)
- Create: `docs/teamspeak-setup.md`
- Test: `tests/Feature/Voice/HttpTeamSpeakClientTest.php`

**Interfaces:**
- Consumes: `VoiceClient`, `VoiceChannel`, `VoiceProvider` (8.1).
- Produces: fully implemented `HttpTeamSpeakClient` honoring the sidecar REST contract: `GET /channels`, `POST /channels {name,parent,temporary}`, `PATCH /channels/{id} {name}`, `DELETE /channels/{id}` — identical JSON shape to `docker/mumble-admin` (`{id,name,parent,temporary,occupants}`), Bearer-token auth.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Voice/HttpTeamSpeakClientTest.php`

Model it on `tests/Feature/Voice/HttpMumbleClientTest.php`. Cover: create (POST, maps `parent:0`→`null`, returns `VoiceChannel` with `occupants`), parent/temporary flags, rename (PATCH), delete (DELETE), list (GET), retry on 429/503/ConnectionException (3 attempts), no-retry on 404. Use `Http::fake()` + `Http::preventStrayRequests()`. Example:

```php
<?php

declare(strict_types=1);

use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpTeamSpeakClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function tsClient(): HttpTeamSpeakClient
{
    return new HttpTeamSpeakClient('http://ts-admin.test', 'secret-token');
}

it('creates a channel and maps root parent to null', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response(
            ['id' => 7, 'name' => '🏆 Cup', 'parent' => 0, 'temporary' => false, 'occupants' => 0],
            201,
        ),
    ]);

    $channel = tsClient()->createChannel('🏆 Cup');

    expect($channel->id)->toBe(7)
        ->and($channel->parentId)->toBeNull()
        ->and($channel->name)->toBe('🏆 Cup');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r['name'] === '🏆 Cup' && $r['parent'] === 0);
});

it('reports its provider as teamspeak', function () {
    expect(tsClient()->provider())->toBe(VoiceProvider::TeamSpeak);
});

it('retries transient 503 then succeeds', function () {
    Http::fakeSequence('ts-admin.test/channels')
        ->push('', 503)
        ->push(['id' => 1, 'name' => 'x', 'parent' => 0, 'temporary' => true, 'occupants' => 0], 201);

    expect(tsClient()->createChannel('x', null, true)->id)->toBe(1);
});

it('does not retry a 404', function () {
    Http::fake(['ts-admin.test/channels/999' => Http::response('', 404)]);

    tsClient()->deleteChannel(999);
})->throws(Illuminate\Http\Client\RequestException::class);
```

- [x] **Step 2: Run it, verify it fails** — `./vendor/bin/pest --filter=HttpTeamSpeakClient` → FAIL (stub throws).

- [x] **Step 3: Implement `HttpTeamSpeakClient`** — copy `HttpMumbleClient`'s structure verbatim (same retry helper, same `parent:0`↔`null` normalization, same error mapping), changing only the class name, `provider()` return, and mapping `occupants` from the response into `VoiceChannel`. Read `app/Modules/Voice/HttpMumbleClient.php` first and mirror it exactly so behavior (retry counts, which statuses retry) is identical.

- [x] **Step 4: Run it, verify it passes** — `./vendor/bin/pest --filter=HttpTeamSpeakClient` → PASS.

- [x] **Step 5: Write the sidecar (config/docs, mode A)** — `docker/teamspeak-admin/app.py`: a FastAPI app exposing the identical REST contract as `docker/mumble-admin/app.py` (same routes, same Bearer auth via `TEAMSPEAK_ADMIN_TOKEN`, `/healthz`), backed by a TeamSpeak ServerQuery connection (`telnetlib`/`py-ts3`) to `TEAMSPEAK_HOST:10011` with `TEAMSPEAK_QUERY_USER`/`TEAMSPEAK_QUERY_PASSWORD`. Map ServerQuery `channelcreate`/`channeledit`/`channeldelete`/`channellist -client` to the routes; return `{id,name,parent,temporary,occupants}` (occupants = channel client count from `channellist -client` / `clientlist`). `Dockerfile` + `requirements.txt` mirror `docker/mumble-admin`. `README.md` documents ServerQuery whitelist/query-account setup. Add a `// M8-infra-later` note that the image is unbuilt/unverified this phase.

  > Deviation: no ServerQuery library dependency (neither `telnetlib`, deprecated/removed in Python 3.13, nor an unverified `py-ts3` package) — a small hand-rolled `_ServerQueryConnection` (raw TCP socket, ServerQuery line protocol) in `app.py`, same "own minimal sidecar over an uncertain third-party dependency" call the M3 mumble-admin sidecar made against `murmur-rest`.

- [x] **Step 6: Compose services (prod profile)** — in `compose.yml` (this repo's actual compose filename — Compose auto-detects it same as `docker-compose.yml`), added `teamspeak` (image `teamspeak:latest`, ports off-default on the host to match the project's non-default-port convention, `TS3SERVER_LICENSE=accept`) and `teamspeak-admin` (build `docker/teamspeak-admin`, env `TEAMSPEAK_HOST=teamspeak`, `TEAMSPEAK_ADMIN_TOKEN`), both under `profiles: [prod]` so the dev stack is byte-identical. Verified `docker compose --profile prod config` parses (no real `up`).

- [x] **Step 7: Docs** — `docs/teamspeak-setup.md`: ServerQuery query-account creation, the admin token, the port mapping, and the mode-A "unverified until real hardware" note. Linked here (this plan doc is M8's living "roadmap" reference, same role the master roadmap's per-phase Erkenntnisse sections play for earlier phases).

- [x] **Step 8: Run static analysis + commit**

Run: `composer check` and `docker compose --profile prod config >/dev/null && echo OK`.

```bash
git add -A
git commit -m "feat(teamspeak): HttpTeamSpeakClient over ServerQuery-REST sidecar (image+compose+docs, mode A)"
```

---

### Task 8.4: Mirrored provisioning — per-provider channel storage + fan-out jobs

Rewrite the three jobs to provision the whole channel tree on **every** active provider and persist channel IDs **per provider**, and to clean up on every provider. This is the heart of the mirror architecture: a team can switch backends instantly because the target channel already exists on both.

**Files:**
- Modify: `app/Modules/Voice/Jobs/ProvisionTournamentVoiceJob.php`, `ProvisionMatchVoiceJob.php`, `CleanupTournamentVoiceJob.php`
- Test: `tests/Feature/Voice/VoiceOrchestrationTest.php` (extend to two providers)

**Interfaces:**
- Consumes: `VoiceProviders` (8.2).
- Produces: persisted shapes `tournaments.settings['voice'][<provider>] = ['tournament_channel_id'=>int,'team_channel_ids'=>int[]]` and `matches.voice_channels[<provider>] = ['entry1_channel_id'=>int,'entry2_channel_id'=>int]`. Idempotency is **per provider** (skip a provider whose subtree already exists; still provision a newly-activated provider on a re-fire).

- [ ] **Step 1: Extend the failing test** — in `VoiceOrchestrationTest.php`, drive with `$fakes = fakeVoice(['mumble','teamspeak']);` and assert the tournament root + team channels are created on **both** fakes (`$fakes['mumble']->assertChannelCreated(...)`, `$fakes['teamspeak']->assertChannelCreated(...)`), that `settings['voice']['mumble']` and `settings['voice']['teamspeak']` are both populated with distinct IDs, that a re-fire creates nothing new on an already-provisioned provider, and that cleanup deletes on both. Add a case: with only `['mumble']` active, only the mumble subtree exists; re-firing after activating teamspeak provisions teamspeak without touching mumble.

- [ ] **Step 2: Run it, verify it fails** — `./vendor/bin/pest --filter=VoiceOrchestration` → FAIL (single-provider shape).

- [ ] **Step 3: Rewrite `ProvisionTournamentVoiceJob::handle`** — inject `VoiceProviders`, loop `foreach ($providers->active() as $value => $client)`, skip `if (isset($settings['voice'][$value]['tournament_channel_id']))`, create root + per-entry team channels on `$client`, write `$settings['voice'][$value] = [...]`. Persist once after the loop. Keep the `team_size > 1` gate and the `Withdrawn` filter unchanged.

- [ ] **Step 4: Rewrite `ProvisionMatchVoiceJob::handle`** — loop active providers; skip a provider whose `$match->voice_channels[$value]` is already set; create both entry channels (temporary) on `$client`; write `$voiceChannels[$value] = ['entry1_channel_id'=>…,'entry2_channel_id'=>…]`. Persist merged.

- [ ] **Step 5: Rewrite `CleanupTournamentVoiceJob::handle`** — for each provider key in `settings['voice']`, resolve `$providers->for(VoiceProvider::from($key))` and delete team channels + root; then for each match with `voice_channels`, delete both entry channels **per provider key present**; null out storage. Tolerate a provider key that is no longer active (still attempt deletion via `for()` so leftover channels get cleaned).

- [ ] **Step 6: Run tests + static analysis** — `./vendor/bin/pest --filter="VoiceOrchestration|Voice"` then `composer check`. Green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(voice): mirror channel tree across all active providers with per-provider persistence"
```

---

### Task 8.5: `voice_provider` on teams + provider-aware `VoiceJoinLink`

Add the per-team default-provider preference and a join-link builder that produces the correct scheme per provider (`mumble://host:port/Channel`, `ts3server://host?port=…&channel=Channel`). The team's choice only selects the highlighted link; channels exist on all providers regardless.

**Files:**
- Create: migration `database/migrations/2026_07_17_100000_add_voice_provider_to_teams_table.php`
- Modify: `app/Modules/Teams/Models/Team.php`
- Create: `app/Modules/Voice/Support/VoiceJoinLink.php`
- Modify: `app/Modules/Voice/Support/MumbleJoinLink.php` (delegate to `VoiceJoinLink` or remove if unused after 8.6)
- Test: `tests/Unit/Voice/VoiceJoinLinkTest.php`, `tests/Feature/Teams/TeamVoiceProviderTest.php`

**Interfaces:**
- Consumes: `VoiceProvider` (8.1).
- Produces: `VoiceJoinLink::for(VoiceProvider $p, string $channelName): string`; `Team::$voice_provider` (nullable `VoiceProvider` cast — a preference, so it may be `$fillable`); helper `VoiceJoinLink::defaultProviderFor(?VoiceProvider $teamChoice): VoiceProvider` falling back to `services.voice.default_provider`.

- [ ] **Step 1: Migration** — add `->string('voice_provider')->nullable()` to `teams`. `down()` drops it.

- [ ] **Step 2: Write the failing test** — `tests/Unit/Voice/VoiceJoinLinkTest.php`

```php
<?php

declare(strict_types=1);

use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Support\VoiceJoinLink;

beforeEach(fn () => config([
    'services.mumble.host' => 'voice.lan', 'services.mumble.port' => 64738,
    'services.teamspeak.host' => 'voice.lan', 'services.teamspeak.port' => 9987,
    'services.voice.default_provider' => 'mumble',
]));

it('builds a mumble deep link', function () {
    expect(VoiceJoinLink::for(VoiceProvider::Mumble, 'Team Alpha'))
        ->toBe('mumble://voice.lan:64738/Team Alpha');
});

it('builds a ts3server connect link with port and channel', function () {
    expect(VoiceJoinLink::for(VoiceProvider::TeamSpeak, 'Team Alpha'))
        ->toBe('ts3server://voice.lan?port=9987&channel=Team%20Alpha');
});

it('falls back to the configured default provider when a team has no choice', function () {
    expect(VoiceJoinLink::defaultProviderFor(null))->toBe(VoiceProvider::Mumble);
    expect(VoiceJoinLink::defaultProviderFor(VoiceProvider::TeamSpeak))->toBe(VoiceProvider::TeamSpeak);
});
```

- [ ] **Step 3: Run it, verify it fails** — `./vendor/bin/pest --filter=VoiceJoinLink` → FAIL.

- [ ] **Step 4: Implement `VoiceJoinLink`** — `for()` switches on provider: Mumble = `"mumble://{host}:{port}/{$channelName}"` (path unencoded, matching existing `MumbleJoinLink` behavior); TeamSpeak = `"ts3server://{host}?port={port}&channel=".rawurlencode($channelName)`. `defaultProviderFor()` returns `$teamChoice ?? VoiceProvider::from(config('services.voice.default_provider'))`.

- [ ] **Step 5: Wire the Team model + test** — add `'voice_provider' => VoiceProvider::class` to `Team::casts()` and `'voice_provider'` to `$fillable`. `TeamVoiceProviderTest`: a team persists and reads back `VoiceProvider::TeamSpeak`; default is `null`.

- [ ] **Step 6: Point `MumbleJoinLink` at `VoiceJoinLink`** — make `MumbleJoinLink::for()` delegate to `VoiceJoinLink::for(VoiceProvider::Mumble, …)` (keeps 8.1-era callers green until 8.6 replaces them).

- [ ] **Step 7: Run tests + static analysis + commit**

```bash
git add -A
git commit -m "feat(voice): per-team voice_provider preference + provider-aware VoiceJoinLink (mumble+ts3server)"
```

---

### Task 8.6: Match page + Discord embed — show all providers, highlight the default

Surface **both** providers' join links wherever the single Mumble link was shown, marking the team's default provider as highlighted. Follows the Signalpult design system (invoke `frontend-design` first).

**Files:**
- Modify: `app/Modules/Tournaments/Http/TournamentPageController.php`
- Modify: `resources/js/types/tournaments.ts`, `resources/js/pages/Tournaments/Show.vue` (or the match voice panel component)
- Modify: `app/Modules/Discord/Support/MatchEmbed.php`
- Modify: `lang/de/voice.php`, `lang/de/discord.php`
- Test: `tests/Feature/Tournaments/TournamentPageVoiceLinksTest.php`, extend `tests/Feature/Discord/MatchEmbedTest.php`

**Interfaces:**
- Consumes: `VoiceJoinLink`, `Team::$voice_provider`, per-provider `voice_channels` (8.4/8.5).
- Produces: prop `myMatchVoiceLinks: Array<{provider:string,label:string,url:string,isDefault:boolean}>` (empty array when none provisioned).

- [ ] **Step 1: Invoke the `frontend-design` skill** and design the voice-link cluster against `docs/design.md` (host:port in `font-mono`; default provider gets the rationed amber accent; the others are quiet secondary links; empty state when no channel yet).

- [ ] **Step 2: Write the failing test** — `TournamentPageVoiceLinksTest`: a match with `voice_channels` on both providers yields two entries in `myMatchVoiceLinks`, the one matching the viewer's team `voice_provider` (or the config default) has `isDefault: true`, and the URLs match the schemes. A match with no channels yields `[]`.

- [ ] **Step 3: Run it, verify it fails** — FAIL (prop still a single string).

- [ ] **Step 4: Implement `voiceLinksFor()`** — replace `voiceLinkFor()`: for each provider key present in `$match->voice_channels`, pick the viewer's entry channel (entry1/entry2 by `entry1_id === $myEntry->id`), build `VoiceJoinLink::for(provider, $myEntry->display_name)`, set `isDefault` via `VoiceJoinLink::defaultProviderFor($myEntry->team?->voice_provider)`. Return the list.

- [ ] **Step 5: Update the Vue type + template** — `MatchVoiceLink` type becomes the list shape; render the cluster; German copy from `lang/de/voice.php` (`voice.join.heading`, `voice.join.default_hint`).

- [ ] **Step 6: Update `MatchEmbed::voiceLink()`** — list one line per provider present, prefixing the default with the amber-equivalent marker (text — Discord embed), German copy via `lang/de/discord.php`.

- [ ] **Step 7: Verify UI** — `preview_start` (dev server), navigate to a seeded tournament match page, `preview_screenshot` light + dark; confirm links render, focus is visible, mono only on host:port. Fix against source if needed.

- [ ] **Step 8: Run tests + all frontend gates + commit**

Run: `composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build`.

```bash
git add -A
git commit -m "feat(voice): show all provider join links on match page + Discord embed, highlight default"
```

---

### Task 8.7: Voice-setup participant page + orga-managed client installers

A "Voice einrichten" participant page with per-provider connection data (host/port, one-click connect links) and downloadable Mumble/TeamSpeak client installers hosted in LANoMAT (private disk, authorized download), plus an orga Filament resource to upload/replace installers and mark the current version. Reuses the M7.3 private-disk + authorized-download conventions.

**Files:**
- Create: migration `..._create_voice_client_installers_table.php`
- Create: `app/Modules/Voice/Models/VoiceClientInstaller.php`, `app/Modules/Voice/Policies/VoiceClientInstallerPolicy.php`
- Create: `app/Modules/Voice/Filament/Resources/VoiceClientInstallerResource.php` (+ List/Create/Edit pages)
- Create: `app/Modules/Voice/Http/VoiceSetupController.php`, `resources/js/pages/Voice/Setup.vue`
- Modify: `routes/web.php`, `lang/de/voice.php`, `app/Providers/AppServiceProvider.php` (register policy if not auto-discovered)
- Test: `tests/Feature/Voice/VoiceSetupPageTest.php`, `tests/Feature/Voice/VoiceClientInstallerTest.php`, `tests/Feature/Voice/VoiceClientInstallerPolicyTest.php`

**Interfaces:**
- Consumes: `VoiceProvider` (8.1), `VoiceJoinLink` (8.5), private disk conventions from `app/Modules/Files/` (M7.3).
- Produces: `VoiceClientInstaller` (columns: `provider` string, `platform` string enum {windows,macos,linux}, `version` string, `path` (private-disk, non-fillable), `original_name`, `is_current` bool). Authorized download route `voice.installers.download`. Participant page route `voice.setup`.

- [ ] **Step 1: Migration + model** — `voice_client_installers`: `provider`, `platform`, `version`, `path`, `original_name`, `is_current` (bool, default false), timestamps, unique index on `(provider, platform)` where `is_current` (enforce single current in the action, not a partial unique, to stay portable). Model: `path`/`is_current` **not** `$fillable`; cast `provider`→`VoiceProvider`, `is_current`→bool.

- [ ] **Step 2: Write the failing policy test** — `VoiceClientInstallerPolicyTest`: `viewAny`/`create`/`update`/`delete` true only for orga (reuse the existing role helper, e.g. `$user->isOrga()`); a plain participant is denied manage but the download route is allowed for authenticated users.

- [ ] **Step 3: Policy + register** — `VoiceClientInstallerPolicy` mirrors the M7.3 `SharedFilePolicy` orga gate. Run it, verify green.

- [ ] **Step 4: Write the failing installer/action test** — `VoiceClientInstallerTest`: uploading a new installer for `(mumble, windows)` stores it on the private disk (assert `Storage::disk('private')` path, not public), marking it current unsets any previous current for the same `(provider, platform)`; download route streams the file for an authed user and 403s for a guest; the file is never exposed via a public URL.

- [ ] **Step 5: Implement upload/replace + download** — a `SetCurrentInstaller`/upload path in the Filament resource (or a small Action class) that stores to `private` disk and flips `is_current`. `VoiceSetupController::download()` authorizes via policy/`auth`, then `Storage::disk('private')->download($installer->path, $installer->original_name)`.

- [ ] **Step 6: Filament resource** — orga-only `VoiceClientInstallerResource`: upload (FileUpload to private disk), provider/platform/version fields, an "is current" toggle/action, table grouped by provider. Gate with the policy.

- [ ] **Step 7: Invoke `frontend-design`, build `Setup.vue`** — participant page: for each active provider, a card with host:port (`font-mono`), one-click connect link (`VoiceJoinLink::for(provider, <lobby/root channel name>)`), the current installers per platform (download buttons), and optional external official-download links. Four states (no installers yet = empty state). German copy from `lang/de/voice.php`. Add the route + a nav entry. Note the TeamSpeak-EULA redistribution caveat as a small info line (from the roadmap).

- [ ] **Step 8: Write the failing page test** — `VoiceSetupPageTest`: the page renders for an authed participant, lists active providers with their connect links, and shows the current installer download links; a guest is redirected to login.

- [ ] **Step 9: Verify UI** — `preview_start`, navigate to `/voice/setup` (seed an installer), screenshot light + dark, confirm empty + normal states, focus, mono only on host:port.

- [ ] **Step 10: Run tests + all frontend gates + commit**

```bash
git add -A
git commit -m "feat(voice): voice-setup page + orga-managed client installers on private disk"
```

---

### Task 8.8: #13 nachschärfung — live occupants + per-gameserver voice channel

Two focused refinements from issue #13: (a) surface **live occupant counts** per voice channel (contract + fake now; real numbers deferred with the sidecars, mode A), and (b) create a **voice channel per running game server** so players on a server can talk, tied into the M6 GameServers provisioning.

**Files:**
- Modify: `app/Modules/Voice/Contracts/VoiceClient.php` (+ both concretes + fake) — add occupant listing
- Modify: `app/Modules/Voice/Support/*` / a small `VoiceOccupancy` query
- Create: `app/Modules/Voice/Jobs/ProvisionServerVoiceJob.php` + a listener on the M6 server-ready event
- Modify: the tournament/match voice panel to show occupant counts (`LiveIndicator` where live)
- Test: `tests/Feature/Voice/VoiceOccupancyTest.php`, `tests/Feature/Voice/ServerVoiceProvisioningTest.php`

**Interfaces:**
- Consumes: `VoiceClient`, `VoiceProviders`, the M6 server-provisioned event + `ServerLink` (from `app/Modules/GameServers/`).
- Produces: `VoiceChannel::$occupants` populated from `listChannels()`; a per-server voice channel created on server-ready, mirrored across providers, persisted on the server/match record; occupant counts on the match page (real values `// M8-infra-later`).

- [ ] **Step 1: Confirm the M6 server-ready event** — read `app/Modules/GameServers/` to find the event fired when a game server becomes ready (the M6 `MatchReady`/warmup path) and the `ServerLink`/match column that stores server identity. Base the listener on the real event name.

- [ ] **Step 2: Write the failing occupancy test** — `VoiceOccupancyTest`: `FakeVoiceClient` can be seeded with occupant counts; `listChannels()` returns them on `VoiceChannel::$occupants`; a `VoiceOccupancy::forTournament($t)` helper aggregates counts per channel across active providers.

- [ ] **Step 3: Implement occupancy** — `FakeVoiceClient` gains a `setOccupants(int $channelId, int $n)` test hook; `createChannel` defaults `occupants` to 0; `listChannels` returns current counts. The Http concretes already map `occupants` from the sidecar JSON (8.3). Add the `VoiceOccupancy` aggregation helper.

- [ ] **Step 4: Write the failing per-server-voice test** — `ServerVoiceProvisioningTest`: firing the server-ready event creates a voice channel named after the server on every active provider (assert on both fakes) and persists the channel IDs; tearing down deletes them.

- [ ] **Step 5: Implement `ProvisionServerVoiceJob` + listener** — mirror `ProvisionMatchVoiceJob`'s fan-out; name the channel from the server/game; persist per provider on the server/match record; wire the listener in `AppServiceProvider`. Ensure cleanup covers it (extend `CleanupTournamentVoiceJob` or add a server-shutdown listener).

- [ ] **Step 6: Surface occupant counts (UI)** — invoke `frontend-design`; on the match/tournament voice cluster show a small occupant count per channel, using `LiveIndicator` when a channel has occupants. Real counts are `// M8-infra-later` (fake-seeded in tests, zero in dev until sidecars run). Screenshot light + dark.

- [ ] **Step 7: Run tests + all frontend gates + commit**

```bash
git add -A
git commit -m "feat(voice): live occupant counts + per-gameserver voice channel (issue #13)"
```

---

## Phase close-out (after Task 8.8)

- [ ] **Whole-branch review** on **opus**, base = tag `m7`, HEAD = last M8 commit, via `scripts/review-package m7 HEAD`. Consolidate findings into ONE fix wave; re-review.
- [ ] **`composer check`** + all four frontend gates green; full suite green.
- [ ] **Roadmap sync** — update `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`: mark M8 done, add an "Erkenntnisse M8" block (VoiceClient generalization, mirror-provisioning per-provider persistence, TeamSpeak ServerQuery-REST-sidecar decision + PHP-8.4 rationale, voice_provider = highlight-only, installers reuse M7.3 private disk, occupancy/per-server-channel mode-A deferral).
- [ ] **GitHub sync** — close milestone #9 (M8); ensure a tracking issue for M8 is closed; update Board #2.
- [ ] **Tag** `m8`; push to origin.
- [ ] **Update memory** — handoff frontmatter/top-line/M8 paragraph/NEXT; new `m8-voice-decisions.md`; `MEMORY.md` index line.

## Self-Review (against the spec)

**Spec coverage:** #2 "Mumble + TeamSpeak simultaneously, choice per team" → 8.1–8.6. Mirror-provisioning + instant switch → 8.4. voice_provider = highlight-only → 8.5/8.6. Voice-client-download section (roadmap line 359) → 8.7. #13 live occupants + channel per gameserver → 8.8. Discord-voice-as-optional-third → explicitly YAGNI this phase; the `VoiceProvider` enum + N-provider registry leave room without building it.

**Type consistency:** `VoiceProvider` (enum), `VoiceChannel` (adds `occupants` in 8.1, used in 8.3/8.8), `VoiceClient::provider()` (8.1, asserted 8.3), `VoiceProviders::active()`/`for()` (8.2, used 8.4/8.8), `VoiceJoinLink::for()`/`defaultProviderFor()` (8.5, used 8.6/8.7), per-provider persisted shapes (8.4, read by 8.6). Names consistent across tasks.

**Placeholder scan:** each code step carries real code or an exact mirror instruction against a named existing file; no TBD/"handle edge cases".
