<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

it('creates a game via the factory with default_server_config cast to ServerConfig', function () {
    $game = Game::factory()->create([
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);

    $fresh = $game->fresh();

    expect($fresh->default_server_config)->toBeInstanceOf(ServerConfig::class)
        ->and($fresh->default_server_config->maxPlayers)->toBe(10)
        ->and($fresh->default_server_config->map)->toBe('de_dust2');
});

it('decodes a null default_server_config to an empty ServerConfig', function () {
    $game = Game::factory()->create(['default_server_config' => null]);

    expect($game->fresh()->default_server_config)->toBeInstanceOf(ServerConfig::class)
        ->and($game->fresh()->default_server_config->toArray())->toBe([]);
});

it('enforces a unique slug', function () {
    Game::factory()->create(['slug' => 'counter-strike-2']);

    Game::factory()->create(['slug' => 'counter-strike-2']);
})->throws(QueryException::class);

it('resolves the tournaments relation', function () {
    $game = Game::factory()->create();
    Tournament::factory()->for($game, 'game')->create();

    expect($game->tournaments)->toHaveCount(1)
        ->and($game->tournaments->first())->toBeInstanceOf(Tournament::class);
});

it('has name, slug, icon_path, min_team_size, max_team_size, pelican_egg_id, provider, provider_app_id fillable but not default_server_config', function () {
    $game = new Game;

    expect($game->getFillable())->toBe([
        'name',
        'slug',
        'icon_path',
        'min_team_size',
        'max_team_size',
        'pelican_egg_id',
        'provider',
        'provider_app_id',
    ]);
});

it('deletes the icon file from the public disk when the game is deleted', function () {
    Storage::fake('public');

    $iconPath = 'games/icons/counter-strike-2.png';
    Storage::disk('public')->put($iconPath, 'fake-icon-contents');

    $game = Game::factory()->create(['icon_path' => $iconPath]);

    Storage::disk('public')->assertExists($iconPath);

    $game->delete();

    Storage::disk('public')->assertMissing($iconPath);
});

it('does not error deleting a game with no icon_path set', function () {
    $game = Game::factory()->create(['icon_path' => null]);

    $game->delete();

    expect(Game::query()->find($game->id))->toBeNull();
});

it('has a german resource label', function () {
    expect(__('games.resource.label'))->toBe('Spiel')
        ->and(__('games.resource.plural_label'))->toBe('Spiele');
});

it('casts provider to LinkedAccountProvider and defaults it and provider_app_id to null', function () {
    $game = Game::factory()->create();

    expect($game->fresh()->provider)->toBeNull()
        ->and($game->fresh()->provider_app_id)->toBeNull();

    $mapped = Game::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_app_id' => '730',
    ]);

    expect($mapped->fresh()->provider)->toBe(LinkedAccountProvider::Steam)
        ->and($mapped->fresh()->provider_app_id)->toBe('730');
});
