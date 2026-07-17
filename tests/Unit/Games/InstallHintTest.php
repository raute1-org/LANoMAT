<?php

use App\Modules\Games\Domain\InstallHint;
use App\Modules\Games\Models\Game;

it('creates a game via the factory with install_hint cast to InstallHint', function () {
    $game = Game::factory()->create([
        'install_hint' => new InstallHint(steamUrl: 'steam://install/730', shareUrl: null, versionNote: 'v1.2'),
    ]);

    $fresh = $game->fresh();

    expect($fresh->install_hint)->toBeInstanceOf(InstallHint::class)
        ->and($fresh->install_hint->steamUrl)->toBe('steam://install/730')
        ->and($fresh->install_hint->shareUrl)->toBeNull()
        ->and($fresh->install_hint->versionNote)->toBe('v1.2');
});

it('decodes a null install_hint to an empty InstallHint', function () {
    $game = Game::factory()->create(['install_hint' => null]);

    expect($game->fresh()->install_hint)->toBeInstanceOf(InstallHint::class)
        ->and($game->fresh()->install_hint->toArray())->toBe([]);
});

it('has install_hint not fillable on Game', function () {
    $game = new Game;

    expect($game->getFillable())->not->toContain('install_hint');
});

it('drops empty fields from InstallHint::toArray', function () {
    $hint = new InstallHint(steamUrl: 'steam://install/730', shareUrl: null, versionNote: null);

    expect($hint->toArray())->toBe(['steam_url' => 'steam://install/730']);
});

it('reconstructs an InstallHint from an array via fromArray', function () {
    $hint = InstallHint::fromArray([
        'steam_url' => 'steam://install/730',
        'share_url' => 'https://files.example/patch.zip',
        'version_note' => 'v1.2',
    ]);

    expect($hint->steamUrl)->toBe('steam://install/730')
        ->and($hint->shareUrl)->toBe('https://files.example/patch.zip')
        ->and($hint->versionNote)->toBe('v1.2');
});

it('has a german label for the install hint field', function () {
    expect(__('games.fields.install_hint'))->toBe('So kommst du ran');
});
