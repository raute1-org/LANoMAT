<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\MatchProgression;
use Illuminate\Support\Facades\DB;

/**
 * The opponent confirms a submitted report. Uses an optimistic lock on
 * `GameMatch::$lock_version` to guard against a race with a concurrent
 * confirm/override: the caller must supply the `lock_version` it last read,
 * and the conditional `UPDATE ... WHERE lock_version = ?` only succeeds if
 * nobody else has changed the row since. Zero affected rows means the row
 * moved under the caller — throw {@see StaleMatchException} so the caller
 * can reload and retry rather than silently clobbering someone else's
 * update.
 *
 * On success, writes the score/winner onto the match, hands off to
 * {@see MatchProgression} to advance the bracket, and marks the report
 * `Confirmed`. All inside one transaction.
 */
class ConfirmMatchReport
{
    public function __construct(
        private readonly MatchProgression $progression,
    ) {}

    public function handle(MatchReport $report, TournamentEntry $confirmer, int $lockVersion): GameMatch
    {
        return DB::transaction(function () use ($report, $lockVersion): GameMatch {
            $match = GameMatch::query()->findOrFail($report->match_id);
            $score1 = $report->score1;
            $score2 = $report->score2;
            $winnerEntryId = $score1 > $score2 ? $match->entry1_id : $match->entry2_id;

            $affected = GameMatch::query()
                ->where('id', $match->id)
                ->where('lock_version', $lockVersion)
                ->update([
                    'score1' => $score1,
                    'score2' => $score2,
                    'winner_entry_id' => $winnerEntryId,
                    'status' => MatchStatus::Completed->value,
                    'lock_version' => $lockVersion + 1,
                ]);

            if ($affected === 0) {
                throw new StaleMatchException;
            }

            $match->refresh();

            $tournament = Tournament::query()->findOrFail($match->tournament_id);
            $this->progression->apply($tournament, $match, $score1, $score2);

            $report->status = ReportStatus::Confirmed;
            $report->save();

            $match->refresh();

            return $match;
        });
    }
}
