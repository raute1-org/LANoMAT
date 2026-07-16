<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\GuardrailPolicy;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('services.pelican.max_ram_mb', 2048);
    Config::set('services.pelican.max_slots', 32);
    Config::set('services.pelican.max_servers_per_user', 2);
});

it('throws when the estimated RAM exceeds the per-instance cap', function () {
    $game = Game::factory()->create();
    $requester = User::factory()->create(['role' => Role::Helper]);

    // 256 base + 100 * 64 = 6656 MB, far over the 2048 MB cap.
    expect(fn () => GuardrailPolicy::assertWithinLimits($game, ['max_players' => 100], $requester))
        ->toThrow(GameServerException::class);
});

it('throws when configured slots exceed the per-instance cap', function () {
    $game = Game::factory()->create();
    $requester = User::factory()->create(['role' => Role::Helper]);

    // Raise the RAM cap out of the way so only the slots cap can trip.
    Config::set('services.pelican.max_ram_mb', 1_000_000);

    expect(fn () => GuardrailPolicy::assertWithinLimits($game, ['max_players' => 64], $requester))
        ->toThrow(GameServerException::class);
});

it('throws when the requester already has max_servers_per_user running servers', function () {
    $game = Game::factory()->create();
    $requester = User::factory()->create(['role' => Role::Helper]);

    ServerLink::factory()->count(2)->create([
        'requested_by' => $requester->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    expect(fn () => GuardrailPolicy::assertWithinLimits($game, ['max_players' => 10], $requester))
        ->toThrow(GameServerException::class);
});

it('does not count Failed/Stopped links toward the per-user cap', function () {
    $game = Game::factory()->create();
    $requester = User::factory()->create(['role' => Role::Helper]);

    ServerLink::factory()->count(5)->create([
        'requested_by' => $requester->id,
        'status' => ServerLinkStatus::Failed,
    ]);

    GuardrailPolicy::assertWithinLimits($game, ['max_players' => 10], $requester);
})->throwsNoExceptions();

it('skips the per-user cap entirely when no requester is given (automatic path)', function () {
    $game = Game::factory()->create();

    GuardrailPolicy::assertWithinLimits($game, ['max_players' => 10], null);
})->throwsNoExceptions();

it('passes when the config is within all limits', function () {
    $game = Game::factory()->create([
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);
    $requester = User::factory()->create(['role' => Role::Helper]);

    GuardrailPolicy::assertWithinLimits($game, $game->default_server_config->toArray(), $requester);
})->throwsNoExceptions();

it('carries a german translation key on the ram-cap error', function () {
    $game = Game::factory()->create();
    $requester = User::factory()->create(['role' => Role::Helper]);

    try {
        GuardrailPolicy::assertWithinLimits($game, ['max_players' => 100], $requester);
        test()->fail('Expected GameServerException to be thrown.');
    } catch (GameServerException $e) {
        expect(__($e->translationKey))->toBe('Der geschätzte Arbeitsspeicherbedarf überschreitet das Limit pro Server.');
    }
});
