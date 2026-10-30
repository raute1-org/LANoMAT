<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

/**
 * "Ergebnis ändern": withdraw the own pending report and submit the corrected
 * one in a single transaction (so the match is never observably `Ready` in
 * between and the opponent gets one broadcast). Scores are "own : opponent",
 * like {@see SubmitMatchReport}; all guards live in the two composed Actions.
 */
class CorrectMatchReport
{
    public function __construct(
        private readonly WithdrawMatchReport $withdraw,
        private readonly SubmitMatchReport $submit,
    ) {}

    public function handle(MatchReport $report, TournamentEntry $reporter, int $ownScore, int $opponentScore, int $lockVersion): MatchReport
    {
        return DB::transaction(function () use ($report, $reporter, $ownScore, $opponentScore, $lockVersion): MatchReport {
            $match = $this->withdraw->withdrawQuietly($report, $reporter, $lockVersion);

            // SubmitMatchReport dispatches MatchReportUpdated itself (once).
            return $this->submit->handle($match, $reporter, $ownScore, $opponentScore);
        });
    }
}
