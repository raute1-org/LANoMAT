<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once the tournament's final match is decided: the champion has
 * been written to `Tournament::$winner_entry_id` and the status transitioned
 * to `Finished`. Broadcasting on `tournament.{id}` is wired in a later task
 * (12).
 *
 * Implements {@see ShouldDispatchAfterCommit} so that later listener never
 * observes pre-commit state and never fires for a subsequently rolled-back
 * transaction — dispatch is deferred until the surrounding `DB::transaction()`
 * in {@see ConfirmMatchReport}/{@see OverrideMatchResult}
 * commits.
 */
class TournamentCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}
}
