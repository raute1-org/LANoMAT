<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\CorrectMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Actions\WithdrawMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Events\MatchReportUpdated;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

/** @return array{0: GameMatch, 1: TournamentEntry, 2: TournamentEntry} match, entry1, entry2 */
function withdrawableMatch(): array
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);
    $tournament = app(StartTournament::class)->handle($tournament)->fresh();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();

    return [$match, TournamentEntry::find($match->entry1_id), TournamentEntry::find($match->entry2_id)];
}

it('lets the reporter withdraw a pending report and puts the match back to ready', function () {
    [$match, $e1] = withdrawableMatch();
    $report = app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);
    EventFacade::fake([MatchReportUpdated::class]);

    $result = app(WithdrawMatchReport::class)->handle($report, $e1, $match->fresh()->lock_version);

    expect($result->status)->toBe(MatchStatus::Ready)
        ->and($report->fresh()->status)->toBe(ReportStatus::Withdrawn);
    EventFacade::assertDispatched(MatchReportUpdated::class);

    // And a fresh report can be submitted again.
    app(SubmitMatchReport::class)->handle($match->fresh(), $e1, 1, 3);
    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('refuses withdrawal by the opponent, a stranger, and for confirmed or disputed reports', function () {
    [$match, $e1, $e2] = withdrawableMatch();
    $report = app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);

    expect(fn () => app(WithdrawMatchReport::class)->handle($report, $e2, $match->fresh()->lock_version))
        ->toThrow(TournamentException::class);

    app(DisputeMatchReport::class)->handle($report, $e2);
    expect(fn () => app(WithdrawMatchReport::class)->handle($report->fresh(), $e1, $match->fresh()->lock_version))
        ->toThrow(TournamentException::class);
    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);

    [$match2, $f1, $f2] = withdrawableMatch();
    $report2 = app(SubmitMatchReport::class)->handle($match2, $f1, 2, 0);
    app(ConfirmMatchReport::class)->handle($report2, $f2, $match2->fresh()->lock_version);
    expect(fn () => app(WithdrawMatchReport::class)->handle($report2->fresh(), $f1, $match2->fresh()->lock_version))
        ->toThrow(TournamentException::class);
});

it('rejects a withdrawal with a stale lock version', function () {
    [$match, $e1] = withdrawableMatch();
    $report = app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);

    expect(fn () => app(WithdrawMatchReport::class)->handle($report, $e1, $match->fresh()->lock_version + 5))
        ->toThrow(StaleMatchException::class);
    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('corrects a report keeping the perspective mapping for either side', function (int $side) {
    [$match, $e1, $e2] = withdrawableMatch();
    $reporter = $side === 1 ? $e1 : $e2;
    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 2, 0);

    $new = app(CorrectMatchReport::class)->handle($report, $reporter, 1, 3, $match->fresh()->lock_version);

    expect($report->fresh()->status)->toBe(ReportStatus::Withdrawn)
        ->and($new->status)->toBe(ReportStatus::Pending)
        ->and([$new->score1, $new->score2])->toBe($side === 1 ? [1, 3] : [3, 1])
        ->and($match->fresh()->status)->toBe(MatchStatus::Reported);
    expect(MatchReport::where('match_id', $match->id)->where('status', ReportStatus::Pending->value)->count())->toBe(1);
})->with([1, 2]);

it('invalidates the opponent confirm of the old score after a correction (stale, not a 500)', function () {
    [$match, $e1, $e2] = withdrawableMatch();
    $report = app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);
    $oldLock = $match->fresh()->lock_version;

    app(CorrectMatchReport::class)->handle($report, $e1, 0, 2, $oldLock);

    $opponent = User::find($e2->user_id);
    $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $oldLock])
        ->assertRedirect();
    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('gives the opponent a friendly message when confirming after a withdrawal', function () {
    [$match, $e1, $e2] = withdrawableMatch();
    app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);
    $reporter = User::find($e1->user_id);
    $opponent = User::find($e2->user_id);

    $this->actingAs($reporter)->post("/matches/{$match->id}/report/withdraw", ['lock_version' => $match->fresh()->lock_version])
        ->assertRedirect();
    expect($match->fresh()->status)->toBe(MatchStatus::Ready);

    $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($match->fresh()->status)->toBe(MatchStatus::Ready);

    $this->actingAs($opponent)->post("/matches/{$match->id}/dispute")->assertRedirect();
    expect($match->fresh()->status)->toBe(MatchStatus::Ready);
});

it('only the reporter may withdraw or correct over HTTP', function () {
    [$match, $e1, $e2] = withdrawableMatch();
    app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);
    $opponent = User::find($e2->user_id);
    $stranger = User::factory()->create();
    $lock = $match->fresh()->lock_version;

    foreach ([$opponent, $stranger] as $user) {
        $this->actingAs($user)->post("/matches/{$match->id}/report/withdraw", ['lock_version' => $lock])->assertForbidden();
        $this->actingAs($user)->post("/matches/{$match->id}/report/correct", ['score1' => 0, 'score2' => 1, 'lock_version' => $lock])->assertForbidden();
    }

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('corrects over HTTP and the opponent page shows the corrected claim', function () {
    [$match, $e1, $e2] = withdrawableMatch();
    app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);
    $reporter = User::find($e1->user_id);
    $opponent = User::find($e2->user_id);

    $this->actingAs($reporter)->post("/matches/{$match->id}/report/correct", [
        'score1' => 3, 'score2' => 1, 'lock_version' => $match->fresh()->lock_version,
    ])->assertRedirect();

    $this->actingAs($opponent)->get("/tournaments/{$match->tournament_id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("pendingReports.{$match->id}.reportedByMe", false)
            ->where("pendingReports.{$match->id}.ownScore", 1)
            ->where("pendingReports.{$match->id}.opponentScore", 3));
});

it('rejects a tied correction', function () {
    [$match, $e1] = withdrawableMatch();
    app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);

    $this->actingAs(User::find($e1->user_id))->post("/matches/{$match->id}/report/correct", [
        'score1' => 2, 'score2' => 2, 'lock_version' => $match->fresh()->lock_version,
    ])->assertSessionHasErrors('score2');
    expect(MatchReport::where('match_id', $match->id)->where('status', ReportStatus::Pending->value)->count())->toBe(1);
});

it('keeps the orga override working on a withdrawn-and-ready match and on a pending report', function () {
    [$match, $e1] = withdrawableMatch();
    $report = app(SubmitMatchReport::class)->handle($match, $e1, 2, 0);
    app(WithdrawMatchReport::class)->handle($report, $e1, $match->fresh()->lock_version);

    $this->actingAs(User::factory()->orga()->create());
    $done = app(OverrideMatchResult::class)->handle($match->fresh(), 2, 1);

    expect($done->status)->toBe(MatchStatus::Completed);
});
