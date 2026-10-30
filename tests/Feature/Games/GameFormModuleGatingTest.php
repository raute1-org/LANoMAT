<?php

use App\Models\User;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Filament\Resources\Games\Pages\EditGame;
use App\Modules\Games\Models\Game;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the game-server fields only while the GameServers module is on', function () {
    $game = Game::factory()->create();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->assertFormFieldVisible('pelican_egg_id')
        ->assertFormFieldVisible('server_presets')
        ->assertFormFieldVisible('install_hint_steam_url');

    app()->instance(Modules::class, new Modules('core'));

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->assertFormFieldHidden('pelican_egg_id')
        ->assertFormFieldHidden('server_presets')
        ->assertFormFieldHidden('install_hint_steam_url')
        ->assertFormFieldHidden('max_players')
        ->assertFormFieldVisible('name');
});

it('keeps stored game-server data when a game is saved under core', function () {
    $game = Game::factory()->create(['pelican_egg_id' => '42']);
    $game->default_server_config = new ServerConfig(maxPlayers: 10, map: 'de_dust2', password: null);
    $game->server_presets = [new ServerPreset('k', 'Preset', new ServerConfig(maxPlayers: 4, map: null, password: null))];
    $game->save();

    $this->actingAs(User::factory()->orga()->create());
    app()->instance(Modules::class, new Modules('core'));

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $game->fresh();
    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->pelican_egg_id)->toBe('42')
        ->and($fresh->default_server_config->maxPlayers)->toBe(10)
        ->and($fresh->server_presets)->toHaveCount(1);
});
