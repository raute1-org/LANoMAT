<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the public leaderboard page with german labels and the top entrant\'s win count', function () {
    $user = User::factory()->create(['name' => 'Champion']);
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    $opponent = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id]);
    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $entry->id, 'entry2_id' => $opponent->id]);
    $match->forceFill(['status' => MatchStatus::Completed, 'winner_entry_id' => $entry->id])->save();

    $this->get('/stats/leaderboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Stats/Leaderboard')
            ->where('labels.title', 'Bestenliste')
            ->where('rows.0.wins', 1)
        );
});

it('is reachable without a logged-in user', function () {
    expect(auth()->check())->toBeFalse();

    $this->get('/stats/leaderboard')->assertOk();
});

it('renders an empty leaderboard without error when there are no completed matches', function () {
    $this->get('/stats/leaderboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Stats/Leaderboard')
            ->where('rows', [])
        );
});
