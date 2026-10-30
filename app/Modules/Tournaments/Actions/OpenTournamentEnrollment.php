<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Facades\DB;

/**
 * Draft -> Enrollment. The orga-facing counterpart to the participant-side
 * enrollment: participants can only enroll in `enrollment`, so this is the
 * one code path that publishes a drafted tournament for sign-ups.
 *
 * Validates that everything participants and the bracket engine rely on is
 * configured before opening (game, entry limit, start, and — unless check-in
 * is switched off via `settings['require_checkin']` — a sane check-in
 * window). Locks the row like {@see StartTournament}.
 */
class OpenTournamentEnrollment
{
    public function handle(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if ($tournament->status !== TournamentStatus::Draft) {
                throw TournamentException::notDraft();
            }

            $missing = $this->missingFields($tournament);

            if ($missing !== []) {
                throw TournamentException::incompleteSetup($missing);
            }

            $tournament->status = TournamentStatus::Enrollment;
            $tournament->save();

            return $tournament;
        });
    }

    /** @return list<string> German labels of the unset required fields. */
    private function missingFields(Tournament $tournament): array
    {
        $missing = [];

        if ($tournament->game_id === null) {
            $missing[] = __('tournaments.fields.game');
        }

        if ($tournament->max_entries === null || $tournament->max_entries < 2) {
            $missing[] = __('tournaments.fields.max_entries');
        }

        // format/team_size/starts_at are non-nullable columns (and required in
        // the form), so they can never be missing here.

        if ($tournament->settings['require_checkin'] ?? true) {
            if ($tournament->checkin_opens_at === null) {
                $missing[] = __('tournaments.fields.checkin_opens_at');
            }

            if ($tournament->checkin_closes_at === null) {
                $missing[] = __('tournaments.fields.checkin_closes_at');
            } elseif ($tournament->checkin_opens_at !== null && $tournament->checkin_closes_at->lte($tournament->checkin_opens_at)) {
                $missing[] = __('tournaments.errors.checkin_window_order');
            }
        }

        return $missing;
    }
}
