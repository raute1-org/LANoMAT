<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Actions\SetTournamentWinner;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\RelationManagers\MatchesRelationManager;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchOverride;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
    $this->orga = User::factory()->orga()->create();
    $this->actingAs($this->orga);
});

function decisionTournament(int $entries, TournamentFormat $format = TournamentFormat::SingleElimination): Tournament
{
    $tournament = Tournament::factory()->for(Event::factory()->live()->create())->checkIn()->create(['format' => $format]);
    TournamentEntry::factory()->checkedIn()->count($entries)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

/** @return Collection<int, GameMatch> round-1 matches by position */
function decisionRoundOne(Tournament $tournament)
{
    return GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->get();
}

it('lets the orga decide a ready match (no-show) with an audit row and advances the winner', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();

    $result = app(OverrideMatchResult::class)->handle($match, 1, 0, 'Opponent did not show up');

    expect($result->status)->toBe(MatchStatus::Completed)
        ->and($result->winner_entry_id)->toBe($match->entry1_id);

    $audit = MatchOverride::query()->where('match_id', $match->id)->sole();
    expect($audit->reason)->toBe('Opponent did not show up')
        ->and($audit->user_id)->toBe($this->orga->id)
        ->and($audit->previous_status)->toBe('ready');

    $final = GameMatch::find($match->next_match_id);
    expect($final->{'entry'.$match->next_slot.'_id'})->toBe($match->entry1_id);
});

it('supersedes a pending report when the orga decides a reported match', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $report = app(SubmitMatchReport::class)->handle($match, $entry1, 2, 0);

    app(OverrideMatchResult::class)->handle($match->fresh(), 0, 3, 'Report was wrong');

    expect($report->fresh()->status)->toBe(ReportStatus::Withdrawn)
        ->and($match->fresh()->winner_entry_id)->toBe($match->entry2_id);
});

it('refuses to decide a pending match with a typed German-keyed exception', function () {
    $tournament = decisionTournament(4);
    $final = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->firstOrFail();

    try {
        app(OverrideMatchResult::class)->handle($final, 1, 0, 'x');
        $this->fail('expected exception');
    } catch (TournamentException $e) {
        expect($e->translationKey)->toBe('tournaments.errors.match_not_decidable');
    }
});

it('corrects a completed match by swapping the entrant in a still waiting follow-up match', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    app(OverrideMatchResult::class)->handle($match, 1, 0, 'first');

    app(OverrideMatchResult::class)->handle($match->fresh(), 0, 1, 'correction');

    $final = GameMatch::find($match->next_match_id);
    expect($final->{'entry'.$match->next_slot.'_id'})->toBe($match->entry2_id)
        ->and($match->fresh()->winner_entry_id)->toBe($match->entry2_id)
        ->and(MatchOverride::where('match_id', $match->id)->count())->toBe(2);
});

it('only updates the score when a correction keeps the winner', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    app(OverrideMatchResult::class)->handle($match, 1, 0, 'first');

    app(OverrideMatchResult::class)->handle($match->fresh(), 5, 2, 'score fix');

    expect($match->fresh()->score1)->toBe(5)
        ->and($match->fresh()->winner_entry_id)->toBe($match->entry1_id);
});

it('refuses to flip a result once the follow-up match has both entrants', function () {
    $tournament = decisionTournament(4);
    [$a, $b] = decisionRoundOne($tournament)->all();
    app(OverrideMatchResult::class)->handle($a, 1, 0, 'a');
    app(OverrideMatchResult::class)->handle($b, 1, 0, 'b');

    try {
        app(OverrideMatchResult::class)->handle($a->fresh(), 0, 1, 'flip');
        $this->fail('expected exception');
    } catch (TournamentException $e) {
        expect($e->translationKey)->toBe('tournaments.errors.result_locked');
    }

    expect($a->fresh()->winner_entry_id)->toBe($a->entry1_id)
        ->and(GameMatch::find($a->next_match_id)->status)->toBe(MatchStatus::Ready);
});

it('keeps the champion consistent when the final is corrected', function () {
    $tournament = decisionTournament(4);
    [$a, $b] = decisionRoundOne($tournament)->all();
    app(OverrideMatchResult::class)->handle($a, 1, 0, 'a');
    app(OverrideMatchResult::class)->handle($b, 1, 0, 'b');
    $final = GameMatch::find($a->next_match_id);
    app(OverrideMatchResult::class)->handle($final, 3, 1, 'final');

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->toBe($final->entry1_id);

    app(OverrideMatchResult::class)->handle($final->fresh(), 0, 3, 'wrong confirmation');

    expect($tournament->fresh()->winner_entry_id)->toBe($final->entry2_id)
        ->and($tournament->fresh()->status)->toBe(TournamentStatus::Finished);
});

it('offers the decision action on the match list for ready matches only', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    $pending = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->firstOrFail();

    Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
        ->assertTableActionVisible('decide', $match)
        ->assertTableActionHidden('decide', $pending)
        ->callAction(TestAction::make('decide')->table($match), data: ['score1' => 2, 'score2' => 1, 'reason' => 'No show'])
        ->assertNotified();

    expect($match->fresh()->status)->toBe(MatchStatus::Completed);
});

it('requires a reason in the decision form', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();

    Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
        ->callAction(TestAction::make('decide')->table($match), data: ['score1' => 2, 'score2' => 1, 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
});

it('does not decide a round robin tie silently and lets the orga name the winner', function () {
    $tournament = decisionTournament(3, TournamentFormat::RoundRobin);
    // perfect cycle: every entry wins exactly one match
    playCycle($tournament);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Live)
        ->and($tournament->winner_entry_id)->toBeNull();

    $chosen = TournamentEntry::where('tournament_id', $tournament->id)->orderBy('id')->first();
    app(SetTournamentWinner::class)->handle($tournament, $chosen->id);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->fresh()->winner_entry_id)->toBe($chosen->id);
});

it('guards confirm and dispute against a report resolved in the meantime', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $report = app(SubmitMatchReport::class)->handle($match, $entry1, 2, 0);
    $staleForConfirm = MatchReport::find($report->id);

    app(DisputeMatchReport::class)->handle($report, $entry2);

    // The confirm call still holds a report loaded while it was pending.
    expect(fn () => app(ConfirmMatchReport::class)->handle($staleForConfirm, $entry2, 99))
        ->toThrow(TournamentException::class);
    expect($match->fresh()->status)->toBe(MatchStatus::Disputed)
        ->and($match->fresh()->winner_entry_id)->toBeNull();
});

it('does not leave a disputed match behind a confirmed result', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $report = app(SubmitMatchReport::class)->handle($match, $entry1, 2, 0);
    $staleForDispute = MatchReport::find($report->id);

    app(ConfirmMatchReport::class)->handle($report, $entry2, $match->fresh()->lock_version);

    expect(fn () => app(DisputeMatchReport::class)->handle($staleForDispute, $entry2))
        ->toThrow(TournamentException::class);
    expect($match->fresh()->status)->toBe(MatchStatus::Completed);
});

it('refuses a second report with the existing report still the only one', function () {
    $tournament = decisionTournament(4);
    $match = decisionRoundOne($tournament)->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    app(SubmitMatchReport::class)->handle($match, $entry1, 2, 0);

    // The second reporter still holds the match as it was while ready.
    try {
        app(SubmitMatchReport::class)->handle($match, $entry2, 0, 2);
        $this->fail('expected exception');
    } catch (TournamentException $e) {
        expect($e->translationKey)->toBe('tournaments.errors.match_already_reported');
    }

    expect(MatchReport::where('match_id', $match->id)->count())->toBe(1);
});

it('shows shared ranks and the tie hint copy for a tied round robin', function () {
    $tournament = decisionTournament(3, TournamentFormat::RoundRobin);
    playCycle($tournament);

    $this->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('standings.0.rank', 1)->where('standings.1.rank', 1)->where('standings.2.rank', 1)
            ->where('tournament.winnerEntryId', null)
            ->where('standingsLabels.tie_pending', 'Gleichstand – Orga entscheidet.'));

    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
        ->assertActionVisible('setWinner');
});

/** Three entries a < b < c play a perfect cycle: a beats b, b beats c, c beats a. */
function playCycle(Tournament $tournament): void
{
    [$a, $b, $c] = TournamentEntry::where('tournament_id', $tournament->id)->orderBy('id')->pluck('id')->all();
    $beats = [$a => $b, $b => $c, $c => $a];

    foreach (GameMatch::where('tournament_id', $tournament->id)->get() as $m) {
        $entry1Wins = $beats[$m->entry1_id] === $m->entry2_id;
        app(OverrideMatchResult::class)->handle($m, $entry1Wins ? 1 : 0, $entry1Wins ? 0 : 1, 'cycle');
    }
}
