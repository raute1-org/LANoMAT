<?php

use App\Modules\Games\Domain\SpectateHint;
use App\Modules\Games\Models\Game;

it('creates a game via the factory with spectate_hint cast to SpectateHint', function () {
    $game = Game::factory()->create([
        'spectate_hint' => new SpectateHint(gotvConnect: 'connect gotv.example:27020', observerNote: null, replayNote: 'Replay ab Runde 3'),
    ]);

    $fresh = $game->fresh();

    expect($fresh->spectate_hint)->toBeInstanceOf(SpectateHint::class)
        ->and($fresh->spectate_hint->gotvConnect)->toBe('connect gotv.example:27020')
        ->and($fresh->spectate_hint->observerNote)->toBeNull()
        ->and($fresh->spectate_hint->replayNote)->toBe('Replay ab Runde 3');
});

it('decodes a null spectate_hint to an empty SpectateHint', function () {
    $game = Game::factory()->create(['spectate_hint' => null]);

    expect($game->fresh()->spectate_hint)->toBeInstanceOf(SpectateHint::class)
        ->and($game->fresh()->spectate_hint->toArray())->toBe([]);
});

it('has spectate_hint not fillable on Game', function () {
    $game = new Game;

    expect($game->getFillable())->not->toContain('spectate_hint');
});

it('drops empty fields from SpectateHint::toArray', function () {
    $hint = new SpectateHint(gotvConnect: 'connect gotv.example:27020', observerNote: null, replayNote: null);

    expect($hint->toArray())->toBe(['gotv_connect' => 'connect gotv.example:27020']);
});

it('reconstructs a SpectateHint from an array via fromArray', function () {
    $hint = SpectateHint::fromArray([
        'gotv_connect' => 'connect gotv.example:27020',
        'observer_note' => 'Observer-Slot anfragen',
        'replay_note' => 'Replay ab Runde 3',
    ]);

    expect($hint->gotvConnect)->toBe('connect gotv.example:27020')
        ->and($hint->observerNote)->toBe('Observer-Slot anfragen')
        ->and($hint->replayNote)->toBe('Replay ab Runde 3');
});

it('reports isEmpty true only when all three fields are null', function () {
    expect((new SpectateHint)->isEmpty())->toBeTrue()
        ->and((new SpectateHint(gotvConnect: 'connect gotv.example:27020'))->isEmpty())->toBeFalse();
});

it('has a german label for the spectate hint field', function () {
    expect(__('games.fields.spectate_hint'))->toBe('So schaust du zu');
});
