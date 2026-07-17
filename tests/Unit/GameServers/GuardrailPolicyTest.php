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
    Config::set('services.pelican.max_running_servers', null);
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

it('throws when the node-wide running-server count is at max_running_servers, requester-independent', function () {
    Config::set('services.pelican.max_running_servers', 3);

    ServerLink::factory()->count(3)->create(['status' => ServerLinkStatus::Ready]);

    // No requester at all (the automatic path) — the global cap must still
    // fire, since it is the guardrail meant to bound exactly this path.
    expect(fn () => GuardrailPolicy::assertWithinLimits(null, [], null))
        ->toThrow(GameServerException::class);
});

it('passes when the node-wide running-server count is one under max_running_servers', function () {
    Config::set('services.pelican.max_running_servers', 3);

    ServerLink::factory()->count(2)->create(['status' => ServerLinkStatus::Ready]);

    GuardrailPolicy::assertWithinLimits(null, [], null);
})->throwsNoExceptions();

it('does not count Failed/Stopped links toward the global running-server cap', function () {
    Config::set('services.pelican.max_running_servers', 1);

    ServerLink::factory()->count(5)->create(['status' => ServerLinkStatus::Failed]);
    ServerLink::factory()->count(5)->create(['status' => ServerLinkStatus::Stopped]);

    GuardrailPolicy::assertWithinLimits(null, [], null);
})->throwsNoExceptions();

it('excludes the given excludingLinkId from the global running-server count', function () {
    Config::set('services.pelican.max_running_servers', 1);

    // The in-flight link the caller has already claimed (Provisioning) is
    // the only running link — excluding it must leave the count at 0, which
    // is strictly under the cap of 1, so this must NOT throw. This is the
    // exact boundary ProvisionMatchServerJob relies on: "at most N besides
    // the one currently being provisioned".
    $inFlight = ServerLink::factory()->create(['status' => ServerLinkStatus::Provisioning]);

    GuardrailPolicy::assertWithinLimits(null, [], null, excludingLinkId: $inFlight->id);
})->throwsNoExceptions();

it('throws when other running links are already at the cap even after excluding the in-flight one', function () {
    Config::set('services.pelican.max_running_servers', 1);

    $inFlight = ServerLink::factory()->create(['status' => ServerLinkStatus::Provisioning]);
    // One other running link besides the in-flight one — that already
    // saturates a cap of 1.
    ServerLink::factory()->create(['status' => ServerLinkStatus::Ready]);

    expect(fn () => GuardrailPolicy::assertWithinLimits(null, [], null, excludingLinkId: $inFlight->id))
        ->toThrow(GameServerException::class);
});

it('skips the global running-server cap entirely when max_running_servers is unset', function () {
    Config::set('services.pelican.max_running_servers', null);

    ServerLink::factory()->count(50)->create(['status' => ServerLinkStatus::Ready]);

    GuardrailPolicy::assertWithinLimits(null, [], null);
})->throwsNoExceptions();

it('carries a german translation key on the global running-server cap error', function () {
    Config::set('services.pelican.max_running_servers', 1);

    ServerLink::factory()->count(1)->create(['status' => ServerLinkStatus::Ready]);

    try {
        GuardrailPolicy::assertWithinLimits(null, [], null);
        test()->fail('Expected GameServerException to be thrown.');
    } catch (GameServerException $e) {
        expect(__($e->translationKey))->toBe('Es laufen bereits die maximal zulässige Anzahl an Spielservern auf diesem Knoten.');
    }
});

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
