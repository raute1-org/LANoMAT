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
