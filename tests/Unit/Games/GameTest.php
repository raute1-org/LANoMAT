<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Database\QueryException;

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

it('has name, slug, icon_path, min_team_size, max_team_size, pelican_egg_id fillable but not default_server_config', function () {
    $game = new Game;

    expect($game->getFillable())->toBe([
        'name',
        'slug',
        'icon_path',
        'min_team_size',
        'max_team_size',
        'pelican_egg_id',
    ]);
});

it('has a german resource label', function () {
    expect(__('games.resource.label'))->toBe('Spiel')
        ->and(__('games.resource.plural_label'))->toBe('Spiele');
});
