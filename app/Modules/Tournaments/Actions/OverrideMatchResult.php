<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Events\MatchReportUpdated;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchOverride;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use App\Modules\Tournaments\Support\MatchProgression;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An orga decides a match's result directly — bypassing the submit/confirm
 * handshake entirely. Works on every state where two entrants stand
 * (`Ready`, `Warmup`, `Reported`, `Disputed`: walkover/no-show, dispute
 * resolution) and on a `Completed` match (correction of a wrongly confirmed
 * result, Ruling S). A `Pending` match (entrants not known yet) and bye/dead
 * matches are refused with {@see TournamentException::matchNotDecidable()}.
 * Requires the `manage` ability on the match's tournament (see
 * {@see TournamentPolicy}).
 *
 * Open report claims are superseded (`Pending` -> `Withdrawn`; a `Disputed`
 * report stays as the record of the dispute) and every decision leaves a
 * {@see MatchOverride} audit row with the reason.
 *
 * A first decision runs the same bracket progression as
 * {@see ConfirmMatchReport}. Correcting a `Completed` match goes through
 * {@see MatchProgression::revise()}, which swaps the entrants in the follow-up
 * matches while they are still untouched, keeps the champion consistent, and
 * otherwise refuses with {@see TournamentException::resultLocked()}.
 */
class OverrideMatchResult
{
    public function __construct(
        private readonly MatchProgression $progression,
    ) {}

    public function handle(GameMatch $match, int $score1, int $score2, ?string $reason = null): GameMatch
    {
        $tournament = Tournament::query()->findOrFail($match->tournament_id);

        Gate::authorize('manage', $tournament);

        // Backstop: the orga override form should already reject ties
        // client-side, but guard here too so a tied override surfaces as a
        // handled TournamentException instead of the domain's raw
        // InvalidArgumentException (a 500) once it reaches
        // MatchProgression/BracketProgressor below.
        if ($score1 === $score2) {
            throw TournamentException::tiedScore();
        }

        return DB::transaction(function () use ($match, $tournament, $score1, $score2, $reason): GameMatch {
            $match = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $tournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($match->status === MatchStatus::Pending || $match->entry1_id === null || $match->entry2_id === null) {
                throw TournamentException::matchNotDecidable();
            }

            $wasCompleted = $match->status === MatchStatus::Completed;

            $audit = new MatchOverride;
            $audit->forceFill([
                'match_id' => $match->id,
                'user_id' => Auth::id(),
                'previous_status' => $match->status->value,
                'previous_score1' => $match->score1,
                'previous_score2' => $match->score2,
                'previous_winner_entry_id' => $match->winner_entry_id,
                'score1' => $score1,
                'score2' => $score2,
                'reason' => $reason,
            ])->save();

            MatchReport::query()
                ->where('match_id', $match->id)
                ->where('status', ReportStatus::Pending->value)
                ->update(['status' => ReportStatus::Withdrawn->value]);

            if ($wasCompleted) {
                $this->progression->revise($tournament, $match, $score1, $score2);
                MatchReportUpdated::dispatch($match->refresh());

                return $match;
            }

            $winnerEntryId = $score1 > $score2 ? $match->entry1_id : $match->entry2_id;

            $match->score1 = $score1;
            $match->score2 = $score2;
            $match->winner_entry_id = $winnerEntryId;
            $match->status = MatchStatus::Completed;
            $match->lock_version++;
            $match->save();

            $this->progression->apply($tournament, $match, $score1, $score2);

            $match->refresh();

            return $match;
        });
    }
}
