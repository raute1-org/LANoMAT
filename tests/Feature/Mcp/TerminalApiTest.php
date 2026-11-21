<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * The launcher fleet registry: `POST /api/v1/terminals/heartbeat` writes the
 * caller's own row, `GET /api/v1/terminals` is the orga monitor. Contract:
 * docs/infra/arcade-terminals.md.
 */
function launcherTerminalToken(User $user, string $name = 'Kabinett 1'): array
{
    $plain = McpCodes::bearerToken();

    $token = (new McpToken)->forceFill([
        'user_id' => $user->getKey(),
        'name' => $name,
        'token_hash' => McpCodes::hash($plain),
        'expires_at' => now()->addDays(90),
    ]);
    $token->save();

    return [$plain, $token];
}

function heartbeatPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kabinett 7',
        'os' => 'windows',
        'arch' => 'x86_64',
        'version' => '1.4.2',
        'state' => TerminalState::Idle->value,
        'current_game_slug' => null,
        'installed' => ['unreal-tournament-2004', 'quake-live'],
        'gamepad' => true,
    ], $overrides);
}

beforeEach(function (): void {
    cache()->store((string) config('cache.limiter'))->flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rejects a heartbeat without a bearer token', function () {
    $this->postJson('/api/v1/terminals/heartbeat', heartbeatPayload())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer');

    $this->withToken('lmcp_unknown')
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload())
        ->assertUnauthorized();
});

it('upserts the caller\'s terminal and answers with the contract fields', function () {
    [$plain, $token] = launcherTerminalToken(User::factory()->create());

    $response = $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload())
        ->assertOk()
        ->assertJsonPath('ok', true);

    $terminal = LauncherTerminal::query()->sole();

    $response->assertJsonPath('terminal_id', $terminal->id);
    expect($response->json('server_time'))->toBeString();

    expect($terminal->mcp_token_id)->toBe($token->id)
        ->and($terminal->name)->toBe('Kabinett 7')
        ->and($terminal->os)->toBe('windows')
        ->and($terminal->arch)->toBe('x86_64')
        ->and($terminal->version)->toBe('1.4.2')
        ->and($terminal->state)->toBe(TerminalState::Idle)
        ->and($terminal->installed)->toBe(['unreal-tournament-2004', 'quake-live'])
        ->and($terminal->gamepad)->toBeTrue()
        ->and($terminal->ip)->toBe('127.0.0.1')
        ->and($terminal->last_seen_at)->not->toBeNull();
});

it('updates the same row on the next heartbeat instead of creating one', function () {
    [$plain] = launcherTerminalToken(User::factory()->create());

    $this->withToken($plain)->postJson('/api/v1/terminals/heartbeat', heartbeatPayload())->assertOk();

    Carbon::setTestNow(now()->addSeconds(30));

    $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload([
            'name' => 'Kabinett 7',
            'state' => TerminalState::Playing->value,
            'current_game_slug' => 'quake-live',
            'installed' => ['quake-live'],
        ]))
        ->assertOk();

    $terminal = LauncherTerminal::query()->sole();

    expect($terminal->state)->toBe(TerminalState::Playing)
        ->and($terminal->current_game_slug)->toBe('quake-live')
        ->and($terminal->installed)->toBe(['quake-live'])
        ->and($terminal->last_seen_at?->getTimestamp())->toBe(now()->getTimestamp());
});

it('keeps one row per token, so two paired terminals never overwrite each other', function () {
    [$first] = launcherTerminalToken(User::factory()->create(), 'Kabinett 1');
    [$second] = launcherTerminalToken(User::factory()->create(), 'Kabinett 2');

    $this->withToken($first)->postJson('/api/v1/terminals/heartbeat', heartbeatPayload(['name' => 'Kabinett 1']))->assertOk();
    $this->withToken($second)->postJson('/api/v1/terminals/heartbeat', heartbeatPayload(['name' => 'Kabinett 2']))->assertOk();

    expect(LauncherTerminal::query()->count())->toBe(2)
        ->and(LauncherTerminal::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Kabinett 1', 'Kabinett 2']);
});

it('treats last_seen_at and ip as server facts, not client claims', function () {
    [$plain] = launcherTerminalToken(User::factory()->create());

    $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload([
            'last_seen_at' => now()->addDay()->toIso8601String(),
            'ip' => '203.0.113.99',
            'mcp_token_id' => 999,
        ]))
        ->assertOk();

    $terminal = LauncherTerminal::query()->sole();

    expect($terminal->last_seen_at?->isToday())->toBeTrue()
        ->and($terminal->ip)->toBe('127.0.0.1')
        ->and($terminal->mcp_token_id)->not->toBe(999);
});

it('defaults the reported state to online', function () {
    [$plain] = launcherTerminalToken(User::factory()->create());
    $payload = heartbeatPayload();
    unset($payload['state']);

    $this->withToken($plain)->postJson('/api/v1/terminals/heartbeat', $payload)->assertOk();

    expect(LauncherTerminal::query()->sole()->state)->toBe(TerminalState::Online);
});

it('normalises and deduplicates the installed slug list', function () {
    [$plain] = launcherTerminalToken(User::factory()->create());

    $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload([
            'installed' => ['UT2004', ' ut2004 ', 'quake-live', 'QUAKE-LIVE'],
        ]))
        ->assertOk();

    expect(LauncherTerminal::query()->sole()->installed)->toBe(['ut2004', 'quake-live']);
});

it('strips control characters and bounds the terminal name', function () {
    [$plain] = launcherTerminalToken(User::factory()->create());

    $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload([
            'name' => "Kabinett\n\t7    Links",
        ]))
        ->assertOk();

    $name = (string) LauncherTerminal::query()->sole()->name;

    expect($name)->toBe('Kabinett 7 Links')
        ->and(mb_strlen($name))->toBeLessThanOrEqual(60);
});

it('rejects an invalid heartbeat payload with field errors', function (string $field, mixed $value) {
    [$plain] = launcherTerminalToken(User::factory()->create());

    $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload([$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(LauncherTerminal::query()->count())->toBe(0);
})->with([
    'missing name' => ['name', ''],
    'unknown state' => ['state', 'exploding'],
    'bad os' => ['os', 'beos'],
    'bad arch' => ['arch', 'x86 64!'],
    'bad slug' => ['current_game_slug', '../etc/passwd'],
    'installed not a list' => ['installed', 'quake-live'],
    'too many installed' => ['installed', array_map(fn (int $i): string => 'game-'.$i, range(1, 501))],
    'gamepad not a boolean' => ['gamepad', 'maybe'],
]);

it('throttles the heartbeat per token', function () {
    [$plain] = launcherTerminalToken(User::factory()->create());

    for ($i = 0; $i < 30; $i++) {
        $this->withToken($plain)->postJson('/api/v1/terminals/heartbeat', heartbeatPayload())->assertOk();
    }

    $this->withToken($plain)
        ->postJson('/api/v1/terminals/heartbeat', heartbeatPayload())
        ->assertStatus(429);
});

it('rejects the fleet list without a bearer token', function () {
    $this->getJson('/api/v1/terminals')->assertUnauthorized();
});

it('forbids participants and helpers from the fleet list', function (Role $role) {
    [$plain] = launcherTerminalToken(User::factory()->create(['role' => $role]));

    $this->withToken($plain)->getJson('/api/v1/terminals')->assertForbidden();
})->with([Role::Participant, Role::Helper]);

it('lists the fleet for orga and admin', function (string $role) {
    $user = User::factory()->{$role}()->create();
    [$plain, $token] = launcherTerminalToken($user, 'Orga-Laptop');

    LauncherTerminal::factory()->pairedTo($token)->playing('quake-live')->withInstalled(['quake-live'])->create([
        'name' => 'Kabinett 7',
        'version' => '1.4.2',
        'gamepad' => true,
    ]);

    $this->withToken($plain)
        ->getJson('/api/v1/terminals')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Kabinett 7')
        ->assertJsonPath('data.0.state', 'playing')
        ->assertJsonPath('data.0.online', true)
        ->assertJsonPath('data.0.os', 'windows')
        ->assertJsonPath('data.0.arch', 'x86_64')
        ->assertJsonPath('data.0.version', '1.4.2')
        ->assertJsonPath('data.0.current_game_slug', 'quake-live')
        ->assertJsonPath('data.0.installed', ['quake-live'])
        ->assertJsonPath('data.0.installed_count', 1)
        ->assertJsonPath('data.0.gamepad', true)
        ->assertJsonPath('data.0.paired_user.name', $user->name)
        ->assertJsonPath('data.0.token_name', 'Orga-Laptop')
        ->assertJsonPath('heartbeat_seconds', LauncherTerminal::HEARTBEAT_SECONDS)
        ->assertJsonPath('offline_after_seconds', LauncherTerminal::OFFLINE_AFTER_SECONDS);
})->with(['orga', 'admin']);

it('derives offline from a stale heartbeat and lists online terminals first', function () {
    $orga = User::factory()->orga()->create();
    [$plain, $token] = launcherTerminalToken($orga);
    [, $secondToken] = launcherTerminalToken($orga, 'Zweites Kabinett');

    LauncherTerminal::factory()->pairedTo($token)->playing('quake-live')->stale()->create(['name' => 'Altes Kabinett']);
    LauncherTerminal::factory()->pairedTo($secondToken)->idle()->create(['name' => 'Frisches Kabinett']);

    $response = $this->withToken($plain)->getJson('/api/v1/terminals')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Frisches Kabinett', 'Altes Kabinett'])
        ->and($response->json('data.1.state'))->toBe('offline')
        ->and($response->json('data.1.online'))->toBeFalse()
        // The reported state stays honest even though the monitor shows offline.
        ->and($response->json('data.1.current_game_slug'))->toBe('quake-live');
});

it('does not leak user data beyond the paired user', function () {
    $orga = User::factory()->orga()->create([
        'name' => 'Orga Ada',
        'email' => 'ada@example.test',
        'discord_id' => '100000000000000001',
    ]);
    [$plain, $token] = launcherTerminalToken($orga, 'Kabinett-Token');

    LauncherTerminal::factory()->pairedTo($token)->create();

    $body = (string) $this->withToken($plain)->getJson('/api/v1/terminals')->assertOk()->getContent();

    expect($body)->toContain('Orga Ada')
        ->not->toContain('ada@example.test')
        ->not->toContain('100000000000000001')
        ->not->toContain('participant');
});
