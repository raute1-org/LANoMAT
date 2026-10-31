<?php

use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Support\PresetAvailability;
use App\Modules\GameServers\Support\RepositoryEggs;
use Database\Seeders\GamesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.pelican.steam_web_api_key' => null, 'services.pelican.gslt' => null]);
    $this->seed(GamesSeeder::class);
});

it('seeds a startable TTT preset on the LANoMAT Garrys Mod egg', function () {
    $preset = Game::query()->where('slug', 'garrys-mod')->firstOrFail()->findPreset('ttt');

    expect($preset)->not->toBeNull()
        ->and($preset->eggUuid)->toBe(RepositoryEggs::GMOD_UUID)
        ->and($preset->config->environment['GAMEMODE'])->toBe('terrortown')
        ->and($preset->config->maxPlayers)->toBe(16)
        ->and($preset->config->usesA2sReadiness())->toBeTrue()
        ->and($preset->config->overridable)->toContain('SRCDS_MAP')
        ->and($preset->config->overridable)->not->toContain('GAMEMODE')
        ->and(PresetAvailability::isAvailable($preset))->toBeTrue();
});

it('keeps Prop Hunt locked until a Steam Web API key is configured', function () {
    $preset = Game::query()->where('slug', 'garrys-mod')->firstOrFail()->findPreset('prop-hunt');

    expect(PresetAvailability::reasons($preset))->toContain('requires_authkey');

    config(['services.pelican.steam_web_api_key' => str_repeat('K', 32)]);

    expect(PresetAvailability::reasons($preset))->not->toContain('requires_authkey')
        ->and(PresetAvailability::reasons($preset))->toBe(['requires_content']);
});

it('seeds a startable Minecraft Paper preset with a plain join address and the Minecraft query', function () {
    $preset = Game::query()->where('slug', 'minecraft')->firstOrFail()->findPreset('paper');

    expect($preset)->not->toBeNull()
        ->and($preset->eggName)->toBe('Paper')
        ->and($preset->eggUuid)->toBeNull()
        ->and($preset->config->readinessType)->toBe('minecraft')
        ->and($preset->config->usesA2sReadiness())->toBeFalse()
        ->and($preset->config->connectTemplate)->toBe('{ip}:{port}')
        ->and($preset->config->overridable)->toBe(['MINECRAFT_VERSION'])
        ->and(PresetAvailability::isAvailable($preset))->toBeTrue();
});

it('ships CS2 GunGame as a disabled, explained stub', function () {
    $preset = Game::query()->where('slug', 'counter-strike-2')->firstOrFail()->findPreset('cs2-gungame');

    expect($preset->enabled)->toBeFalse()
        ->and($preset->description)->toContain('Metamod')
        ->and($preset->config->extraPorts)->toBe(['TV_PORT'])
        ->and(PresetAvailability::reasons($preset))->toBe(['disabled', 'requires_gslt', 'requires_content']);
});

it('does not overwrite presets an orga edited when seeded again', function () {
    $game = Game::query()->where('slug', 'garrys-mod')->firstOrFail();
    $presets = $game->server_presets;
    $presets[0] = new ServerPreset('ttt', 'Mein TTT', $presets[0]->config);
    $game->server_presets = $presets;
    $game->save();

    $this->seed(GamesSeeder::class);

    $fresh = Game::query()->where('slug', 'garrys-mod')->firstOrFail();
    expect($fresh->server_presets)->toHaveCount(2)->and($fresh->findPreset('ttt')->name)->toBe('Mein TTT');
});
