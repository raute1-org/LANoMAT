<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Events\MatchReportUpdated;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

/** @return array{0: Tournament, 1: GameMatch} */
function reportPerspectiveMatch(int $entries = 4): array
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count($entries)->create(['tournament_id' => $tournament->id]);
    $tournament = app(StartTournament::class)->handle($tournament)->fresh();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();

    return [$tournament, $match];
}

// F-S4-1: the form's "own : opponent" must be mapped to entry1/entry2 by the reporter's side.
it('credits the reported scores to the reporter regardless of which side they are', function (int $side, int $own, int $opp) {
    [, $match] = reportPerspectiveMatch();
    $reporter = TournamentEntry::find($side === 1 ? $match->entry1_id : $match->entry2_id);
    $opponent = TournamentEntry::find($side === 1 ? $match->entry2_id : $match->entry1_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, $own, $opp);

    expect($report->score1)->toBe($side === 1 ? $own : $opp)
        ->and($report->score2)->toBe($side === 1 ? $opp : $own);

    $confirmed = app(ConfirmMatchReport::class)->handle($report, $opponent, $match->fresh()->lock_version);

    // The reporter claimed to win when own > opp: the winner must follow that claim.
    expect($confirmed->winner_entry_id)->toBe($own > $opp ? $reporter->id : $opponent->id)
        ->and($confirmed->score1)->toBe($side === 1 ? $own : $opp)
        ->and($confirmed->score2)->toBe($side === 1 ? $opp : $own);
})->with([
    'entry 1 wins 2:0' => [1, 2, 0],
    'entry 1 loses 0:2' => [1, 0, 2],
    'entry 2 wins 2:0' => [2, 2, 0],
    'entry 2 loses 1:3' => [2, 1, 3],
    'entry 2 wins 16:14' => [2, 16, 14],
]);

it('rejects ties in the action for either side', function (int $side) {
    [, $match] = reportPerspectiveMatch();
    $reporter = TournamentEntry::find($side === 1 ? $match->entry1_id : $match->entry2_id);
    $opponent = TournamentEntry::find($side === 1 ? $match->entry2_id : $match->entry1_id);

    $report = app(SubmitMatchReport::class)->handle($match, $reporter, 1, 1);

    expect(fn () => app(ConfirmMatchReport::class)->handle($report, $opponent, $match->fresh()->lock_version))
        ->toThrow(TournamentException::class);
})->with([1, 2]);

it('maps the HTTP form for an entry-2 reporter and crowns the correct winner on confirm', function () {
    [, $match] = reportPerspectiveMatch();
    $entry2 = TournamentEntry::find($match->entry2_id);
    $entry1 = TournamentEntry::find($match->entry1_id);
    $reporter = User::find($entry2->user_id);
    $opponent = User::find($entry1->user_id);

    // "Ergebnis (du)" = 2, "Ergebnis (Gegner)" = 0
    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 0])->assertRedirect();

    $report = MatchReport::where('match_id', $match->id)->firstOrFail();
    expect([$report->score1, $report->score2])->toBe([0, 2]);

    $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])->assertRedirect();

    expect($match->fresh()->winner_entry_id)->toBe($entry2->id);
});

// F-S4-2: both participants see the pending report from their own perspective.
it('exposes the pending report to both participants from their own perspective', function () {
    [$tournament, $match] = reportPerspectiveMatch();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry2->user_id);
    $opponent = User::find($entry1->user_id);

    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 0]);

    $this->actingAs($reporter)->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("pendingReports.{$match->id}.reportedByMe", true)
            ->where("pendingReports.{$match->id}.ownScore", 2)
            ->where("pendingReports.{$match->id}.opponentScore", 0)
            ->where("pendingReports.{$match->id}.opponentName", $entry1->display_name)
        );

    $this->actingAs($opponent)->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("pendingReports.{$match->id}.reportedByMe", false)
            ->where("pendingReports.{$match->id}.ownScore", 0)
            ->where("pendingReports.{$match->id}.opponentScore", 2)
            ->where("pendingReports.{$match->id}.reporterName", $entry2->display_name)
            ->has("pendingReports.{$match->id}.reportedAt")
        );
});

it('does not expose pending reports to strangers or guests', function () {
    [$tournament, $match] = reportPerspectiveMatch();
    $reporter = User::find(TournamentEntry::find($match->entry1_id)->user_id);
    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 0]);

    $this->actingAs(User::factory()->create())->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pendingReports', []));
});

// F-S4-13: a stale second confirm click must not render a raw 404.
it('redirects with a friendly flash on a repeated confirm', function () {
    [, $match] = reportPerspectiveMatch();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $this->actingAs(User::find($entry1->user_id))->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 0]);
    $opponent = User::find($entry2->user_id);
    $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])->assertRedirect();

    $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertRedirect()
        ->assertSessionMissing('errors');
});

it('still answers 404 for confirm when no report ever existed', function () {
    [, $match] = reportPerspectiveMatch();
    $user = User::find(TournamentEntry::find($match->entry2_id)->user_id);

    $this->actingAs($user)->post("/matches/{$match->id}/confirm", ['lock_version' => 0])->assertNotFound();
});

// F-S6-2: submitting / disputing a report is pushed to the tournament channel (ids only).
it('broadcasts a report change on the tournament channel without PII', function () {
    Illuminate\Support\Facades\Event::fake([MatchReportUpdated::class]);
    [$tournament, $match] = reportPerspectiveMatch();
    $reporter = TournamentEntry::find($match->entry1_id);

    app(SubmitMatchReport::class)->handle($match, $reporter, 2, 0);

    Illuminate\Support\Facades\Event::assertDispatched(
        MatchReportUpdated::class,
        function (MatchReportUpdated $event) use ($tournament, $match): bool {
            return $event->broadcastOn()->name === 'tournament.'.$tournament->id
                && $event->broadcastAs() === 'match.report_updated'
                && $event->broadcastWith() === [
                    'tournament_id' => $tournament->id,
                    'match_id' => $match->id,
                    'status' => 'reported',
                ];
        },
    );
});

it('broadcasts a report change when the opponent disputes', function () {
    [, $match] = reportPerspectiveMatch();
    $report = app(SubmitMatchReport::class)->handle($match, TournamentEntry::find($match->entry1_id), 2, 0);
    Illuminate\Support\Facades\Event::fake([MatchReportUpdated::class]);

    app(DisputeMatchReport::class)->handle($report, TournamentEntry::find($match->entry2_id));

    Illuminate\Support\Facades\Event::assertDispatched(
        MatchReportUpdated::class,
        fn ($event): bool => $event->broadcastWith()['status'] === 'disputed',
    );
});

// F-S4-3: the reporter cannot confirm or dispute their own report (server side stays 403).
it('keeps forbidding the reporter from confirming or disputing their own report', function () {
    [, $match] = reportPerspectiveMatch();
    $reporter = User::find(TournamentEntry::find($match->entry2_id)->user_id);
    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 0]);

    $this->actingAs($reporter)->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])->assertForbidden();
    $this->actingAs($reporter)->post("/matches/{$match->id}/dispute")->assertForbidden();
});

// F-S4-13: helper "Go" confirms with a toast.
it('flashes a success toast when a helper sends a warmup match live', function () {
    [, $match] = reportPerspectiveMatch();
    $match->status = MatchStatus::Warmup;
    $match->save();
    $helper = User::factory()->create(['role' => Role::Helper]);

    $response = $this->actingAs($helper)->post("/matches/{$match->id}/go")->assertRedirect();

    expect($match->fresh()->status)->toBe(MatchStatus::Ready);
    $response->assertSessionHas('inertia.flash_data.toast.message', trans('tournaments.warmup.went_live'));
});
