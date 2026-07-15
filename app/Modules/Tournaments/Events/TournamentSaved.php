<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever a schedule-relevant attribute of a `Tournament` is
 * created or changed (see {@see Tournament::booted()}). Currently consumed
 * only by the Schedule module to keep its mirrored `schedule_items` row in
 * sync, independent of whether the tournament was created via Filament or
 * a future Action.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the listener never reacts
 * to a tournament save that is later rolled back.
 */
class TournamentSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}
}
