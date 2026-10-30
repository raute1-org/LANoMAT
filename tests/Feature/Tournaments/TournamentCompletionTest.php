<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
    $this->actingAs(User::factory()->orga()->create());
});

function startedTournament(TournamentFormat $format, int $n): Tournament
{
    $tournament = Tournament::factory()->for(Event::factory()->live()->create())->checkIn()->create(['format' => $format]);
    TournamentEntry::factory()->checkedIn()->count($n)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

/** Plays every still-playable match (slot1 always wins 2:0) until none is left. */
function playOutAll(Tournament $tournament, ?callable $scores = null): void
{
    $scores ??= fn (): array => [2, 0];
    $guard = 0;

    while ($guard++ < 500) {
        $match = GameMatch::where('tournament_id', $tournament->id)
            ->where('status', 'ready')->orderBy('round')->orderBy('position')->first();

        if ($match === null) {
            return;
        }

        [$s1, $s2] = $scores($match);
        app(OverrideMatchResult::class)->handle($match, $s1, $s2);
    }
}

// F-S4-4
it('finishes a single-elimination tournament with exactly two entries', function (TournamentFormat $format) {
    EventFacade::fake([TournamentCompleted::class]);
    $tournament = startedTournament($format, 2);

    playOutAll($tournament);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->not->toBeNull();
    EventFacade::assertDispatched(TournamentCompleted::class);
})->with([TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination]);

// F-S4-5
it('finishes a round robin once every match is played and crowns the standings leader', function (int $n) {
    $tournament = startedTournament(TournamentFormat::RoundRobin, $n);
    expect(GameMatch::where('tournament_id', $tournament->id)->count())->toBe($n * ($n - 1) / 2);

    // The entry with the highest id always wins.
    playOutAll($tournament, fn (GameMatch $m): array => $m->entry1_id > $m->entry2_id ? [2, 1] : [1, 2]);

    $tournament->refresh();
    $topEntry = TournamentEntry::where('tournament_id', $tournament->id)->max('id');

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->toBe((int) $topEntry);
})->with([2, 3, 4, 5, 8]);

it('does not finish a round robin while a match is still open', function () {
    $tournament = startedTournament(TournamentFormat::RoundRobin, 4);
    $first = GameMatch::where('tournament_id', $tournament->id)->orderBy('round')->orderBy('position')->first();

    app(OverrideMatchResult::class)->handle($first, 2, 0);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Live)
        ->and($tournament->fresh()->winner_entry_id)->toBeNull();
});

it('shows round robin standings on the tournament page', function () {
    $tournament = startedTournament(TournamentFormat::RoundRobin, 3);
    playOutAll($tournament);

    $this->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('standings', 3)
            ->where('standings.0.rank', 1)
            ->has('standings.0.name')
            ->has('standings.0.wins')
            ->has('standings.0.scoreDifference')
            ->has('standingsLabels.title')
        );
});

it('sends no standings for elimination formats', function () {
    $tournament = startedTournament(TournamentFormat::SingleElimination, 4);

    $this->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('standings', null));
});

it('exposes the champion on finished tournaments', function () {
    $tournament = startedTournament(TournamentFormat::SingleElimination, 2);
    playOutAll($tournament);

    $winner = TournamentEntry::find($tournament->fresh()->winner_entry_id);

    $this->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tournament.winnerName', $winner->display_name)
        );
});

it('shows the round robin table on the admin edit page', function () {
    $tournament = startedTournament(TournamentFormat::RoundRobin, 3);
    playOutAll($tournament);

    Livewire\Livewire::test(EditTournament::class, ['record' => $tournament->getKey()])
        ->assertSee(__('tournaments.standings.title'))
        ->assertSee('1. ');
});
