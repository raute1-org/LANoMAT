<?php

namespace App\Modules\Discord\Listeners;

use App\Modules\Discord\Jobs\CreateMatchChannelJob;
use App\Modules\Tournaments\Events\MatchReady;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a match becoming playable by queuing {@see CreateMatchChannelJob}
 * — kept as a thin dispatch wrapper so the (potentially slow, rate-limited)
 * Discord API calls never run inline with the bracket-progression
 * transaction that triggered this event.
 */
class CreateMatchChannelOnReady implements ShouldQueue
{
    public function handle(MatchReady $event): void
    {
        CreateMatchChannelJob::dispatch($event->match->id);
    }
}
