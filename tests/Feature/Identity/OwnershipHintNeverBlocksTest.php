<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\Identity\Actions\LinkAccount;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedAccountData;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pins the single most important rule of the game-ownership hint (M9 task
 * 9.7): it is advisory only and must NEVER block enrollment, regardless of
 * whether the check comes back NotOwned or Unknown. If enrollment ever grows
 * an ownership gate, these tests are the guardrail that breaks first.
 */
it('lets a user enroll when ownership is Unknown (no linked account at all)', function () {
    $game = Game::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_app_id' => '730',
    ]);
    $tournament = Tournament::factory()->enrollment()->create(['game_id' => $game->id]);
    $user = User::factory()->create(); // no linked Steam at all → Unknown

    $this->actingAs($user)->post(route('tournaments.enroll', $tournament))->assertRedirect();

    expect($tournament->fresh()->entries()->where('user_id', $user->id)->exists())->toBeTrue();
});

/**
 * Binding-rule guardrail: this test forces GameOwnershipHint::for() to
 * genuinely resolve to NotOwned (not just fall through to Unknown) by giving
 * the game a Steam provider mapping, linking the enrolling user's Steam
 * account, and making the fake connector report owns:false — then asserts
 * enrollment still succeeds end-to-end at the HTTP layer. This is the case
 * that actually exercises the "never gates" guarantee; the Unknown-only
 * variant above would pass even if enrollment secretly gated on NotOwned.
 */
it('lets a user enroll when ownership genuinely resolves to NotOwned via the connector', function () {
    $game = Game::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_app_id' => '730',
    ]);
    $tournament = Tournament::factory()->enrollment()->create(['game_id' => $game->id]);
    $user = User::factory()->create();

    fakeLinkedAccounts()->willReportOwnership(LinkedAccountProvider::Steam, owns: false);
    app(LinkAccount::class)->handle($user, LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: 'steam-1',
        nickname: 'SteamNick',
    ));

    $this->actingAs($user)->post(route('tournaments.enroll', $tournament))->assertRedirect();

    expect($tournament->fresh()->entries()->where('user_id', $user->id)->exists())->toBeTrue();
});
