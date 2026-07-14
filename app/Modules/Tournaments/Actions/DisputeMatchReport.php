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
 * The opponent disputes a submitted report instead of confirming it — e.g.
 * the reported score is wrong. Moves the report and its match to `Disputed`,
 * which routes it into the orga queue for manual resolution via
 * {@see OverrideMatchResult}.
 *
 * Identity guard: same rule as {@see ConfirmMatchReport} — the disputer
 * must be a participant of the match and must not be the entry that
 * submitted the report itself. Enforced here directly so the Action stands
 * on its own regardless of the calling layer.
 */
class DisputeMatchReport
{
    public function handle(MatchReport $report, TournamentEntry $disputer): MatchReport
    {
        $match = GameMatch::query()->findOrFail($report->match_id);

        $this->assertIsOpponent($match, $report, $disputer);

        // A report that is no longer Pending cannot be disputed again — most
        // importantly, a match that has already progressed past Reported
        // (e.g. Completed, via a confirm or orga override) must not be
        // flipped back to Disputed after the fact.
        if ($report->status !== ReportStatus::Pending) {
            throw TournamentException::reportNotPending();
        }

        if ($match->status !== MatchStatus::Reported) {
            throw TournamentException::matchNotDisputable();
        }

        $report->status = ReportStatus::Disputed;
        $report->save();

        $match->status = MatchStatus::Disputed;
        $match->save();

        return $report;
    }

    private function assertIsOpponent(GameMatch $match, MatchReport $report, TournamentEntry $disputer): void
    {
        if ($disputer->id !== $match->entry1_id && $disputer->id !== $match->entry2_id) {
            throw TournamentException::notAParticipant();
        }

        if (EntryOwner::userId($disputer) === $report->reported_by) {
            throw TournamentException::cannotConfirmOwnReport();
        }
    }
}
