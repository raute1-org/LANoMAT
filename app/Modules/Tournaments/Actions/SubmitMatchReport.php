<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Events\MatchReportUpdated;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EntryOwner;
use Illuminate\Support\Facades\DB;

/**
 * A participant reports the result of their match. Only allowed while the
 * match is `Ready` (both slots filled, nobody has reported yet). Creates a
 * `Pending` {@see MatchReport} and moves the match to `Reported`, awaiting
 * the opponent's confirmation via {@see ConfirmMatchReport}.
 *
 * Scores are passed from the reporter's perspective ("own : opponent", the
 * wording of the participant form) and stored entry-oriented
 * (`score1` = entry 1, `score2` = entry 2, like `GameMatch`), so a report by
 * the second entry credits the right side and the confirm path crowns the
 * right winner.
 */
class SubmitMatchReport
{
    public function handle(GameMatch $match, TournamentEntry $reporter, int $ownScore, int $opponentScore): MatchReport
    {
        $report = DB::transaction(function () use ($match, $reporter, $ownScore, $opponentScore): MatchReport {
            // Row lock + state check inside the transaction: two participants
            // submitting at the same instant serialize here, so only the
            // first creates a report; the second sees `Reported` and is told
            // to confirm or dispute the existing claim (F-R2A-3).
            $locked = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === MatchStatus::Reported) {
                throw TournamentException::matchAlreadyReported();
            }

            if ($locked->status !== MatchStatus::Ready) {
                throw TournamentException::matchNotReady();
            }

            $reporterIsEntry1 = $reporter->id === $locked->entry1_id;

            if (! $reporterIsEntry1 && $reporter->id !== $locked->entry2_id) {
                throw TournamentException::notAParticipant();
            }

            $report = new MatchReport([
                'match_id' => $locked->id,
                'reported_by' => EntryOwner::userId($reporter),
                'score1' => $reporterIsEntry1 ? $ownScore : $opponentScore,
                'score2' => $reporterIsEntry1 ? $opponentScore : $ownScore,
            ]);
            $report->status = ReportStatus::Pending;
            $report->save();

            $locked->status = MatchStatus::Reported;
            $locked->save();

            return $report;
        });

        MatchReportUpdated::dispatch($match->refresh());

        return $report;
    }
}
