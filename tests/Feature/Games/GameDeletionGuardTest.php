<?php

use App\Models\User;
use App\Modules\Games\Exceptions\GameInUseException;
use App\Modules\Games\Filament\Resources\Games\Pages\EditGame;
use App\Modules\Games\Filament\Resources\Games\Pages\ListGames;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('refuses to delete a game that tournaments use, at model level', function () {
    $game = Game::factory()->create();
    Tournament::factory()->create(['game_id' => $game->id]);

    expect(fn () => $game->delete())->toThrow(GameInUseException::class);
    expect(Game::query()->whereKey($game->id)->exists())->toBeTrue();
});

it('keeps tournament game_id intact even for a raw delete (restrictOnDelete)', function () {
    $game = Game::factory()->create();
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);

    expect(fn () => DB::transaction(fn () => Game::query()->whereKey($game->id)->toBase()->delete()))
        ->toThrow(QueryException::class);
    expect($tournament->fresh()->game_id)->toBe($game->id);
});

it('shows a german warning and keeps the game when deleting a used game in the admin', function () {
    $game = Game::factory()->create();
    Tournament::factory()->create(['game_id' => $game->id]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListGames::class)
        ->callTableAction('delete', $game)
        ->assertNotified(__('games.errors.in_use'));

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->callAction('delete')
        ->assertNotified(__('games.errors.in_use'));

    expect(Game::query()->whereKey($game->id)->exists())->toBeTrue();
});

it('still deletes an unused game', function () {
    $game = Game::factory()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListGames::class)->callTableAction('delete', $game);

    expect(Game::query()->whereKey($game->id)->exists())->toBeFalse();
});
