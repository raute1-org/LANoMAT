<?php

namespace App\Modules\Tournaments\Jobs;

use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Thin queue wrapper around {@see StartTournament} for scheduler-driven
 * autostart (`TournamentTickCommand`): dispatched once a tournament's
 * `starts_at` has arrived, so bracket generation and persistence happen off
 * the scheduler's request cycle.
 */
class StartTournamentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}

    public function handle(StartTournament $startTournament): void
    {
        $startTournament->handle($this->tournament);
    }
}
