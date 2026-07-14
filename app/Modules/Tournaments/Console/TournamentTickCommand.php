<?php

namespace App\Modules\Tournaments\Console;

use App\Modules\Tournaments\Actions\CloseCheckin;
use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Console\Command;

class TournamentTickCommand extends Command
{
    protected $signature = 'lanomat:tournament-tick';

    protected $description = 'Open/close tournament check-in windows whose scheduled time has arrived.';

    public function handle(OpenCheckin $openCheckin, CloseCheckin $closeCheckin): int
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

        Tournament::query()
            ->where('status', TournamentStatus::CheckIn->value)
            ->whereNotNull('checkin_closes_at')
            ->where('checkin_closes_at', '<=', $now)
            ->each(function (Tournament $tournament) use ($closeCheckin): void {
                $closeCheckin->handle($tournament);
            });

        return self::SUCCESS;
    }
}
