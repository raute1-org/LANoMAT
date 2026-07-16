<?php

use App\Modules\Games\Filament\Resources\Games\Pages\CreateGame;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * CreateGame::extractConfig (shared by EditGame via
 * CreateGame::extractConfig) used to silently fall back to an empty
 * ServerConfig when the uploaded default-config file was missing or not
 * valid JSON — a latent data-loss bug, since GameServers\Support\
 * EffectiveConfig::resolve() throws on the exact same inputs. Both now
 * delegate to ServerConfig::fromStoragePath(), which always throws; this
 * asserts the Filament boundary turns that throw into a translated
 * Notification + Halt (no unhandled 500, no silently-empty config) rather
 * than reintroducing the old silent fallback.
 */
it('surfaces a translated notification instead of a silent empty config when the uploaded default config is corrupt JSON', function () {
    Storage::fake('public');
    Storage::disk('public')->put('gameserver-configs/corrupt.json', 'not valid json {{{');

    $data = ['default_config_upload' => 'gameserver-configs/corrupt.json'];

    expect(fn () => CreateGame::extractConfig($data))->toThrow(Halt::class);

    Notification::assertNotified(__('gameservers.errors.invalid_default_config_upload'));
});

it('surfaces a translated notification instead of a silent empty config when the uploaded default config file is missing', function () {
    Storage::fake('public');

    $data = ['default_config_upload' => 'gameserver-configs/does-not-exist.json'];

    expect(fn () => CreateGame::extractConfig($data))->toThrow(Halt::class);

    Notification::assertNotified(__('gameservers.errors.invalid_default_config_upload'));
});

it('still parses a valid uploaded default config into a ServerConfig', function () {
    Storage::fake('public');
    Storage::disk('public')->put(
        'gameserver-configs/valid.json',
        json_encode(['max_players' => 24, 'map' => 'de_nuke']),
    );

    $data = ['default_config_upload' => 'gameserver-configs/valid.json'];

    $config = CreateGame::extractConfig($data);

    expect($config->maxPlayers)->toBe(24)
        ->and($config->map)->toBe('de_nuke');
});
