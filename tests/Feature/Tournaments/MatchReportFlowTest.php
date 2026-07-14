<?php

use App\Models\User;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Most tests here run the report/confirm/override flow without scoping
    // Event::fake(), so TournamentStarted/MatchReady/MatchCompleted/
    // TournamentCompleted dispatch for real and reach both the Discord
    // match-channel listener (Task 18) and the voice-provisioning listener
    // (Task 21) — fake both clients globally so this bracket-progression
    // suite never hits a real server.
    fakeDiscord();
    fakeMumble();
});

/**
 * Submits and confirms a report for `$match` (score1-score2), returning the
 * confirmed `GameMatch`. Used by the double-elimination grand-final tests to
 * play a whole bracket through concisely.
 */
function playAndConfirm(GameMatch $match, int $score1, int $score2): GameMatch
{
    $reporter = TournamentEntry::find($match->entry1_id);
    $opponent = TournamentEntry::find($match->entry2_id);
    $report = app(SubmitMatchReport::class)->handle($match, $reporter, $score1, $score2);

    return app(ConfirmMatchReport::class)->handle($report, $opponent, $match->fresh()->lock_version);
}

/**
 * Builds and starts a 4-entry single-elimination tournament, returning the
 * tournament plus its two round-1 matches (ordered by position).
 *
 * @return array{0: Tournament, 1: Collection<int, GameMatch>}
 */
function startFourEntrySingleElim(): array
{
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    $started = app(StartTournament::class)->handle($tournament);

    $round1 = GameMatch::where('tournament_id', $started->id)->where('round', 1)->orderBy('position')->get();

    return [$started, $round1];
}

it('creates a pending report when submitting at Ready status', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);

    expect($report)->toBeInstanceOf(MatchReport::class)
        ->and($report->status)->toBe(ReportStatus::Pending)
        ->and($report->match_id)->toBe($match->id)
        ->and($report->score1)->toBe(2)
        ->and($report->score2)->toBe(1);

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('rejects submitting a report when the match is not Ready', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);

    app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);

    expect(fn () => app(SubmitMatchReport::class)->handle($match->fresh(), $reporter, 2, 1))
        ->toThrow(DomainException::class);
});

it('confirms a report with the correct lock_version, writes the winner, advances the bracket and eliminates the loser', function () {
    Event::fake([MatchReady::class, MatchCompleted::class, TournamentCompleted::class]);

    [$tournament, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);
    $opponent = TournamentEntry::find($match->entry2_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);
    $lockVersion = $match->fresh()->lock_version;

    $confirmed = app(ConfirmMatchReport::class)->handle($report, $opponent, $lockVersion);

    expect($confirmed->status)->toBe(MatchStatus::Completed)
        ->and($confirmed->score1)->toBe(2)
        ->and($confirmed->score2)->toBe(1)
        ->and($confirmed->winner_entry_id)->toBe($match->entry1_id)
        ->and($confirmed->lock_version)->toBe($lockVersion + 1);

    expect($report->fresh()->status)->toBe(ReportStatus::Confirmed);

    // Winner must be seeded into the next round's match, which is now Ready.
    $nextMatch = GameMatch::find($confirmed->next_match_id);
    expect($nextMatch)->not->toBeNull();
    expect([$nextMatch->entry1_id, $nextMatch->entry2_id])->toContain($match->entry1_id);

    Event::assertDispatched(MatchCompleted::class, fn (MatchCompleted $event) => $event->match->is($confirmed));

    // The tournament is not finished yet — only one round-1 match has been played.
    Event::assertNotDispatched(TournamentCompleted::class);
});

it('dispatches MatchReady when a downstream match becomes playable', function () {
    Event::fake([MatchReady::class, MatchCompleted::class, TournamentCompleted::class]);

    [$tournament, $round1] = startFourEntrySingleElim();
    [$matchA, $matchB] = [$round1->get(0), $round1->get(1)];

    $final = GameMatch::find($matchA->next_match_id);

    // startFourEntrySingleElim() itself already dispatched MatchReady for
    // matchA and matchB (Fix #1's round-1 dispatch, captured by the
    // Event::fake() above) — clear those out so the assertions below are
    // scoped to what happens as a *result of* the report/confirm flow.
    Event::assertDispatchedTimes(MatchReady::class, 2);

    $reportA = app(SubmitMatchReport::class)->handle($matchA, TournamentEntry::find($matchA->entry1_id), 2, 0);
    app(ConfirmMatchReport::class)->handle($reportA, TournamentEntry::find($matchA->entry2_id), $matchA->fresh()->lock_version);

    // After only match A is decided, the final is not yet playable (one slot
    // still pending) — no *additional* MatchReady beyond the two round-1
    // dispatches above, and specifically none for the final.
    Event::assertDispatchedTimes(MatchReady::class, 2);
    Event::assertNotDispatched(MatchReady::class, fn (MatchReady $event) => $event->match->is($final));

    $reportB = app(SubmitMatchReport::class)->handle($matchB->fresh(), TournamentEntry::find($matchB->entry1_id), 1, 3);
    $confirmedB = app(ConfirmMatchReport::class)->handle($reportB, TournamentEntry::find($matchB->entry2_id), $matchB->fresh()->lock_version);

    Event::assertDispatched(MatchReady::class, fn (MatchReady $event) => $event->match->is($final->fresh()));
    expect($final->fresh()->status)->toBe(MatchStatus::Ready);
});

it('throws StaleMatchException when confirming with an outdated lock_version', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);
    $opponent = TournamentEntry::find($match->entry2_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);
    $staleLockVersion = $match->lock_version - 1;

    expect(fn () => app(ConfirmMatchReport::class)->handle($report, $opponent, 999))
        ->toThrow(StaleMatchException::class);

    // Match must remain untouched.
    expect($match->fresh()->status)->toBe(MatchStatus::Reported)
        ->and($match->fresh()->winner_entry_id)->toBeNull();

    expect($staleLockVersion)->toBeLessThan($match->lock_version + 1);
});

it('sets the match and report to Disputed when the opponent disputes a report', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);
    $opponent = TournamentEntry::find($match->entry2_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);

    $disputed = app(DisputeMatchReport::class)->handle($report, $opponent);

    expect($disputed->status)->toBe(ReportStatus::Disputed)
        ->and($match->fresh()->status)->toBe(MatchStatus::Disputed);
});

it('rejects the reporter confirming their own report', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);
    $lockVersion = $match->fresh()->lock_version;

    expect(fn () => app(ConfirmMatchReport::class)->handle($report, $reporter, $lockVersion))
        ->toThrow(TournamentException::class);

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('rejects an unrelated entry confirming a report', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);
    $stranger = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $match->tournament_id]);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);
    $lockVersion = $match->fresh()->lock_version;

    expect(fn () => app(ConfirmMatchReport::class)->handle($report, $stranger, $lockVersion))
        ->toThrow(TournamentException::class);

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('rejects the reporter disputing their own report', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);

    expect(fn () => app(DisputeMatchReport::class)->handle($report, $reporter))
        ->toThrow(TournamentException::class);

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('rejects an unrelated entry disputing a report', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();
    $reporter = TournamentEntry::find($match->entry1_id);
    $stranger = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $match->tournament_id]);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 1);

    expect(fn () => app(DisputeMatchReport::class)->handle($report, $stranger))
        ->toThrow(TournamentException::class);

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('allows an orga to override a match result, bypassing confirmation', function () {
    Event::fake([MatchReady::class, MatchCompleted::class, TournamentCompleted::class]);

    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();

    $orga = User::factory()->orga()->create();
    test()->actingAs($orga);

    $overridden = app(OverrideMatchResult::class)->handle($match, 3, 1);

    expect($overridden->status)->toBe(MatchStatus::Completed)
        ->and($overridden->winner_entry_id)->toBe($match->entry1_id);

    Event::assertDispatched(MatchCompleted::class);
});

it('rejects an override from a non-orga user', function () {
    [, $round1] = startFourEntrySingleElim();
    $match = $round1->first();

    $stranger = User::factory()->create();
    test()->actingAs($stranger);

    expect(fn () => app(OverrideMatchResult::class)->handle($match, 3, 1))
        ->toThrow(AuthorizationException::class);
});

it('completes the tournament and sets the champion once the final is decided', function () {
    Event::fake([MatchReady::class, MatchCompleted::class, TournamentCompleted::class]);

    [$tournament, $round1] = startFourEntrySingleElim();
    [$matchA, $matchB] = [$round1->get(0), $round1->get(1)];

    $reportA = app(SubmitMatchReport::class)->handle($matchA, TournamentEntry::find($matchA->entry1_id), 2, 0);
    $confirmedA = app(ConfirmMatchReport::class)->handle($reportA, TournamentEntry::find($matchA->entry2_id), $matchA->fresh()->lock_version);
    $championId = $confirmedA->winner_entry_id;

    $reportB = app(SubmitMatchReport::class)->handle($matchB->fresh(), TournamentEntry::find($matchB->entry1_id), 1, 3);
    $confirmedB = app(ConfirmMatchReport::class)->handle($reportB, TournamentEntry::find($matchB->entry2_id), $matchB->fresh()->lock_version);

    $final = GameMatch::find($confirmedA->next_match_id)->fresh();
    $finalReporter = TournamentEntry::find($final->entry1_id);
    $finalOpponent = TournamentEntry::find($final->entry2_id);

    $finalWinnerId = $championId; // whichever slot championId sits in wins.
    [$s1, $s2] = $final->entry1_id === $finalWinnerId ? [2, 0] : [0, 2];

    $finalReport = app(SubmitMatchReport::class)->handle($final, $finalReporter, $s1, $s2);
    $finalConfirmed = app(ConfirmMatchReport::class)->handle($finalReport, $finalOpponent, $final->lock_version);

    expect($finalConfirmed->status)->toBe(MatchStatus::Completed)
        ->and($finalConfirmed->winner_entry_id)->toBe($finalWinnerId);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->toBe($finalWinnerId);

    Event::assertDispatched(TournamentCompleted::class, fn (TournamentCompleted $event) => $event->tournament->is($tournament));
});

it('does not finish the tournament when the losers-bracket side wins GF1 and arms the reset, only after GF2', function () {
    Event::fake([MatchReady::class, MatchCompleted::class, TournamentCompleted::class]);

    $tournament = Tournament::factory()->checkIn()->doubleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);
    $started = app(StartTournament::class)->handle($tournament);

    $wbR1 = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Winners->value)->where('round', 1)->orderBy('position')->get();
    playAndConfirm($wbR1->get(0), 2, 0);
    playAndConfirm($wbR1->get(1)->fresh(), 2, 0);

    $wbFinal = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Winners->value)->where('round', 2)->first();
    $wbChampionId = playAndConfirm($wbFinal->fresh(), 2, 0)->winner_entry_id;

    $lbR1 = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Losers->value)->where('round', 1)->first();
    playAndConfirm($lbR1->fresh(), 2, 0);

    $lbFinal = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Losers->value)->orderByDesc('round')->first();
    $lbFinalFresh = $lbFinal->fresh();
    // Slot 2 wins the LB final, becoming the losers-bracket champion.
    playAndConfirm($lbFinalFresh, 0, 2);
    $lbChampionId = $lbFinalFresh->entry2_id;

    $gf1 = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Finals->value)->orderBy('round')->first();
    $gf1Fresh = $gf1->fresh();
    expect($gf1Fresh->status)->toBe(MatchStatus::Ready);

    // Force the losers-bracket champion (slot holding $lbChampionId) to win GF1.
    [$s1, $s2] = $gf1Fresh->entry1_id === $lbChampionId ? [2, 0] : [0, 2];
    $gf1Confirmed = playAndConfirm($gf1Fresh, $s1, $s2);

    expect($gf1Confirmed->status)->toBe(MatchStatus::Completed)
        ->and($gf1Confirmed->winner_entry_id)->toBe($lbChampionId);

    // The tournament must NOT be finished yet — the reset (GF2) is armed and still to be played.
    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Live)
        ->and($tournament->winner_entry_id)->toBeNull();
    Event::assertNotDispatched(TournamentCompleted::class);

    $gf2 = GameMatch::find($gf1Confirmed->next_match_id)->fresh();
    expect($gf2->status)->toBe(MatchStatus::Ready)
        ->and([$gf2->entry1_id, $gf2->entry2_id])->toEqualCanonicalizing([$wbChampionId, $lbChampionId]);

    // Winners-bracket finalist beats the losers-bracket champion in the reset.
    [$s1, $s2] = $gf2->entry1_id === $wbChampionId ? [2, 0] : [0, 2];
    $gf2Confirmed = playAndConfirm($gf2, $s1, $s2);

    expect($gf2Confirmed->winner_entry_id)->toBe($wbChampionId);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->toBe($wbChampionId);

    Event::assertDispatched(TournamentCompleted::class, fn (TournamentCompleted $event) => $event->tournament->is($tournament));
});

it('finishes the tournament at GF1 when the winners-bracket side wins outright (no reset needed)', function () {
    Event::fake([MatchReady::class, MatchCompleted::class, TournamentCompleted::class]);

    $tournament = Tournament::factory()->checkIn()->doubleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);
    $started = app(StartTournament::class)->handle($tournament);

    $wbR1 = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Winners->value)->where('round', 1)->orderBy('position')->get();
    playAndConfirm($wbR1->get(0), 2, 0);
    playAndConfirm($wbR1->get(1)->fresh(), 2, 0);

    $wbFinal = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Winners->value)->where('round', 2)->first();
    $wbChampionId = playAndConfirm($wbFinal->fresh(), 2, 0)->winner_entry_id;

    $lbR1 = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Losers->value)->where('round', 1)->first();
    playAndConfirm($lbR1->fresh(), 2, 0);

    $lbFinal = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Losers->value)->orderByDesc('round')->first();
    playAndConfirm($lbFinal->fresh(), 2, 0);

    $gf1 = GameMatch::where('tournament_id', $started->id)->where('bracket', Bracket::Finals->value)->orderBy('round')->first();
    $gf1Fresh = $gf1->fresh();

    // The winners-bracket champion wins GF1 outright.
    [$s1, $s2] = $gf1Fresh->entry1_id === $wbChampionId ? [2, 0] : [0, 2];
    $gf1Confirmed = playAndConfirm($gf1Fresh, $s1, $s2);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->toBe($wbChampionId);

    // The reset (GF2) is left dead — never armed, never playable.
    $gf2 = GameMatch::find($gf1Confirmed->next_match_id)->fresh();
    expect($gf2->status)->toBe(MatchStatus::Pending)
        ->and($gf2->entry1_id)->toBeNull()
        ->and($gf2->entry2_id)->toBeNull();

    Event::assertDispatched(TournamentCompleted::class, fn (TournamentCompleted $event) => $event->tournament->is($tournament));
});

it('resolves German copy for match status and dispute labels', function () {
    app()->setLocale('de');

    expect(__('tournaments.match_status.disputed'))->toBe('Strittig')
        ->and(__('tournaments.report.submitted'))->not->toBeNull()
        ->and(__('tournaments.report.disputed'))->not->toBeNull();
});
