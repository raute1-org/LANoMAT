<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Collection;

/**
 * Read model: the pending (not yet confirmed/disputed) result report of each
 * match the viewer takes part in, expressed from the VIEWER's perspective
 * ("own : opponent", the same wording as the report form) so both the
 * reporter and the opponent who has to confirm see the claimed result.
 *
 * Stored reports are entry-oriented (`score1` = entry 1); the orientation is
 * flipped here by the viewer's side. Never exposed to non-participants: the
 * public bracket DTO stays free of pending claims.
 */
class PendingReportView
{
    /**
     * @param  Collection<int, GameMatch>  $matches
     * @return array<int, array{reportedByMe: bool, ownScore: int, opponentScore: int, reporterName: string|null, opponentName: string|null, reportedAt: string|null}>
     */
    public static function forViewer(Collection $matches, ?TournamentEntry $myEntry): array
    {
        if ($myEntry === null) {
            return [];
        }

        $mine = $matches->filter(
            fn (GameMatch $match): bool => $match->status === MatchStatus::Reported
                && ($match->entry1_id === $myEntry->id || $match->entry2_id === $myEntry->id),
        );

        if ($mine->isEmpty()) {
            return [];
        }

        /** @var Collection<int, MatchReport> $reports */
        $reports = MatchReport::query()
            ->whereIn('match_id', $mine->pluck('id')->all())
            ->where('status', ReportStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->keyBy('match_id');

        $myUserId = EntryOwner::userId($myEntry);
        $result = [];

        foreach ($mine as $match) {
            $report = $reports->get($match->id);

            if ($report === null) {
                continue;
            }

            $iAmEntry1 = $match->entry1_id === $myEntry->id;
            $reportedByMe = $report->reported_by === $myUserId;
            $opponent = $iAmEntry1 ? $match->entry2 : $match->entry1;
            $reporter = $reportedByMe ? $myEntry : $opponent;

            $result[$match->id] = [
                'reportedByMe' => $reportedByMe,
                'ownScore' => $iAmEntry1 ? $report->score1 : $report->score2,
                'opponentScore' => $iAmEntry1 ? $report->score2 : $report->score1,
                'reporterName' => $reporter?->display_name,
                'opponentName' => $opponent?->display_name,
                'reportedAt' => $report->created_at?->toIso8601String(),
            ];
        }

        return $result;
    }
}
