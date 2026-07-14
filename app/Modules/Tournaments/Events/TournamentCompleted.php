<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once the tournament's final match is decided: the champion has
 * been written to `Tournament::$winner_entry_id` and the status transitioned
 * to `Finished`. Broadcasting on `tournament.{id}` is wired in a later task
 * (12).
 */
class TournamentCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}
}
