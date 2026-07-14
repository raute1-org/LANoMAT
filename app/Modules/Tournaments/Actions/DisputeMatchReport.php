<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\TournamentEntry;

/**
 * The opponent disputes a submitted report instead of confirming it — e.g.
 * the reported score is wrong. Moves the report and its match to `Disputed`,
 * which routes it into the orga queue for manual resolution via
 * {@see OverrideMatchResult}.
 */
class DisputeMatchReport
{
    public function handle(MatchReport $report, TournamentEntry $disputer): MatchReport
    {
        $report->status = ReportStatus::Disputed;
        $report->save();

        $match = GameMatch::query()->findOrFail($report->match_id);
        $match->status = MatchStatus::Disputed;
        $match->save();

        return $report;
    }
}
