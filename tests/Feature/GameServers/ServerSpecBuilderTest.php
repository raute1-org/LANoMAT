<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\GuardrailPolicy;
use App\Modules\GameServers\Support\ServerSpecBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pelican.owner_user_id' => '3',
        'services.pelican.default_node_id' => '1',
        'services.pelican.default_disk_mb' => 5120,
        'services.pelican.default_cpu_percent' => 0,
    ]);
});

it('overlays typed config and explicit environment onto the egg defaults, never inventing variables', function () {
    $fake = fakePelican();
    $fake->eggs['4'] = new PelicanEgg('4', 'CS2', ['MAX_PLAYERS' => '12', 'SRCDS_MAP' => 'de_mirage', 'GAME_MODE' => '1'], ['MAX_PLAYERS']);
    $game = Game::factory()->create(['slug' => 'cs2']);
    $config = (new ServerConfig(maxPlayers: 10, map: 'de_dust2', password: 'secret', environment: ['GAME_MODE' => 2, 'VAC_ENABLED' => true]))->toArray();

    $spec = (new ServerSpecBuilder($fake))->build($game, '4', $config, 'srv');

    // SERVER_PASSWORD is not declared by this egg, so the password is not sent.
    expect($spec->environment)->toBe(['MAX_PLAYERS' => '10', 'SRCDS_MAP' => 'de_dust2', 'GAME_MODE' => '2', 'VAC_ENABLED' => '1'])
        ->and($spec->ownerUserId)->toBe('3')
        ->and($spec->nodeId)->toBe('1')
        ->and($spec->eggId)->toBe('4')
        ->and($spec->limits->disk)->toBe(5120)
        ->and($spec->limits->cpu)->toBe(0);
});

it('uses explicit memory, disk and cpu limits from the config', function () {
    $game = Game::factory()->create();
    $config = (new ServerConfig(maxPlayers: 10, memoryMb: 3072, diskMb: 60000, cpuPercent: 200))->toArray();

    $spec = (new ServerSpecBuilder(fakePelican()))->build($game, '4', $config, 'srv');

    expect($spec->limits->memory)->toBe(3072)
        ->and($spec->limits->disk)->toBe(60000)
        ->and($spec->limits->cpu)->toBe(200);
});

it('requires an owner user to be configured', function () {
    config(['services.pelican.owner_user_id' => null]);

    expect(fn () => (new ServerSpecBuilder(fakePelican()))->build(Game::factory()->create(), '4', [], 'srv'))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_not_configured');
});

it('lets the ram guardrail judge the memory limit that is actually sent', function () {
    config(['services.pelican.max_ram_mb' => 4096, 'services.pelican.max_running_servers' => null]);
    $game = Game::factory()->create();
    $config = (new ServerConfig(maxPlayers: 2, memoryMb: 8192))->toArray();

    expect(fn () => GuardrailPolicy::assertWithinLimits($game, $config, requester: null))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.ram_cap_exceeded');
});

it('round-trips the new typed config fields through toArray/fromArray', function () {
    $config = new ServerConfig(maxPlayers: 8, memoryMb: 2048, diskMb: 4096, cpuPercent: 150, environment: ['A' => 'b']);

    expect(ServerConfig::fromArray($config->toArray()))->toEqual($config);
});

it('places the server on the planned allocations and gives extra ports their own variable', function () {
    $fake = fakePelican();
    $fake->eggs['4'] = new PelicanEgg('4', 'CS2', ['SERVER_PORT_X' => '', 'TV_PORT' => '27020']);
    $game = Game::factory()->create();
    $config = (new ServerConfig(maxPlayers: 10, extraPorts: ['TV_PORT']))->toArray();
    $allocations = [new PelicanAllocation('11', '192.0.2.10', 27015), new PelicanAllocation('12', '192.0.2.10', 27016)];

    $spec = (new ServerSpecBuilder($fake))->build($game, '4', $config, 'srv', allocations: $allocations);

    expect($spec->allocationId)->toBe('11')
        ->and($spec->additionalAllocationIds)->toBe(['12'])
        ->and($spec->environment['TV_PORT'])->toBe('27016')
        ->and($spec->limits->allocations)->toBe(2);
});

it('names the server through the hostname variable the egg declares, unless the preset sets one', function () {
    $fake = fakePelican();
    $fake->eggs['4'] = new PelicanEgg('4', 'GMod', ['SERVER_HOSTNAME' => 'LANoMAT Server']);
    $game = Game::factory()->create();

    $named = (new ServerSpecBuilder($fake))->build($game, '4', [], 'srv', displayName: 'Sommer-LAN TTT');
    $fixed = (new ServerSpecBuilder($fake))->build($game, '4', (new ServerConfig(environment: ['SERVER_HOSTNAME' => 'Fixed']))->toArray(), 'srv', displayName: 'Ignored');

    expect($named->environment['SERVER_HOSTNAME'])->toBe('Sommer-LAN TTT')
        ->and($fixed->environment['SERVER_HOSTNAME'])->toBe('Fixed');
});

it('strips characters from a display name that could break the startup line', function () {
    $fake = fakePelican();
    $fake->eggs['4'] = new PelicanEgg('4', 'GMod', ['SERVER_HOSTNAME' => 'x']);

    $spec = (new ServerSpecBuilder($fake))->build(Game::factory()->create(), '4', [], 'srv', displayName: 'My "LAN"; $(rm -rf) `x` \\ Server');

    expect($spec->environment['SERVER_HOSTNAME'])->toBe('My LAN rm -rf x Server');
});

it('hands configured secrets to the egg only for presets that need them', function () {
    config(['services.pelican.steam_web_api_key' => str_repeat('A', 32), 'services.pelican.gslt' => str_repeat('B', 32)]);
    $fake = fakePelican();
    $fake->eggs['4'] = new PelicanEgg('4', 'GMod', ['STEAM_WEB_API_KEY' => '', 'STEAM_ACC' => '']);
    $game = Game::factory()->create();

    $plain = (new ServerSpecBuilder($fake))->build($game, '4', [], 'srv');
    $needy = (new ServerSpecBuilder($fake))->build($game, '4', [], 'srv', requires: ['authkey', 'gslt']);

    expect($plain->environment['STEAM_WEB_API_KEY'])->toBe('')
        ->and($plain->environment['STEAM_ACC'])->toBe('')
        ->and($needy->environment['STEAM_WEB_API_KEY'])->toBe(str_repeat('A', 32))
        ->and($needy->environment['STEAM_ACC'])->toBe(str_repeat('B', 32));
});
