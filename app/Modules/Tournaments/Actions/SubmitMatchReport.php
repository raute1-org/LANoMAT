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
        if ($match->status !== MatchStatus::Ready) {
            throw TournamentException::matchNotReady();
        }

        $reporterIsEntry1 = $reporter->id === $match->entry1_id;

        if (! $reporterIsEntry1 && $reporter->id !== $match->entry2_id) {
            throw TournamentException::notAParticipant();
        }

        $report = new MatchReport([
            'match_id' => $match->id,
            'reported_by' => EntryOwner::userId($reporter),
            'score1' => $reporterIsEntry1 ? $ownScore : $opponentScore,
            'score2' => $reporterIsEntry1 ? $opponentScore : $ownScore,
        ]);
        $report->status = ReportStatus::Pending;
        $report->save();

        $match->status = MatchStatus::Reported;
        $match->save();

        MatchReportUpdated::dispatch($match);

        return $report;
    }
}
