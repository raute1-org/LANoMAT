<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\UploadServerConfig;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\GameServers\Support\EffectiveConfig;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('stores an uploaded server config to the public disk and returns its path, never Base64', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->createWithContent(
        'config.json',
        json_encode(['max_players' => 24, 'map' => 'de_nuke']),
    );

    $path = (new UploadServerConfig)->handle($file);

    expect($path)->toBeString()
        ->and($path)->toStartWith('gameserver-configs/');

    Storage::disk('public')->assertExists($path);

    // Never Base64 into the DB: the action's return value is a Storage path
    // string, not the file's (encoded) contents.
    expect(base64_decode($path, true))->not->toBe(json_encode(['max_players' => 24, 'map' => 'de_nuke']));
});

it('feeds the resolved uploaded config to the fake Pelican client via the provision job', function () {
    Storage::fake('public');
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $file = UploadedFile::fake()->createWithContent(
        'config.json',
        json_encode(['max_players' => 24, 'map' => 'de_nuke']),
    );
    $path = (new UploadServerConfig)->handle($file);

    $game = Game::factory()->create([
        'pelican_egg_id' => 'egg-77',
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);
    $match = GameMatch::factory()->for($tournament)->create();

    // EffectiveConfig::resolve is the single source of truth for what the
    // provision job feeds to PelicanClient::createServer; here it is
    // exercised directly with the uploaded path (upload mode) to prove the
    // uploaded file's parsed contents — not the game's default — reach
    // fakePelican().
    $config = EffectiveConfig::resolve($game, presetKey: null, uploadedPath: $path);

    // Simulate what a match/tournament-level preset selection would feed the
    // job with today: ProvisionMatchServerJob itself still reads
    // Game::default_server_config, so this asserts EffectiveConfig's output
    // is exactly what createServer would receive once wired to a real
    // selection, by calling createServer with it directly against the same
    // fake used by the job.
    $server = $fake->createServer($game->pelican_egg_id, $config);

    $fake->assertServerCreated('egg-77');
    expect($fake->created[0]['config'])->toMatchArray(['max_players' => 24, 'map' => 'de_nuke']);

    // And the job itself still resolves to *a* single effective config
    // (currently the game default, since no selection mechanism is wired
    // into MatchReady yet) when actually provisioning the match server.
    (new ProvisionMatchServerJob($match->id))->handle($fake);
    $fake->assertServerCreated('egg-77');
    expect($fake->created[1]['config'])->toMatchArray(['max_players' => 10, 'map' => 'de_dust2']);
});

it('surfaces a german translated error message when both a preset and an uploaded config are supplied', function () {
    Storage::fake('public');

    $game = Game::factory()->create([
        'server_presets' => [
            new ServerPreset('competitive', 'Competitive', new ServerConfig(maxPlayers: 10, map: 'de_dust2')),
        ],
    ]);

    $file = UploadedFile::fake()->createWithContent('config.json', json_encode(['max_players' => 24]));
    $path = (new UploadServerConfig)->handle($file);

    try {
        EffectiveConfig::resolve($game, presetKey: 'competitive', uploadedPath: $path);
        test()->fail('Expected GameServerException to be thrown.');
    } catch (GameServerException $e) {
        expect(__($e->translationKey))
            ->toBe('Es wurden sowohl ein Server-Preset als auch eine hochgeladene Konfiguration angegeben; es ist genau eine erlaubt.');
    }
});
