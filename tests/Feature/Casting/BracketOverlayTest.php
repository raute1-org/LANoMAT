<?php

use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Starting a tournament dispatches TournamentStarted/MatchReady, which
    // reach the Discord/voice listeners — fake both so this suite never
    // hits a real external service (mirrors TournamentPageTest).
    fakeDiscord();
    fakeMumble();
});

/**
 * Starts an 8-entry single-elimination tournament for a publicly-visible
 * (live) event and returns it, freshly reloaded — mirrors
 * TournamentPageTest::startEightEntrySingleElim() so the overlay test
 * exercises the same real bracket shape the participant page does.
 */
function startPubliclyVisibleBracket(): Tournament
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

it('renders the bracket overlay for a tournament whose event is publicly visible', function () {
    $tournament = startPubliclyVisibleBracket();

    $this->get("/overlay/tournament/{$tournament->id}/bracket")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Overlay/Bracket')
            ->where('tournament.id', $tournament->id)
            ->where('tournament.name', $tournament->name)
            ->has('matches', 7)
            ->has('matches.0', fn (AssertableInertia $match) => $match
                ->hasAll(['id', 'round', 'bracket', 'position', 'nextMatchId', 'nextSlot', 'slot1', 'slot2', 'entry1Id', 'entry2Id', 'score1', 'score2', 'winnerEntryId', 'status', 'lockVersion', 'server', 'warmupStartedAt', 'spectateHint'])
            )
            ->has('labels.matchStatusLabels')
            ->has('labels.bracketLabels')
            ->has('labels.reportLabels')
            ->has('labels.liveScoreLabels')
        );
});

it('is reachable by a guest with no authentication', function () {
    $tournament = startPubliclyVisibleBracket();

    // No actingAs() call at all — the default test client is a guest.
    $this->get("/overlay/tournament/{$tournament->id}/bracket")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Overlay/Bracket'));
});

it('404s the bracket overlay for a tournament whose event is still draft', function () {
    $event = Event::factory()->draft()->create();
    $tournament = Tournament::factory()->for($event)->enrollment()->create();

    $this->get("/overlay/tournament/{$tournament->id}/bracket")
        ->assertNotFound();
});
