<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Listeners;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\Tournaments\Actions\EnterWarmup;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a match-scoped {@see ServerLinkUpdated} turning Ready by
 * automatically moving the match into `Warmup` (Task 11's game-agnostic
 * gate) — "the match's game server just came up" is exactly the automatic
 * trigger the brief names as an alternative to a human helper's manual
 * warmup start. Kept a thin dispatch wrapper, mirroring
 * {@see ProvisionMatchServerOnReady}.
 *
 * Only acts while the match is still `Ready` (both slots filled, nobody has
 * reported yet): a re-fired `ServerLinkUpdated` (e.g. the poll job writing
 * Ready more than once) or a server coming up after the match has already
 * progressed past warmup must not throw — {@see EnterWarmup} would raise
 * for any other status, so that case is filtered out here instead of
 * catching the exception.
 */
class EnterWarmupOnServerReady implements ShouldQueue
{
    public function handle(ServerLinkUpdated $event): void
    {
        $link = $event->serverLink;

        if ($link->status !== ServerLinkStatus::Ready || $link->match_id === null) {
            return;
        }

        $match = GameMatch::query()->find($link->match_id);

        if ($match === null || $match->status !== MatchStatus::Ready) {
            return;
        }

        (new EnterWarmup)->handle($match);
    }
}
