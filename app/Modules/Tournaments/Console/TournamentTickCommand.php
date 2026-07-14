<?php

namespace App\Modules\Tournaments\Console;

use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Console\Command;

class TournamentTickCommand extends Command
{
    protected $signature = 'lanomat:tournament-tick';

    protected $description = 'Open tournament check-in windows whose scheduled time has arrived.';

    /**
     * Note: closing the check-in window is time-gated inside `CheckInEntry`
     * itself (via `checkin_closes_at`), not a status transition — so there
     * is nothing to "close" here. The CheckIn -> Live transition is owned
     * exclusively by `StartTournament` (roadmap 3.11, not yet built), which
     * also generates and persists the bracket. This tick intentionally does
     * not autostart tournaments.
     */
    public function handle(OpenCheckin $openCheckin): int
    {
        $now = now();

        // Idempotent: only acts on tournaments whose current status still
        // matches the source state of the transition, so re-running the
        // tick (every minute) never double-fires.
        Tournament::query()
            ->where('status', TournamentStatus::Enrollment->value)
            ->whereNotNull('checkin_opens_at')
            ->where('checkin_opens_at', '<=', $now)
            ->each(function (Tournament $tournament) use ($openCheckin): void {
                $openCheckin->handle($tournament);
            });

        return self::SUCCESS;
    }
}
