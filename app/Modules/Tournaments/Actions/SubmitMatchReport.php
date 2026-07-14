<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
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
 */
class SubmitMatchReport
{
    public function handle(GameMatch $match, TournamentEntry $reporter, int $score1, int $score2): MatchReport
    {
        if ($match->status !== MatchStatus::Ready) {
            throw TournamentException::matchNotReady();
        }

        $report = new MatchReport([
            'match_id' => $match->id,
            'reported_by' => EntryOwner::userId($reporter),
            'score1' => $score1,
            'score2' => $score2,
        ]);
        $report->status = ReportStatus::Pending;
        $report->save();

        $match->status = MatchStatus::Reported;
        $match->save();

        return $report;
    }
}
