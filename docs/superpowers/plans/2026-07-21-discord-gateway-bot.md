# Discord Gateway Bot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the LANoMAT Discord app a persistent Gateway connection (online presence + inbound events) via a thin discord.js sidecar that bridges to Laravel, keeping all domain logic in PHP.

**Architecture:** A Node discord.js sidecar (`docker/discord-gateway/`) holds the Gateway connection and forwards events over an internal, secret-authenticated HTTP endpoint to the Laravel app. Interactions migrate off the Ed25519 HTTP endpoint onto the Gateway (always-defer: the sidecar `deferReply()`s, PHP delivers content as a follow-up) reusing the existing `CommandRouter`. Voice-state updates drive a No-PII voice-presence read-model + empty broadcast; member/message/reaction events are surfaced as typed Laravel events with logging listeners.

**Tech Stack:** PHP 8.4 · Laravel 13 · Pest 4 · Node 22 + discord.js v14 · Docker Compose · Reverb (broadcast).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-21-discord-gateway-bot-design.md`.
- All domain logic stays in **PHP**; the Node sidecar is pure transport — no DB access, no business rules.
- External systems are reached only through contracts + fakes in tests; **never call the real Discord API in tests** (`FakeDiscordClient`, `Bus::fake`, `Event::fake`, `Http::fake`).
- **Every authorization goes through a Policy**; the acting user is resolved from the mapped Discord user (`User::where('discord_id', …)`), never a client-supplied local id.
- Broadcasts on public channels carry **no private data**: `broadcastWith()` returns `[]`; consumers pull a read-model. Voice presence exposes **only mapped LANoMAT display names** (unmapped Discord users are counted, not named).
- Pest runs **sequentially**, never `--parallel`.
- `composer check` (pint, phpstan level 8, pest) and `npm run lint:check` / `types:check` / `build` must be green after every task that touches their domain.
- Code/comments/commits/docs in **English**; user-facing copy in German via `lang/de/`.
- Conventional Commits; TDD (failing test first) wherever there is testable behavior; frequent commits.
- The ingress secret is compared with `hash_equals` (constant-time). The ingress route is internal (reached as `http://app/...` on the compose network), never exposed via Traefik.
- Privileged intents (`GUILD_MEMBERS`) and clearing the portal Interactions Endpoint URL are **manual portal steps** (documented in Task 7/8), not code.

## File Structure

**Create:**
- `app/Modules/Discord/Http/Middleware/VerifyGatewaySecret.php` — constant-time shared-secret guard for the ingress.
- `app/Modules/Discord/Http/GatewayIngressController.php` — single ingress entry point; routes forwarded events by `type`.
- `app/Modules/Discord/Support/HandleVoiceState.php` — applies a voice-state envelope to the read-model.
- `app/Modules/Discord/Support/VoicePresenceProjection.php` — pure No-PII projection of current voice occupancy.
- `app/Modules/Discord/Events/DiscordVoicePresenceUpdated.php` — empty broadcast on public `discord-voice`.
- `app/Modules/Discord/Events/DiscordGuildMemberJoined.php`, `DiscordGuildMemberLeft.php`, `DiscordMessageCreated.php`, `DiscordMessageReactionChanged.php` — typed inbound events.
- `app/Modules/Discord/Listeners/LogGatewayEvent.php` — logging listener for the four surface-only events.
- `app/Modules/Discord/Models/DiscordVoiceState.php` — read-model row.
- `app/Modules/Discord/Http/VoicePresenceController.php` — `GET /discord/voice`.
- `database/migrations/2026_07_21_150000_create_discord_voice_states_table.php`.
- `docker/discord-gateway/{bot.mjs,package.json,Dockerfile,README.md}` — the sidecar.
- `tests/Feature/Discord/{GatewayIngressAuthTest,GatewayInteractionTest,VoicePresenceTest,VoicePresenceEndpointTest,GatewaySurfaceEventsTest}.php`.

**Modify:**
- `config/services.php` — add `discord.gateway_bridge_secret`.
- `bootstrap/app.php` — register `discord.gateway` alias, add ingress path to CSRF exemptions; later remove `discord.signature` alias + its import.
- `routes/web.php` — add the ingress + voice routes; later remove the HTTP interactions route.
- `routes/channels.php` — document the public `discord-voice` channel.
- `.env.example` — new env keys.
- `compose.yml` — `discord-gateway` service.
- `CLAUDE.md`, `docs/architecture.md` — amend the "no gateway" rule; document the sidecar.

**Delete (Task 2):**
- `app/Modules/Discord/Http/InteractionsController.php`, `app/Modules/Discord/Http/Middleware/VerifyDiscordSignature.php`.
- `tests/Feature/Discord/InteractionsPingTest.php`, `InteractionsSignatureTest.php` (HTTP-endpoint-specific: the Gateway has no PING and no Ed25519).

---

### Task 1: Internal gateway ingress (auth + routing + interaction handling)

**Files:**
- Create: `app/Modules/Discord/Http/Middleware/VerifyGatewaySecret.php`
- Create: `app/Modules/Discord/Http/GatewayIngressController.php`
- Modify: `config/services.php` (discord block, after `'application_id'`)
- Modify: `bootstrap/app.php` (alias + CSRF exempt)
- Modify: `routes/web.php`
- Test: `tests/Feature/Discord/GatewayIngressAuthTest.php`, `tests/Feature/Discord/GatewayInteractionTest.php`

**Interfaces:**
- Produces: route name `discord.gateway.ingress` at `POST internal/discord/gateway`, middleware alias `discord.gateway`. Request body shape `{ "type": string, "data": object }`; for `type === "interaction"`, `data` is a raw Discord interaction payload (has `token`, `application_id`, `data.name`, `data.options`, `member.user.id`).
- Consumes: `CommandRouter::dispatch(array): array`, `InteractionResponseType`, `SendFollowupJob(string $applicationId, string $token, string $content)`.

- [ ] **Step 1: Write the failing auth test**

`tests/Feature/Discord/GatewayIngressAuthTest.php`:
```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['services.discord.gateway_bridge_secret' => 'test-secret']));

it('401s without the gateway secret', function () {
    $this->postJson('/internal/discord/gateway', ['type' => 'noop', 'data' => []])
        ->assertUnauthorized();
});

it('401s with a wrong gateway secret', function () {
    $this->postJson('/internal/discord/gateway', ['type' => 'noop', 'data' => []], ['X-Gateway-Secret' => 'nope'])
        ->assertUnauthorized();
});

it('accepts a correct gateway secret and ignores unknown event types', function () {
    $this->postJson('/internal/discord/gateway', ['type' => 'noop', 'data' => []], ['X-Gateway-Secret' => 'test-secret'])
        ->assertNoContent();
});
```

- [ ] **Step 2: Run it — expect FAIL (route/middleware missing)**

Run: `./vendor/bin/pest --filter=GatewayIngressAuth`
Expected: FAIL (404 / route not defined).

- [ ] **Step 3: Add config, middleware, controller, route, alias**

`config/services.php` — inside the `'discord' => [ … ]` array, after `'application_id' => env('DISCORD_APPLICATION_ID'),`:
```php
        'gateway_bridge_secret' => env('DISCORD_GATEWAY_BRIDGE_SECRET'),
```

`app/Modules/Discord/Http/Middleware/VerifyGatewaySecret.php`:
```php
<?php

namespace App\Modules\Discord\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal gateway ingress: the discord.js sidecar authenticates
 * with a shared secret over the compose network. Replaces the (now retired)
 * Ed25519 signature check — that verified *Discord*; this verifies *our
 * sidecar → our app*.
 */
class VerifyGatewaySecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.discord.gateway_bridge_secret');
        $provided = $request->header('X-Gateway-Secret');

        if (! is_string($expected) || $expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
```

`app/Modules/Discord/Http/GatewayIngressController.php`:
```php
<?php

namespace App\Modules\Discord\Http;

use App\Modules\Discord\Interactions\CommandRouter;
use App\Modules\Discord\Interactions\InteractionResponseType;
use App\Modules\Discord\Jobs\SendFollowupJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single ingress for gateway events forwarded by the discord.js sidecar
 * (docker/discord-gateway). The sidecar is pure transport; every decision is
 * made here in PHP. Routes by the envelope's `type`; the sidecar has already
 * `deferReply()`d interactions, so command content is delivered as a
 * follow-up (see SendFollowupJob) rather than an immediate response.
 */
class GatewayIngressController
{
    public function __invoke(Request $request): Response
    {
        $type = $request->string('type')->toString();
        /** @var array<string, mixed> $data */
        $data = (array) $request->input('data', []);

        match ($type) {
            'interaction' => $this->handleInteraction($data),
            default => Log::info('discord.gateway.ignored', ['type' => $type]),
        };

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleInteraction(array $payload): void
    {
        $response = CommandRouter::dispatch($payload);

        // The sidecar already sent the deferred acknowledgement, so an
        // immediate (type 4) response becomes a follow-up edit. A deferred
        // (type 5) handler has already queued its own SendFollowupJob.
        if (($response['type'] ?? null) !== InteractionResponseType::ChannelMessageWithSource->value) {
            return;
        }

        $content = $response['data']['content'] ?? null;
        $applicationId = $payload['application_id'] ?? null;
        $token = $payload['token'] ?? null;

        if (is_string($content) && $content !== '' && is_string($applicationId) && is_string($token)) {
            Bus::dispatch(new SendFollowupJob($applicationId, $token, $content));
        }
    }
}
```

`bootstrap/app.php` — add to the `$middleware->alias([...])` array:
```php
            'discord.gateway' => \App\Modules\Discord\Http\Middleware\VerifyGatewaySecret::class,
```
and add the ingress path to the CSRF exemption (it is called by the sidecar, no session/token):
```php
        $middleware->validateCsrfTokens(except: ['api/discord/interactions', 'internal/discord/gateway', 'api/telemetry/cs2/*']);
```

`routes/web.php` — add near the other discord routes (top `use`: `use App\Modules\Discord\Http\GatewayIngressController;`):
```php
Route::post('internal/discord/gateway', GatewayIngressController::class)
    ->middleware('discord.gateway')
    ->name('discord.gateway.ingress');
```

- [ ] **Step 4: Run the auth test — expect PASS**

Run: `./vendor/bin/pest --filter=GatewayIngressAuth`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the failing interaction test**

`tests/Feature/Discord/GatewayInteractionTest.php`:
```php
<?php

use App\Modules\Discord\Jobs\SendFollowupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.discord.gateway_bridge_secret' => 'test-secret',
        'services.discord.application_id' => 'app-123',
    ]);
});

function postInteraction(array $interaction): \Illuminate\Testing\TestResponse
{
    return test()->postJson(
        '/internal/discord/gateway',
        ['type' => 'interaction', 'data' => $interaction],
        ['X-Gateway-Secret' => 'test-secret'],
    );
}

it('delivers an immediate command response as a follow-up job', function () {
    Bus::fake();

    postInteraction([
        'type' => 2,
        'application_id' => 'app-123',
        'token' => 'interaction-token',
        'member' => ['user' => ['id' => '900']],
        'data' => ['name' => 'help', 'options' => []],
    ])->assertNoContent();

    Bus::assertDispatched(
        SendFollowupJob::class,
        fn (SendFollowupJob $job) => $job->applicationId === 'app-123'
            && $job->token === 'interaction-token'
            && $job->content !== '',
    );
});

it('does not double-dispatch for a deferred (type 5) command', function () {
    Bus::fake();

    // /tournament bracket is a deferred handler that queues its own follow-up.
    postInteraction([
        'type' => 2,
        'application_id' => 'app-123',
        'token' => 'interaction-token',
        'member' => ['user' => ['id' => '900']],
        'data' => ['name' => 'tournament', 'options' => [
            ['name' => 'bracket', 'type' => 1, 'options' => [
                ['name' => 'id', 'type' => 3, 'value' => '999999'],
            ]],
        ]],
    ])->assertNoContent();

    // Exactly one follow-up (the handler's own), not a second from the ingress.
    Bus::assertDispatchedTimes(SendFollowupJob::class, 1);
});
```

- [ ] **Step 6: Run it — expect PASS** (the controller from Step 3 already implements this)

Run: `./vendor/bin/pest --filter=GatewayInteraction`
Expected: PASS (2 tests). If the deferred test dispatches 2, verify the ingress only wraps type-4 responses (it must not touch type-5).

- [ ] **Step 7: composer check + commit**

Run: `composer check`
```bash
git add app/Modules/Discord/Http config/services.php bootstrap/app.php routes/web.php tests/Feature/Discord/GatewayIngressAuthTest.php tests/Feature/Discord/GatewayInteractionTest.php
git commit -m "feat(discord): internal gateway ingress with secret auth and interaction handling"
```

---

### Task 2: Retire the HTTP interactions endpoint

**Files:**
- Delete: `app/Modules/Discord/Http/InteractionsController.php`, `app/Modules/Discord/Http/Middleware/VerifyDiscordSignature.php`
- Delete: `tests/Feature/Discord/InteractionsPingTest.php`, `tests/Feature/Discord/InteractionsSignatureTest.php`
- Modify: `routes/web.php` (remove the interactions route + its comment), `bootstrap/app.php` (remove `discord.signature` alias + import; drop `api/discord/interactions` from CSRF exempt)
- Modify: `tests/Feature/Discord/SlashCommandTest.php` (repoint from the HTTP route to the ingress)

**Interfaces:**
- Consumes: the ingress from Task 1 (`postInteraction` helper pattern).

- [ ] **Step 1: Repoint SlashCommandTest at the ingress**

`SlashCommandTest.php` currently posts signed requests to `route('discord.interactions')`. Replace its request helper so each command case posts `{type:'interaction', data:<payload>}` to `/internal/discord/gateway` with `['X-Gateway-Secret' => 'test-secret']` (set `config(['services.discord.gateway_bridge_secret' => 'test-secret'])` in `beforeEach`), and assert on the resulting `SendFollowupJob` content (via `Bus::fake()` + `Bus::assertDispatched`) instead of on the synchronous JSON body. Keep every command-behaviour assertion; only the transport changes. Concretely, replace the old signed-post helper with:
```php
function dispatchCommand(array $data): void
{
    test()->postJson(
        '/internal/discord/gateway',
        ['type' => 'interaction', 'data' => $data],
        ['X-Gateway-Secret' => 'test-secret'],
    )->assertNoContent();
}
```
and read the produced content from the dispatched `SendFollowupJob` (immediate commands) or assert the deferred handler's own job (deferred commands).

- [ ] **Step 2: Run SlashCommandTest — expect PASS on the new path**

Run: `./vendor/bin/pest --filter=SlashCommand`
Expected: PASS.

- [ ] **Step 3: Delete the HTTP endpoint + its transport-only tests**

```bash
git rm app/Modules/Discord/Http/InteractionsController.php \
       app/Modules/Discord/Http/Middleware/VerifyDiscordSignature.php \
       tests/Feature/Discord/InteractionsPingTest.php \
       tests/Feature/Discord/InteractionsSignatureTest.php
```

`routes/web.php` — remove the `use App\Modules\Discord\Http\InteractionsController;` import, the `Route::post('api/discord/interactions', …)` block, and the now-stale comment above it.

`bootstrap/app.php` — remove `use App\Modules\Discord\Http\Middleware\VerifyDiscordSignature;`, remove the `'discord.signature' => VerifyDiscordSignature::class,` alias line, and drop `'api/discord/interactions'` from the `validateCsrfTokens(except: [...])` list (leaving `'internal/discord/gateway'` and `'api/telemetry/cs2/*'`).

- [ ] **Step 4: Verify the old route is gone**

Add to `tests/Feature/Discord/GatewayIngressAuthTest.php`:
```php
it('no longer exposes the retired HTTP interactions endpoint', function () {
    $this->postJson('/api/discord/interactions', ['type' => 1])->assertNotFound();
});
```

- [ ] **Step 5: composer check + commit**

Run: `composer check`
```bash
git add -A
git commit -m "refactor(discord): retire the Ed25519 HTTP interactions endpoint in favour of the gateway"
```

---

### Task 3: Voice-presence read-model (migration + model)

**Files:**
- Create: `database/migrations/2026_07_21_150000_create_discord_voice_states_table.php`
- Create: `app/Modules/Discord/Models/DiscordVoiceState.php`
- Test: `tests/Feature/Discord/VoicePresenceTest.php` (schema portion)

**Interfaces:**
- Produces: table `discord_voice_states` (`discord_user_id` string unique, `channel_id` string, `channel_name` string nullable, `user_id` FK→users nullable, timestamps). Model `DiscordVoiceState` with `$fillable = ['discord_user_id','channel_id','channel_name','user_id']`.

- [ ] **Step 1: Write the failing model test**

`tests/Feature/Discord/VoicePresenceTest.php`:
```php
<?php

use App\Modules\Discord\Models\DiscordVoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a voice state row', function () {
    $row = DiscordVoiceState::create([
        'discord_user_id' => '900',
        'channel_id' => 'chan-1',
        'channel_name' => 'Turnier 1',
        'user_id' => null,
    ]);

    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->exists())->toBeTrue()
        ->and($row->channel_name)->toBe('Turnier 1');
});
```

- [ ] **Step 2: Run it — expect FAIL (no table/model)**

Run: `./vendor/bin/pest --filter="stores a voice state row"`
Expected: FAIL.

- [ ] **Step 3: Migration + model**

`database/migrations/2026_07_21_150000_create_discord_voice_states_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_voice_states', function (Blueprint $table) {
            $table->id();
            $table->string('discord_user_id')->unique();
            $table->string('channel_id');
            $table->string('channel_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_voice_states');
    }
};
```

`app/Modules/Discord/Models/DiscordVoiceState.php`:
```php
<?php

namespace App\Modules\Discord\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single "this Discord user is currently in this voice channel" row —
 * the read-model behind {@see VoicePresenceProjection}. Guild-wide (Discord
 * voice is not scoped to a LANoMAT event). `user_id` links to the mapped
 * LANoMAT user when the Discord account is known, else null.
 *
 * @property string $discord_user_id
 * @property string $channel_id
 * @property string|null $channel_name
 * @property int|null $user_id
 */
class DiscordVoiceState extends Model
{
    protected $fillable = ['discord_user_id', 'channel_id', 'channel_name', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Run it — expect PASS**

Run: `./vendor/bin/pest --filter="stores a voice state row"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_21_150000_create_discord_voice_states_table.php app/Modules/Discord/Models/DiscordVoiceState.php tests/Feature/Discord/VoicePresenceTest.php
git commit -m "feat(discord): discord_voice_states read-model table and model"
```

---

### Task 4: Voice-state ingress branch, projection, and broadcast

**Files:**
- Create: `app/Modules/Discord/Support/HandleVoiceState.php`
- Create: `app/Modules/Discord/Support/VoicePresenceProjection.php`
- Create: `app/Modules/Discord/Events/DiscordVoicePresenceUpdated.php`
- Modify: `app/Modules/Discord/Http/GatewayIngressController.php` (add `'voice_state'` branch)
- Modify: `routes/channels.php` (document the public `discord-voice` channel)
- Test: `tests/Feature/Discord/VoicePresenceTest.php` (append)

**Interfaces:**
- Consumes: `DiscordVoiceState`, mapped-user lookup `User::where('discord_id', …)`.
- Produces: `HandleVoiceState::handle(array $data): void` (keys `guild_id`,`user_id`,`channel_id` nullable,`channel_name` nullable); `VoicePresenceProjection::current(): array<int, array{channel: string, count: int, names: list<string>}>`; `DiscordVoicePresenceUpdated` broadcast on `discord-voice` as `voice.updated`.

- [ ] **Step 1: Write the failing behaviour tests** (append to `VoicePresenceTest.php`)

```php
use App\Models\User;
use App\Modules\Discord\Events\DiscordVoicePresenceUpdated;
use App\Modules\Discord\Support\HandleVoiceState;
use App\Modules\Discord\Support\VoicePresenceProjection;
use Illuminate\Support\Facades\Event;

it('upserts on join and deletes on leave', function () {
    $handler = app(HandleVoiceState::class);

    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1']);
    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->value('channel_id'))->toBe('c1');

    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c2', 'channel_name' => 'Turnier 2']);
    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->value('channel_id'))->toBe('c2');

    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => null, 'channel_name' => null]);
    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->exists())->toBeFalse();
});

it('projects No-PII occupancy: mapped names only, unmapped counted but unnamed', function () {
    $mapped = User::factory()->create(['discord_id' => '900', 'name' => 'Alice']);
    $handler = app(HandleVoiceState::class);
    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1']);
    $handler->handle(['guild_id' => 'g', 'user_id' => '901', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1']); // unmapped

    $projection = VoicePresenceProjection::current();

    expect($projection)->toHaveCount(1)
        ->and($projection[0]['channel'])->toBe('Turnier 1')
        ->and($projection[0]['count'])->toBe(2)
        ->and($projection[0]['names'])->toBe(['Alice']);
});

it('broadcasts an empty voice-presence update from the ingress', function () {
    Event::fake([DiscordVoicePresenceUpdated::class]);
    config(['services.discord.gateway_bridge_secret' => 'test-secret']);

    $this->postJson('/internal/discord/gateway', [
        'type' => 'voice_state',
        'data' => ['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1'],
    ], ['X-Gateway-Secret' => 'test-secret'])->assertNoContent();

    Event::assertDispatched(DiscordVoicePresenceUpdated::class);
    expect((new DiscordVoicePresenceUpdated)->broadcastWith())->toBe([]);
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `./vendor/bin/pest --filter=VoicePresence`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement handler, projection, event, ingress branch**

`app/Modules/Discord/Events/DiscordVoicePresenceUpdated.php`:
```php
<?php

namespace App\Modules\Discord\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever Discord voice occupancy changes, so a consumer can
 * partial-reload {@see VoicePresenceProjection}. Empty payload (No-PII); the
 * public `discord-voice` channel needs no auth callback (mirrors
 * `PresenceUpdated`). Guild-wide, so not the per-event `event.{id}` channel.
 */
class DiscordVoicePresenceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function broadcastOn(): Channel
    {
        return new Channel('discord-voice');
    }

    public function broadcastAs(): string
    {
        return 'voice.updated';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
```

`app/Modules/Discord/Support/HandleVoiceState.php`:
```php
<?php

namespace App\Modules\Discord\Support;

use App\Models\User;
use App\Modules\Discord\Events\DiscordVoicePresenceUpdated;
use App\Modules\Discord\Models\DiscordVoiceState;

/**
 * Applies a forwarded VOICE_STATE_UPDATE to the read-model: null channel =
 * left (delete), otherwise join/move (upsert), then broadcasts the change.
 */
class HandleVoiceState
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): void
    {
        $discordUserId = $data['user_id'] ?? null;

        if (! is_string($discordUserId)) {
            return;
        }

        $channelId = $data['channel_id'] ?? null;

        if (! is_string($channelId)) {
            DiscordVoiceState::query()->where('discord_user_id', $discordUserId)->delete();
        } else {
            $userId = User::query()->where('discord_id', $discordUserId)->value('id');

            DiscordVoiceState::query()->updateOrCreate(
                ['discord_user_id' => $discordUserId],
                [
                    'channel_id' => $channelId,
                    'channel_name' => is_string($data['channel_name'] ?? null) ? $data['channel_name'] : null,
                    'user_id' => $userId,
                ],
            );
        }

        DiscordVoicePresenceUpdated::dispatch();
    }
}
```

`app/Modules/Discord/Support/VoicePresenceProjection.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordVoiceState;

/**
 * Pure, No-PII projection of current Discord voice occupancy: per channel a
 * head count and the display names of *mapped* LANoMAT users only (unmapped
 * Discord users are counted, never named). Mirrors
 * {@see \App\Modules\Presence\Support\PresenceProjection} discipline; one
 * bounded query.
 */
final class VoicePresenceProjection
{
    /**
     * @return list<array{channel: string, count: int, names: list<string>}>
     */
    public static function current(): array
    {
        $rows = DiscordVoiceState::query()->with('user')->get();

        return $rows
            ->groupBy(fn (DiscordVoiceState $s): string => $s->channel_name ?? $s->channel_id)
            ->map(fn ($group, $channel): array => [
                'channel' => (string) $channel,
                'count' => $group->count(),
                'names' => $group
                    ->map(fn (DiscordVoiceState $s): ?string => $s->user?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
```

`app/Modules/Discord/Http/GatewayIngressController.php` — add to the `match` in `__invoke`, and import `use App\Modules\Discord\Support\HandleVoiceState;`:
```php
            'voice_state' => app(HandleVoiceState::class)->handle($data),
```

`routes/channels.php` — append a documentation comment (no callback needed; public channel, mirrors `tournament.{id}`):
```php
// Discord voice occupancy (`DiscordVoicePresenceUpdated` -> 'voice.updated')
// broadcasts on the public `discord-voice` channel — no authorization
// callback (payload is empty, consumers pull VoicePresenceProjection).
```

- [ ] **Step 4: Run — expect PASS**

Run: `./vendor/bin/pest --filter=VoicePresence`
Expected: PASS.

- [ ] **Step 5: composer check + commit**

Run: `composer check`
```bash
git add app/Modules/Discord routes/channels.php tests/Feature/Discord/VoicePresenceTest.php
git commit -m "feat(discord): voice-presence projection, ingress branch, and broadcast"
```

---

### Task 5: Voice-presence read endpoint

**Files:**
- Create: `app/Modules/Discord/Http/VoicePresenceController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Discord/VoicePresenceEndpointTest.php`

**Interfaces:**
- Consumes: `VoicePresenceProjection::current()`.
- Produces: `GET /discord/voice` (name `discord.voice`) → JSON `{ channels: [...] }`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Discord/VoicePresenceEndpointTest.php`:
```php
<?php

use App\Modules\Discord\Models\DiscordVoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the current voice occupancy as JSON', function () {
    DiscordVoiceState::create(['discord_user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1', 'user_id' => null]);

    $this->getJson('/discord/voice')
        ->assertOk()
        ->assertJsonPath('channels.0.channel', 'Turnier 1')
        ->assertJsonPath('channels.0.count', 1);
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=VoicePresenceEndpoint`)

- [ ] **Step 3: Controller + route**

`app/Modules/Discord/Http/VoicePresenceController.php`:
```php
<?php

namespace App\Modules\Discord\Http;

use App\Modules\Discord\Support\VoicePresenceProjection;
use Illuminate\Http\JsonResponse;

/**
 * Public read of current Discord voice occupancy (No-PII, mapped names only).
 * Public like the other participant read surfaces; the projection itself is
 * the privacy boundary.
 */
class VoicePresenceController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['channels' => VoicePresenceProjection::current()]);
    }
}
```

`routes/web.php` — add (top `use App\Modules\Discord\Http\VoicePresenceController;`):
```php
Route::get('discord/voice', VoicePresenceController::class)->name('discord.voice');
```

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=VoicePresenceEndpoint`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Discord/Http/VoicePresenceController.php routes/web.php tests/Feature/Discord/VoicePresenceEndpointTest.php
git commit -m "feat(discord): GET /discord/voice occupancy endpoint"
```

---

### Task 6: Member + message/reaction surface events

**Files:**
- Create: `app/Modules/Discord/Events/DiscordGuildMemberJoined.php`, `DiscordGuildMemberLeft.php`, `DiscordMessageCreated.php`, `DiscordMessageReactionChanged.php`
- Create: `app/Modules/Discord/Listeners/LogGatewayEvent.php`
- Modify: `app/Modules/Discord/Http/GatewayIngressController.php` (add `member_add`,`member_remove`,`message_create`,`reaction` branches)
- Test: `tests/Feature/Discord/GatewaySurfaceEventsTest.php`

**Interfaces:**
- Produces: four `Dispatchable` events (plain, non-broadcast):
  - `DiscordGuildMemberJoined(string $discordUserId)`
  - `DiscordGuildMemberLeft(string $discordUserId)`
  - `DiscordMessageCreated(string $channelId, string $authorId, string $messageId)`
  - `DiscordMessageReactionChanged(string $messageId, string $channelId, string $userId, string $emoji, bool $added)`
- `LogGatewayEvent` is a single listener registered for all four (logs at info).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Discord/GatewaySurfaceEventsTest.php`:
```php
<?php

use App\Modules\Discord\Events\DiscordGuildMemberJoined;
use App\Modules\Discord\Events\DiscordGuildMemberLeft;
use App\Modules\Discord\Events\DiscordMessageCreated;
use App\Modules\Discord\Events\DiscordMessageReactionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['services.discord.gateway_bridge_secret' => 'test-secret']));

function postEvent(string $type, array $data): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/internal/discord/gateway', ['type' => $type, 'data' => $data], ['X-Gateway-Secret' => 'test-secret']);
}

it('dispatches typed events for surfaced gateway events', function () {
    Event::fake([
        DiscordGuildMemberJoined::class, DiscordGuildMemberLeft::class,
        DiscordMessageCreated::class, DiscordMessageReactionChanged::class,
    ]);

    postEvent('member_add', ['guild_id' => 'g', 'user_id' => '900'])->assertNoContent();
    postEvent('member_remove', ['guild_id' => 'g', 'user_id' => '900'])->assertNoContent();
    postEvent('message_create', ['channel_id' => 'c', 'author_id' => '900', 'message_id' => 'm'])->assertNoContent();
    postEvent('reaction', ['message_id' => 'm', 'channel_id' => 'c', 'user_id' => '900', 'emoji' => '✅', 'added' => true])->assertNoContent();

    Event::assertDispatched(DiscordGuildMemberJoined::class, fn ($e) => $e->discordUserId === '900');
    Event::assertDispatched(DiscordGuildMemberLeft::class, fn ($e) => $e->discordUserId === '900');
    Event::assertDispatched(DiscordMessageCreated::class, fn ($e) => $e->messageId === 'm');
    Event::assertDispatched(DiscordMessageReactionChanged::class, fn ($e) => $e->added === true && $e->emoji === '✅');
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=GatewaySurfaceEvents`)

- [ ] **Step 3: Implement events, listener, ingress branches**

Each event (example `DiscordMessageReactionChanged.php`; the others follow the same `Dispatchable` shape with the constructor args from the Interfaces block):
```php
<?php

namespace App\Modules\Discord\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reaction was added/removed on a guild message. Surface-only this phase:
 * dispatched onto the event bus for future listeners (e.g. reaction-to-
 * register), logged by {@see \App\Modules\Discord\Listeners\LogGatewayEvent}.
 */
class DiscordMessageReactionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $messageId,
        public readonly string $channelId,
        public readonly string $userId,
        public readonly string $emoji,
        public readonly bool $added,
    ) {}
}
```
`DiscordGuildMemberJoined`/`DiscordGuildMemberLeft`: `public readonly string $discordUserId`.
`DiscordMessageCreated`: `public readonly string $channelId, string $authorId, string $messageId`.

`app/Modules/Discord/Listeners/LogGatewayEvent.php`:
```php
<?php

namespace App\Modules\Discord\Listeners;

use Illuminate\Support\Facades\Log;

/**
 * Records surfaced gateway events (member join/leave, message, reaction) at
 * info level — the concrete extension point until product behaviour is
 * specified. Registered for all four events via auto-discovery (single
 * `handle` accepting the common interface would need a marker; instead this
 * exposes one method per event so Laravel event discovery wires it up).
 */
class LogGatewayEvent
{
    public function handle(object $event): void
    {
        Log::info('discord.gateway.event', ['event' => $event::class] + get_object_vars($event));
    }
}
```
Register the listener explicitly in `bootstrap/app.php`'s provider or an `EventServiceProvider` map (follow the repo's existing listener-registration pattern — the Discord module already registers listeners like `CreateMatchChannelOnReady`; add `LogGatewayEvent` for the four events the same way). If the repo relies on auto-discovery, a single `handle(object $event)` is not auto-discovered by type; instead register the mapping explicitly:
```php
Event::listen(DiscordGuildMemberJoined::class, [LogGatewayEvent::class, 'handle']);
Event::listen(DiscordGuildMemberLeft::class, [LogGatewayEvent::class, 'handle']);
Event::listen(DiscordMessageCreated::class, [LogGatewayEvent::class, 'handle']);
Event::listen(DiscordMessageReactionChanged::class, [LogGatewayEvent::class, 'handle']);
```
(Place these where the module's other `Event::listen`/subscriber registrations live — check `app/Providers/` and the Discord module for the established pattern before adding.)

`GatewayIngressController` — extend the `match` (imports for the four events):
```php
            'member_add' => DiscordGuildMemberJoined::dispatch((string) ($data['user_id'] ?? '')),
            'member_remove' => DiscordGuildMemberLeft::dispatch((string) ($data['user_id'] ?? '')),
            'message_create' => DiscordMessageCreated::dispatch((string) ($data['channel_id'] ?? ''), (string) ($data['author_id'] ?? ''), (string) ($data['message_id'] ?? '')),
            'reaction' => DiscordMessageReactionChanged::dispatch((string) ($data['message_id'] ?? ''), (string) ($data['channel_id'] ?? ''), (string) ($data['user_id'] ?? ''), (string) ($data['emoji'] ?? ''), (bool) ($data['added'] ?? false)),
```

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=GatewaySurfaceEvents`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Discord bootstrap/app.php tests/Feature/Discord/GatewaySurfaceEventsTest.php
git commit -m "feat(discord): surface member/message/reaction gateway events as typed events"
```

---

### Task 7: The discord.js sidecar + compose service + env

**Files:**
- Create: `docker/discord-gateway/bot.mjs`, `package.json`, `Dockerfile`, `README.md`
- Modify: `compose.yml`, `.env.example`

**Interfaces:**
- Consumes: the ingress `POST internal/discord/gateway` with `X-Gateway-Secret` and body `{type, data}` (Tasks 1/4/6).
- No PHP test (sidecar, like `docker/mumble-admin`): verified operationally in Step 5.

- [ ] **Step 1: `docker/discord-gateway/package.json`**
```json
{
  "name": "lanomat-discord-gateway",
  "private": true,
  "type": "module",
  "dependencies": {
    "discord.js": "14.16.3"
  }
}
```
(Pin to the current stable v14 at implementation time; verify against npm.)

- [ ] **Step 2: `docker/discord-gateway/bot.mjs`**
```js
// Thin discord.js gateway sidecar for LANoMAT (spec:
// docs/superpowers/specs/2026-07-21-discord-gateway-bot-design.md).
// Pure transport: holds the connection, sets presence, forwards every event
// to the Laravel ingress. No domain logic, no DB. discord.js handles
// heartbeat/resume/reconnect/rate-limits (Discord's own recommendation).
import { Client, Events, GatewayIntentBits, ActivityType } from 'discord.js';

const {
  DISCORD_BOT_TOKEN,
  DISCORD_GATEWAY_INGRESS_URL = 'http://app/internal/discord/gateway',
  DISCORD_GATEWAY_BRIDGE_SECRET,
  DISCORD_PRESENCE_STATUS = 'online',
  DISCORD_PRESENCE_ACTIVITY_TYPE = 'Watching',
  DISCORD_PRESENCE_ACTIVITY_NAME = 'LANoMAT',
} = process.env;

if (!DISCORD_BOT_TOKEN || !DISCORD_GATEWAY_BRIDGE_SECRET) {
  console.error('discord-gateway: DISCORD_BOT_TOKEN and DISCORD_GATEWAY_BRIDGE_SECRET are required');
  process.exit(1);
}

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildVoiceStates,
    GatewayIntentBits.GuildMembers,      // privileged — enable in the portal
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.GuildMessageReactions,
  ],
  presence: {
    status: DISCORD_PRESENCE_STATUS,
    activities: [{ name: DISCORD_PRESENCE_ACTIVITY_NAME, type: ActivityType[DISCORD_PRESENCE_ACTIVITY_TYPE] ?? ActivityType.Watching }],
  },
});

async function postToIngress(type, data) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const res = await fetch(DISCORD_GATEWAY_INGRESS_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Gateway-Secret': DISCORD_GATEWAY_BRIDGE_SECRET },
        body: JSON.stringify({ type, data }),
        signal: AbortSignal.timeout(5000),
      });
      if (res.ok) return;
      console.error(`discord-gateway: ingress ${type} -> HTTP ${res.status}`);
    } catch (err) {
      console.error(`discord-gateway: ingress ${type} attempt ${attempt} failed: ${err}`);
    }
    await new Promise((r) => setTimeout(r, attempt * 500));
  }
  console.error(`discord-gateway: dropped ${type} after retries`);
}

client.once(Events.ClientReady, (c) => console.log(`discord-gateway: logged in as ${c.user.tag}`));

client.on(Events.InteractionCreate, async (interaction) => {
  if (!interaction.isChatInputCommand()) return;
  try {
    await interaction.deferReply();
    await postToIngress('interaction', {
      type: 2,
      token: interaction.token,
      application_id: interaction.applicationId,
      member: { user: { id: interaction.user.id } },
      user: { id: interaction.user.id },
      data: { name: interaction.commandName, options: interaction.options.data },
    });
  } catch (err) {
    console.error(`discord-gateway: interaction handling failed: ${err}`);
  }
});

client.on(Events.VoiceStateUpdate, (_old, ns) =>
  postToIngress('voice_state', { guild_id: ns.guild.id, user_id: ns.id, channel_id: ns.channelId, channel_name: ns.channel?.name ?? null }));
client.on(Events.GuildMemberAdd, (m) => postToIngress('member_add', { guild_id: m.guild.id, user_id: m.id }));
client.on(Events.GuildMemberRemove, (m) => postToIngress('member_remove', { guild_id: m.guild.id, user_id: m.id }));
client.on(Events.MessageCreate, (msg) => { if (msg.author.bot) return; postToIngress('message_create', { channel_id: msg.channelId, author_id: msg.author.id, message_id: msg.id }); });
client.on(Events.MessageReactionAdd, (r, u) => postToIngress('reaction', { message_id: r.message.id, channel_id: r.message.channelId, user_id: u.id, emoji: r.emoji.name, added: true }));
client.on(Events.MessageReactionRemove, (r, u) => postToIngress('reaction', { message_id: r.message.id, channel_id: r.message.channelId, user_id: u.id, emoji: r.emoji.name, added: false }));

client.login(DISCORD_BOT_TOKEN);
```

- [ ] **Step 3: `docker/discord-gateway/Dockerfile`**
```dockerfile
FROM node:22-alpine
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund
COPY bot.mjs ./
CMD ["node", "bot.mjs"]
```

- [ ] **Step 4: compose service + env**

`compose.yml` — add (profile prod, one instance only):
```yaml
  # Discord Gateway sidecar (spec 2026-07-21): the single persistent Gateway
  # connection (presence + inbound events), bridged to `app` over the compose
  # network. Pure transport; all logic is in the Laravel app. profiles:[prod]
  # so exactly one session per bot token exists.
  discord-gateway:
    build: ./docker/discord-gateway
    profiles: [prod]
    env_file: .env
    restart: unless-stopped
```

`.env.example` — append after `DISCORD_APPLICATION_ID=`:
```
DISCORD_GATEWAY_BRIDGE_SECRET=
DISCORD_GATEWAY_INGRESS_URL=http://app/internal/discord/gateway
DISCORD_PRESENCE_STATUS=online
DISCORD_PRESENCE_ACTIVITY_TYPE=Watching
DISCORD_PRESENCE_ACTIVITY_NAME=LANoMAT
```

`docker/discord-gateway/README.md` — short run book: purpose, the pure-transport boundary, required env, the **manual portal prerequisites** (enable Server Members Intent; clear the Interactions Endpoint URL so interactions arrive over the Gateway), and that it is verified operationally (not unit-tested), mirroring `docker/mumble-admin/README.md` / `docker/teamspeak-admin/README.md`.

- [ ] **Step 5: Operational verification**

Set `DISCORD_GATEWAY_BRIDGE_SECRET` in `.env`, build and start:
```bash
docker compose --profile prod up -d --build discord-gateway
docker compose logs discord-gateway | grep "logged in as"
```
Expected: `discord-gateway: logged in as <bot#tag>` (proves the Gateway connection = bot shows online). With the app running and the portal Interactions Endpoint URL cleared, invoke a slash command in the guild and confirm the follow-up appears; join a voice channel and confirm `GET /discord/voice` reflects it.

- [ ] **Step 6: Commit**

```bash
git add docker/discord-gateway compose.yml .env.example
git commit -m "feat(discord): discord.js gateway sidecar and compose service"
```

---

### Task 8: Docs — CLAUDE.md amendment + architecture.md

> **Finalization note for the executor:** this is a docs-only task. Do NOT merge, tag, or close anything — the controller finalizes the branch after the whole-branch review.

**Files:**
- Modify: `CLAUDE.md` (the "No bot process … No gateway connection" bullet under Architecture rules)
- Modify: `docs/architecture.md`

- [ ] **Step 1: Amend CLAUDE.md**

Replace the `**No bot process:**` bullet with:
```markdown
- **Discord Gateway via a thin sidecar:** Discord runs a persistent Gateway connection through a small discord.js sidecar (`docker/discord-gateway/`) for presence (online status) and inbound events (interactions, member join/leave, voice-state, message/reaction). The sidecar is **pure transport** — it forwards events to the Laravel app over an internal secret-authenticated endpoint (`internal/discord/gateway`, `VerifyGatewaySecret`); all domain logic stays in PHP. Slash-command interactions arrive over the Gateway (always-defer; the sidecar `deferReply()`s, PHP delivers the content as a follow-up) — the Ed25519 HTTP interactions endpoint has been retired. Outbound calls still go through the `DiscordClient` contract + `HttpDiscordClient`.
```

- [ ] **Step 2: Update docs/architecture.md**

Add the sidecar + ingress + `discord_voice_states` read-model to the module/data-model sketch; note the retirement of the HTTP interactions endpoint and the manual portal prerequisites (Server Members Intent; clear Interactions Endpoint URL).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/architecture.md
git commit -m "docs(discord): document the gateway sidecar architecture and retire the no-gateway rule"
```

---

## Self-Review

**1. Spec coverage:** §4 architecture → Tasks 1,4,6,7. §5 sidecar → Task 7. §6 interaction always-defer → Tasks 1,2. §7 voice presence (read-model/projection/broadcast/endpoint) → Tasks 3,4,5. §8 member/message/reaction typed events + intents → Tasks 6,7. §9 manual prerequisites → documented in Tasks 7,8 (not code). §11 security (secret, internal, hash_equals) → Task 1. §12 docs → Task 8. §13 testing → each task's Pest tests; sidecar operational in Task 7. §14 rollout ordering → spec (deploy concern, not code). §15 open decisions → resolved to defaults (voice = read endpoint only, guild-wide; retire HTTP; static presence). No gaps.

**2. Placeholder scan:** No TBD/TODO. The one "follow the repo's existing listener-registration pattern" in Task 6 is a concrete instruction to match an established pattern plus the exact `Event::listen` fallback code — not a placeholder.

**3. Type consistency:** `SendFollowupJob(applicationId, token, content)` matches the existing job. `CommandRouter::dispatch(array): array` and `InteractionResponseType::ChannelMessageWithSource` match the existing classes. Event constructor signatures in Task 6 match their `Event::assertDispatched` reads in the test. `HandleVoiceState::handle(array)` and `VoicePresenceProjection::current(): list<...>` match their tests and the `GET /discord/voice` JSON shape. `discord-voice` channel + `voice.updated` alias consistent between event and channels.php doc.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-21-discord-gateway-bot.md`. Two execution options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints.

Which approach?
