<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Events\MatchReportUpdated;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EntryOwner;
use Illuminate\Support\Facades\DB;

/**
 * The reporting side takes back its own still-pending result claim
 * (`Reported` -> `Ready`). The report row is kept with status `Withdrawn` (an
 * audit trail, and it lets a late confirm/dispute by the opponent be answered
 * with a friendly message instead of a 404).
 *
 * Uses the same optimistic `lock_version` as {@see ConfirmMatchReport} and
 * bumps it: an opponent whose confirm raced the withdrawal (or a following
 * correction) therefore fails with {@see StaleMatchException} instead of
 * confirming a claim that no longer exists.
 *
 * Identity guard (in the Action, not only the Policy): the caller must be a
 * participant AND the entry that submitted the report.
 */
class WithdrawMatchReport
{
    public function handle(MatchReport $report, TournamentEntry $withdrawer, int $lockVersion): GameMatch
    {
        return DB::transaction(function () use ($report, $withdrawer, $lockVersion): GameMatch {
            $match = $this->withdrawQuietly($report, $withdrawer, $lockVersion);

            MatchReportUpdated::dispatch($match);

            return $match;
        });
    }

    /**
     * The state change without the broadcast, for {@see CorrectMatchReport},
     * which broadcasts once for the withdraw + resubmit pair.
     */
    public function withdrawQuietly(MatchReport $report, TournamentEntry $withdrawer, int $lockVersion): GameMatch
    {
        $match = GameMatch::query()->whereKey($report->match_id)->lockForUpdate()->firstOrFail();

        if ($withdrawer->id !== $match->entry1_id && $withdrawer->id !== $match->entry2_id) {
            throw TournamentException::notAParticipant();
        }

        if (EntryOwner::userId($withdrawer) !== $report->reported_by) {
            throw TournamentException::cannotWithdrawOthersReport();
        }

        // Re-read under the match lock: a concurrent confirm/dispute/withdraw
        // may have resolved the report since the caller loaded it.
        $report = MatchReport::query()->whereKey($report->getKey())->firstOrFail();

        if ($report->status !== ReportStatus::Pending || $match->status !== MatchStatus::Reported) {
            throw TournamentException::reportNotPending();
        }

        // A match that was warmed up (`warmup_started_at` is stamped once and
        // never cleared) can only have reached Reported through Live, so the
        // withdrawal puts it back where it was.
        $restored = $match->warmup_started_at !== null ? MatchStatus::Live : MatchStatus::Ready;

        $affected = GameMatch::query()
            ->whereKey($match->id)
            ->where('lock_version', $lockVersion)
            ->update([
                'status' => $restored->value,
                'lock_version' => $lockVersion + 1,
            ]);

        if ($affected === 0) {
            throw new StaleMatchException;
        }

        $report->status = ReportStatus::Withdrawn;
        $report->save();

        return $match->refresh();
    }
}
