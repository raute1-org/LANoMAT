<?php

namespace App\Modules\Tournaments\Jobs;

use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        try {
            $startTournament->handle($this->tournament);
        } catch (TournamentException $exception) {
            // A rule refusal (too few entries, already started by a racing
            // click, ...) is not retryable: retrying only fails again and
            // would fill failed_jobs. Log it and finish the job.
            Log::warning('Tournament autostart refused: '.$exception->getMessage(), [
                'tournament_id' => $this->tournament->id,
            ]);
        }
    }
}
