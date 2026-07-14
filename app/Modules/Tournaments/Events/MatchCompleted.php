<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever a match result is recorded — via confirmed report or
 * orga override — regardless of whether that match was the tournament final.
 * Broadcasting on `tournament.{id}` and Discord/voice-channel side-effects
 * are wired in later tasks (12/18/21).
 *
 * Implements {@see ShouldDispatchAfterCommit} so those later listeners never
 * observe pre-commit state and never fire for a subsequently rolled-back
 * transaction — dispatch is deferred until the surrounding `DB::transaction()`
 * in {@see ConfirmMatchReport}/{@see OverrideMatchResult}
 * commits.
 */
class MatchCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GameMatch $match,
    ) {}
}
