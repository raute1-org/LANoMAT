<?php

use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Actions\UpdateTournament;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\TournamentSetupCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('refuses to update a live tournament', function () {
    app(UpdateTournament::class)->handle(User::factory()->orga()->create(), Tournament::factory()->live()->create(), ['name' => 'x']);
})->throws(TournamentException::class);

it('rejects a participant', function () {
    app(UpdateTournament::class)->handle(User::factory()->create(), Tournament::factory()->create(), ['name' => 'x']);
})->throws(AuthorizationException::class);

it('updates missing setup fields so the setup check passes', function () {
    $tournament = Tournament::factory()->create();
    expect(TournamentSetupCheck::missingFields($tournament))->not->toBe([]);

    $updated = app(UpdateTournament::class)->handle(User::factory()->orga()->create(), $tournament, [
        'game_id' => Game::factory()->create()->id,
        'max_entries' => 16,
        'checkin_opens_at' => '2026-12-05 12:30:00',
        'checkin_closes_at' => '2026-12-05 12:55:00',
    ]);

    expect(TournamentSetupCheck::missingFields($updated))->toBe([]);
});

it('validates the check-in window against the stored value', function () {
    $tournament = Tournament::factory()->create(['checkin_opens_at' => '2026-12-05 12:30:00']);

    app(UpdateTournament::class)->handle(User::factory()->orga()->create(), $tournament, ['checkin_closes_at' => '2026-12-05 12:00:00']);
})->throws(ValidationException::class);

it('can edit an announced tournament', function () {
    $t = Tournament::factory()->announced()->create();

    $updated = app(UpdateTournament::class)->handle(User::factory()->orga()->create(), $t, ['name' => 'Renamed']);

    expect($updated->name)->toBe('Renamed');
});
