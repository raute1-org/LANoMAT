<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pins the single most important rule of the game-ownership hint (M9 task
 * 9.7): it is advisory only and must NEVER block enrollment, regardless of
 * whether the check comes back NotOwned or Unknown. If enrollment ever grows
 * an ownership gate, this test is the guardrail that breaks first.
 */
it('lets a user enroll even when ownership is NotOwned or Unknown', function (string $case) {
    $game = Game::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_app_id' => '730',
    ]);
    $tournament = Tournament::factory()->enrollment()->create(['game_id' => $game->id]);
    $user = User::factory()->create(); // no linked Steam at all → Unknown

    if ($case === 'notowned') {
        // Even if the user HAD linked Steam and it came back NotOwned,
        // enrollment must still succeed.
        fakeLinkedAccounts()->willReportOwnership(LinkedAccountProvider::Steam, owns: false);
    }

    $this->actingAs($user)->post(route('tournaments.enroll', $tournament))->assertRedirect();

    expect($tournament->fresh()->entries()->where('user_id', $user->id)->exists())->toBeTrue();
})->with(['unknown', 'notowned']);
