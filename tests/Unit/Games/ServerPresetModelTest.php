<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;

it('round-trips the extended preset fields through arrays', function () {
    $preset = new ServerPreset(
        key: 'ttt',
        name: 'TTT',
        config: new ServerConfig(
            maxPlayers: 16,
            memoryMb: 3072,
            environment: ['GAMEMODE' => 'terrortown', 'SRCDS_MAP' => 'gm_construct'],
            overridable: ['SRCDS_MAP'],
            extraPorts: ['TV_PORT'],
            readinessType: 'a2s',
            readinessGame: 'Trouble in Terrorist Town',
            readinessFolder: 'garrysmod',
            readinessTimeout: 180,
            connectTemplate: 'steam://connect/{ip}:{port}',
            contentType: 'workshop',
            contentRef: '123456',
            installMarker: 'garrysmod',
        ),
        description: 'Round-based',
        eggUuid: 'uuid-1',
        eggName: 'Garrys Mod (LANoMAT)',
        enabled: false,
        requires: ['authkey'],
    );

    $copy = ServerPreset::fromArray($preset->toArray());

    expect($copy)->toEqual($preset)
        ->and($copy->config->usesA2sReadiness())->toBeTrue();
});

it('reads a legacy preset without the new keys as an enabled preset with no requirements', function () {
    $preset = ServerPreset::fromArray(['key' => 'k', 'name' => 'Old', 'config' => ['max_players' => 8, 'map' => 'de_dust2']]);

    expect($preset->enabled)->toBeTrue()
        ->and($preset->requires)->toBe([])
        ->and($preset->eggUuid)->toBeNull()
        ->and($preset->config->overridable)->toBe([])
        ->and($preset->config->usesA2sReadiness())->toBeFalse()
        ->and($preset->config->maxPlayers)->toBe(8);
});

it('drops empty optional values from the stored config array', function () {
    expect((new ServerConfig(maxPlayers: 4))->toArray())->toBe(['max_players' => 4]);
});

it('round-trips fixed ports and reads old presets without them', function () {
    $config = new ServerConfig(fixedPorts: ['main' => 17777, 'QUERY_PORT' => 17778]);

    expect(ServerConfig::fromArray($config->toArray()))->toEqual($config)
        ->and(ServerConfig::fromArray(['max_players' => 4])->fixedPorts)->toBe([])
        ->and((new ServerConfig(maxPlayers: 4))->toArray())->not->toHaveKey('fixed_ports');
});

it('rejects invalid fixed ports and drops them when reading stored data', function (array $ports) {
    expect(fn () => new ServerConfig(fixedPorts: $ports))->toThrow(InvalidArgumentException::class);
})->with([
    'too low' => [['main' => 80]],
    'too high' => [['main' => 70000]],
    'duplicate' => [['main' => 17777, 'QUERY_PORT' => 17777]],
]);

it('tolerates broken stored fixed ports instead of throwing', function () {
    $config = ServerConfig::fromArray(['fixed_ports' => ['main' => 17777, 'A' => 17777, 'B' => 80, 'C' => 'x', 'D' => '17780']]);

    expect($config->fixedPorts)->toBe(['main' => 17777, 'D' => 17780]);
});
