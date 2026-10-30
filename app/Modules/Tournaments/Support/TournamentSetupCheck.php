<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Tournaments\Models\Tournament;

/**
 * The "is this tournament configured well enough to go public" rule, shared by
 * the announce and open-enrollment transitions so both refuse identically.
 */
final class TournamentSetupCheck
{
    /**
     * Game, entry limit and — unless check-in is switched off via
     * `settings['require_checkin']` — a sane check-in window.
     * format/team_size/starts_at are non-nullable columns (and required in the
     * form), so they can never be missing here.
     *
     * @return list<string> German labels of the unset required fields.
     */
    public static function missingFields(Tournament $tournament): array
    {
        $missing = [];

        if ($tournament->game_id === null) {
            $missing[] = __('tournaments.fields.game');
        }

        if ($tournament->max_entries === null || $tournament->max_entries < 2) {
            $missing[] = __('tournaments.fields.max_entries');
        }

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
