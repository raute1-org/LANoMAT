<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\EffectiveConfig;
use Illuminate\Support\Facades\Storage;

it('resolves the chosen preset config when a preset key is given (form mode)', function () {
    $game = Game::factory()->create([
        'default_server_config' => new ServerConfig(maxPlayers: 8, map: 'default-map'),
        'server_presets' => [
            new ServerPreset('competitive', 'Competitive', new ServerConfig(maxPlayers: 10, map: 'de_dust2')),
            new ServerPreset('casual', 'Casual', new ServerConfig(maxPlayers: 20, map: 'de_office')),
        ],
    ]);

    $config = EffectiveConfig::resolve($game, presetKey: 'competitive', uploadedPath: null);

    expect($config)->toMatchArray(['max_players' => 10, 'map' => 'de_dust2']);
});

it('throws when the given preset key does not exist on the game', function () {
    $game = Game::factory()->create(['server_presets' => []]);

    expect(fn () => EffectiveConfig::resolve($game, presetKey: 'missing', uploadedPath: null))
        ->toThrow(GameServerException::class);
});

it('resolves the uploaded config file when an upload path is given (upload mode)', function () {
    Storage::fake('public');

    $game = Game::factory()->create([
        'default_server_config' => new ServerConfig(maxPlayers: 8, map: 'default-map'),
    ]);

    $path = 'gameserver-configs/uploaded.json';
    Storage::disk('public')->put($path, json_encode(['max_players' => 32, 'map' => 'de_inferno']));

    $config = EffectiveConfig::resolve($game, presetKey: null, uploadedPath: $path);

    expect($config)->toMatchArray(['max_players' => 32, 'map' => 'de_inferno']);
});

it('throws GameServerException when both a preset key and an uploaded path are supplied', function () {
    Storage::fake('public');

    $game = Game::factory()->create([
        'server_presets' => [
            new ServerPreset('competitive', 'Competitive', new ServerConfig(maxPlayers: 10, map: 'de_dust2')),
        ],
    ]);

    $path = 'gameserver-configs/uploaded.json';
    Storage::disk('public')->put($path, json_encode(['max_players' => 32]));

    expect(fn () => EffectiveConfig::resolve($game, presetKey: 'competitive', uploadedPath: $path))
        ->toThrow(GameServerException::class);
});

it('falls back to the game default_server_config when neither preset nor upload is supplied', function () {
    $game = Game::factory()->create([
        'default_server_config' => new ServerConfig(maxPlayers: 8, map: 'default-map'),
    ]);

    $config = EffectiveConfig::resolve($game, presetKey: null, uploadedPath: null);

    expect($config)->toMatchArray(['max_players' => 8, 'map' => 'default-map']);
});

it('carries a german translation key on the both-supplied error', function () {
    Storage::fake('public');

    $game = Game::factory()->create([
        'server_presets' => [
            new ServerPreset('competitive', 'Competitive', new ServerConfig(maxPlayers: 10)),
        ],
    ]);

    $path = 'gameserver-configs/uploaded.json';
    Storage::disk('public')->put($path, json_encode(['max_players' => 32]));

    try {
        EffectiveConfig::resolve($game, presetKey: 'competitive', uploadedPath: $path);
        $this->fail('Expected GameServerException to be thrown.');
    } catch (GameServerException $e) {
        expect(__($e->translationKey))->toBe('Es wurden sowohl ein Server-Preset als auch eine hochgeladene Konfiguration angegeben; es ist genau eine erlaubt.');
    }
});
