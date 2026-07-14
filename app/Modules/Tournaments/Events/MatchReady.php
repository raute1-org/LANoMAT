<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a match transitions to `Ready` because both of its slots
 * now hold a real entrant — either because a report was just confirmed and
 * this is a freshly-fed downstream match, or an orga override had the same
 * effect. Broadcasting on `tournament.{id}` and Discord/voice-channel
 * side-effects are wired in later tasks (12/18/21).
 */
class MatchReady
{
    use Dispatchable;

    public function __construct(
        public readonly GameMatch $match,
    ) {}
}
