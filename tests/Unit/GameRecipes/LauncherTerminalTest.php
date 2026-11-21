<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

function launcherTokenFor(User $user): McpToken
{
    return (new McpToken)->forceFill([
        'user_id' => $user->getKey(),
        'name' => 'Kabinett',
        'token_hash' => McpCodes::hash(McpCodes::bearerToken()),
        'expires_at' => now()->addDays(90),
    ]);
}

it('creates a terminal via the factory with typed attributes', function () {
    $terminal = LauncherTerminal::factory()->create(['name' => 'Kabinett 7']);

    expect($terminal)->toBeInstanceOf(LauncherTerminal::class)
        ->and($terminal->name)->toBe('Kabinett 7')
        ->and($terminal->state)->toBeInstanceOf(TerminalState::class)
        ->and($terminal->installed)->toBeArray()
        ->and($terminal->gamepad)->toBeBool()
        ->and($terminal->last_seen_at)->not->toBeNull();
});

it('keeps identity and system-managed fields out of mass assignment', function () {
    $terminal = new LauncherTerminal;

    expect($terminal->getFillable())
        ->toContain('name')
        ->toContain('state')
        ->toContain('current_game_slug')
        ->toContain('installed')
        ->toContain('gamepad')
        ->not->toContain('mcp_token_id')
        ->not->toContain('last_seen_at')
        ->not->toContain('ip');
});

it('reads a fresh heartbeat as online and a stale one as offline', function () {
    Carbon::setTestNow('2026-11-21 18:00:00');

    $fresh = LauncherTerminal::factory()->playing('quake-live')->create();
    $stale = LauncherTerminal::factory()->playing('quake-live')->stale()->create();

    expect($fresh->isOnline())->toBeTrue()
        ->and($fresh->derivedState())->toBe(TerminalState::Playing)
        ->and($stale->isOnline())->toBeFalse()
        ->and($stale->derivedState())->toBe(TerminalState::Offline);

    // Exactly the documented window: 90 s old is still online, 91 s is not.
    $edge = LauncherTerminal::factory()->create([
        'last_seen_at' => now()->subSeconds(LauncherTerminal::OFFLINE_AFTER_SECONDS),
    ]);
    $expired = LauncherTerminal::factory()->create([
        'last_seen_at' => now()->subSeconds(LauncherTerminal::OFFLINE_AFTER_SECONDS + 1),
    ]);

    expect($edge->derivedState())->not->toBe(TerminalState::Offline)
        ->and($expired->derivedState())->toBe(TerminalState::Offline);
});

it('keeps a reported offline state until the next heartbeat', function () {
    $terminal = LauncherTerminal::factory()->create([
        'state' => TerminalState::Offline,
        'last_seen_at' => now(),
    ]);

    expect($terminal->isOnline())->toBeTrue()
        ->and($terminal->derivedState())->toBe(TerminalState::Offline);
});

it('treats a terminal that was never seen as offline', function () {
    $terminal = LauncherTerminal::factory()->create(['last_seen_at' => null]);

    expect($terminal->isOnline())->toBeFalse()
        ->and($terminal->derivedState())->toBe(TerminalState::Offline);
});

it('filters non-string entries out of the installed list', function () {
    $terminal = LauncherTerminal::factory()->create([
        'installed' => ['quake-live', '', 42, null, 'ut2004'],
    ]);

    expect($terminal->installedSlugs())->toBe(['quake-live', 'ut2004']);
});

it('reaches the paired user through its token', function () {
    $user = User::factory()->create(['name' => 'Ada Orga']);
    $token = launcherTokenFor($user);
    $token->save();

    $terminal = LauncherTerminal::factory()->pairedTo($token)->create();

    expect($terminal->token?->is($token))->toBeTrue()
        ->and($terminal->user?->is($user))->toBeTrue()
        ->and($terminal->fresh()->user?->name)->toBe('Ada Orga');
});

it('has German labels and badge colours for the terminal state', function () {
    expect(TerminalState::Online->label())->toBe('Online')
        ->and(TerminalState::Playing->label())->toBe('Spielt')
        ->and(TerminalState::Idle->label())->toBe('Bereit')
        ->and(TerminalState::Offline->label())->toBe('Offline')
        ->and(TerminalState::Playing->color())->toBe('success')
        ->and(TerminalState::Offline->color())->toBe('gray');
});
