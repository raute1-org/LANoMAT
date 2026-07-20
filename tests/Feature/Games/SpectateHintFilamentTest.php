<?php

use App\Modules\Games\Domain\SpectateHint;
use App\Modules\Games\Filament\Resources\Games\Pages\CreateGame;
use App\Modules\Games\Filament\Resources\Games\Pages\EditGame;
use App\Modules\Games\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts a SpectateHint from the flat spectate_hint_* fields and strips them from data', function () {
    $data = [
        'name' => 'Counter-Strike 2',
        'spectate_hint_gotv_connect' => 'connect gotv.example:27020',
        'spectate_hint_observer_note' => 'Observer-Slot anfragen',
        'spectate_hint_replay_note' => 'Replay ab Runde 3',
    ];

    $hint = CreateGame::extractSpectateHint($data);

    expect($hint)->toBeInstanceOf(SpectateHint::class)
        ->and($hint->gotvConnect)->toBe('connect gotv.example:27020')
        ->and($hint->observerNote)->toBe('Observer-Slot anfragen')
        ->and($hint->replayNote)->toBe('Replay ab Runde 3')
        ->and($data)->not->toHaveKeys([
            'spectate_hint_gotv_connect',
            'spectate_hint_observer_note',
            'spectate_hint_replay_note',
        ])
        ->and($data)->toHaveKey('name', 'Counter-Strike 2');
});

it('extracts an empty SpectateHint when none of the flat fields are set', function () {
    $data = ['name' => 'Counter-Strike 2'];

    $hint = CreateGame::extractSpectateHint($data);

    expect($hint->isEmpty())->toBeTrue();
});

/**
 * Invokes a protected/private method via reflection, mirroring
 * tests/Unit/Tournaments/Domain/SlotTest.php's constructSlotViaReflection.
 */
function invokeProtected(object $object, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}

it('hydrates the flat spectate_hint_* fields from the stored SpectateHint on edit', function () {
    $game = Game::factory()->create([
        'spectate_hint' => new SpectateHint(
            gotvConnect: 'connect gotv.example:27020',
            observerNote: 'Observer-Slot anfragen',
            replayNote: 'Replay ab Runde 3',
        ),
    ]);

    $fresh = $game->fresh();

    $editGame = new EditGame;
    $data = invokeProtected($editGame, 'mutateFormDataBeforeFill', [[
        'spectate_hint' => $fresh->spectate_hint,
        'default_server_config' => $fresh->default_server_config,
        'server_presets' => $fresh->server_presets,
        'install_hint' => $fresh->install_hint,
    ]]);

    expect($data['spectate_hint_gotv_connect'])->toBe('connect gotv.example:27020')
        ->and($data['spectate_hint_observer_note'])->toBe('Observer-Slot anfragen')
        ->and($data['spectate_hint_replay_note'])->toBe('Replay ab Runde 3')
        ->and($data)->not->toHaveKey('spectate_hint');
});

it('persists the SpectateHint on the model when creating a game via the Filament create flow', function () {
    $data = [
        'name' => 'Counter-Strike 2',
        'slug' => 'counter-strike-2',
        'min_team_size' => 1,
        'max_team_size' => 5,
        'default_config_mode' => 'form',
        'spectate_hint_gotv_connect' => 'connect gotv.example:27020',
        'spectate_hint_observer_note' => 'Observer-Slot anfragen',
        'spectate_hint_replay_note' => 'Replay ab Runde 3',
    ];

    $createGame = new CreateGame;
    /** @var Game $record */
    $record = invokeProtected($createGame, 'handleRecordCreation', [$data]);

    expect($record->fresh()->spectate_hint->gotvConnect)->toBe('connect gotv.example:27020')
        ->and($record->fresh()->spectate_hint->observerNote)->toBe('Observer-Slot anfragen')
        ->and($record->fresh()->spectate_hint->replayNote)->toBe('Replay ab Runde 3');
});
