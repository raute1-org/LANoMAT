<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever a match result is recorded — via confirmed report or
 * orga override — regardless of whether that match was the tournament final.
 * Broadcasting on `tournament.{id}` and Discord/voice-channel side-effects
 * are wired in later tasks (12/18/21).
 */
class MatchCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly GameMatch $match,
    ) {}
}
