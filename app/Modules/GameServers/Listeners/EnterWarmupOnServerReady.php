<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Listeners;

use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\Tournaments\Actions\EnterWarmup;
use App\Modules\Tournaments\Actions\GoLive;
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
 *
 * Also requires `warmup_started_at` to still be null. `MatchStatus::Ready`
 * is overloaded: {@see GoLive} flips a live
 * match's status back to `Ready` too (the "gong" moment — the match is now
 * actually being played, not waiting to be played), and
 * {@see SetManualJoinInfo} dispatches
 * `ServerLinkUpdated(Ready)` on every call, including a helper merely
 * editing join info after the match already went live. Without this guard
 * that later event would match the `MatchStatus::Ready` check above and yank
 * a live match back into `Warmup`, re-arming the gong. `warmup_started_at`
 * is stamped once by {@see EnterWarmup} and never cleared afterwards, so a
 * non-null value reliably means "already warmed up (and possibly live)" —
 * a later `ServerLinkUpdated(Ready)` is then a no-op here.
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

        if ($match === null || $match->status !== MatchStatus::Ready || $match->warmup_started_at !== null) {
            return;
        }

        (new EnterWarmup)->handle($match);
    }
}
