<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Starts an 8-entry single-elimination tournament (7 matches total: 4 + 2 +
 * 1) and returns it, freshly reloaded.
 */
function startEightEntrySingleElim(): Tournament
{
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

it('renders the tournaments index for an event with german labels', function () {
    $event = Event::factory()->live()->create();
    Tournament::factory()->for($event)->enrollment()->create(['name' => 'Season Opener']);

    $this->get("/events/{$event->slug}/tournaments")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Index')
            ->where('labels.title', 'Turniere')
            ->has('tournaments', 1)
            ->where('tournaments.0.name', 'Season Opener')
        );
});

it('renders the bracket for an 8-entry single-elimination tournament with 7 matches', function () {
    $tournament = startEightEntrySingleElim();

    $this->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('labels.title', 'Turnier')
            ->has('matches', 7)
            ->has('matches.0', fn (AssertableInertia $match) => $match
                ->hasAll(['id', 'round', 'bracket', 'position', 'nextMatchId', 'nextSlot', 'slot1', 'slot2', 'entry1Id', 'entry2Id', 'score1', 'score2', 'winnerEntryId', 'status', 'lockVersion'])
            )
        );
});

it('creates an entry on POST enroll while the tournament is in enrollment', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/tournaments/{$tournament->id}/enroll")
        ->assertRedirect();

    expect(TournamentEntry::query()->where('tournament_id', $tournament->id)->where('user_id', $user->id)->exists())
        ->toBeTrue();
});

it('rejects enroll outside the enrollment window', function () {
    $tournament = Tournament::factory()->create(); // draft
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/tournaments/{$tournament->id}/enroll")
        ->assertForbidden();
});

it('creates a report on POST by a match participant', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $reporter = User::find($entry1->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    expect(MatchReport::query()->where('match_id', $match->id)->exists())->toBeTrue();
    expect($match->fresh()->status->value)->toBe('reported');
});

it('forbids a non-participant from reporting a match result', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertForbidden();

    expect(MatchReport::query()->where('match_id', $match->id)->exists())->toBeFalse();
});

it('forbids a non-participant from confirming a report', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $reporter = User::find($entry1->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $report = MatchReport::query()->where('match_id', $match->id)->firstOrFail();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertForbidden();

    expect($report->fresh()->status->value)->toBe('pending');
});

it('lets the opponent confirm a report, advancing the match to completed', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $lockVersion = $match->fresh()->lock_version;

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $lockVersion])
        ->assertRedirect();

    expect($match->fresh()->status->value)->toBe('completed');
});

it('lets the opponent dispute a report', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/dispute")
        ->assertRedirect();

    expect($match->fresh()->status->value)->toBe('disputed');
});

it('creates an entry on POST checkin while the entry owner acts during the check-in window', function () {
    $tournament = Tournament::factory()->checkIn()->create();
    $user = User::factory()->create();
    $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->post("/tournaments/{$tournament->id}/checkin")
        ->assertRedirect();

    expect($entry->fresh()->status->value)->toBe('checked_in');
});

it('resolves the german tournaments index and show titles', function () {
    app()->setLocale('de');

    expect(__('tournaments.page.index_title'))->toBe('Turniere')
        ->and(__('tournaments.page.show_title'))->toBe('Turnier');
});
