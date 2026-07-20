<?php

use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Builds a two-entry match with persisted scores on a publicly-visible (live)
 * event's tournament — mirrors BracketOverlayTest's
 * startPubliclyVisibleBracket() but does not need a full bracket, since the
 * scoreboard overlay only ever renders one match at a time.
 */
function matchOnPubliclyVisibleTournament(): GameMatch
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    $entry1 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'display_name' => 'Team Rocket']);
    $entry2 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'display_name' => 'Team Aqua']);

    return GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'score1' => 3,
        'score2' => 1,
    ]);
}

it('renders the scoreboard overlay for a match whose event is publicly visible', function () {
    $match = matchOnPubliclyVisibleTournament();

    $this->get("/overlay/match/{$match->id}/scoreboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Overlay/Scoreboard')
            ->where('tournamentId', $match->tournament_id)
            ->where('matchId', $match->id)
            ->where('data.tournament', $match->tournament->name)
            ->where('data.team1', 'Team Rocket')
            ->where('data.team2', 'Team Aqua')
            ->where('data.score1', 3)
            ->where('data.score2', 1)
            ->has('labels')
        );
});

it('is reachable by a guest with no authentication', function () {
    $match = matchOnPubliclyVisibleTournament();

    // No actingAs() call at all — the default test client is a guest.
    $this->get("/overlay/match/{$match->id}/scoreboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Overlay/Scoreboard'));
});

it('renders gracefully for a match with an empty slot', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    $entry1 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'display_name' => 'Solo Entrant']);

    $match = GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'entry1_id' => $entry1->id,
        'entry2_id' => null,
        'score1' => null,
        'score2' => null,
    ]);

    $this->get("/overlay/match/{$match->id}/scoreboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Overlay/Scoreboard')
            ->where('data.team1', 'Solo Entrant')
            ->where('data.team2', null)
            ->where('data.score1', null)
            ->where('data.score2', null)
        );
});

it('404s the scoreboard overlay for a match whose event is still draft', function () {
    $event = Event::factory()->draft()->create();
    $tournament = Tournament::factory()->for($event)->enrollment()->create();
    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id]);

    $this->get("/overlay/match/{$match->id}/scoreboard")
        ->assertNotFound();
});
