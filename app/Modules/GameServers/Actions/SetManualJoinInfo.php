<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\GuardrailPolicy;
use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Support\Facades\Gate;

/**
 * The helper-reachable manual fallback for a match's game server: when
 * auto-provisioning fails or a game has no `pelican_egg_id` at all (manual
 * mode, see {@see ProvisionMatchServerOnReady}),
 * an orga/helper types in the connect details by hand.
 *
 * Upserts the match's existing {@see ServerLink} (if any — e.g. one left
 * behind in {@see ServerLinkStatus::Failed}
 * by {@see PollServerStatusJob}) rather than
 * always creating a new row, so a helper correcting a typo does not pile up
 * duplicate links for the same match. Authorized in-Action (mirrors
 * {@see SetStatusSignal}) since this has more
 * than one future entry point (a control page today, potentially Filament or
 * Discord later).
 *
 * This is the one entry point with a real interactive requester, so it is
 * the primary enforcement point for {@see GuardrailPolicy}'s per-user
 * concurrency cap (see that class's docblock): no config/RAM estimate
 * applies here (this action never calls `PelicanClient::createServer()`,
 * it just records a human-typed address/port), but a helper attaching an
 * unbounded number of manual servers is exactly the "one user, too many
 * servers" case the cap exists for. `$actor` is recorded on the resulting
 * link's `requested_by` so later calls can count it.
 */
class SetManualJoinInfo
{
    public function handle(GameMatch $match, JoinInfo $info, User $actor): ServerLink
    {
        Gate::forUser($actor)->authorize('setManualJoinInfo', $match);

        $link = $match->serverLink ?? new ServerLink(['match_id' => $match->id]);

        // Only enforce the cap when this would grow the actor's running
        // server count — an update to an existing link the actor already
        // owns (e.g. correcting a typo) must not be blocked by the actor's
        // own prior link. No game/config applies here (see GuardrailPolicy's
        // docblock), so only the per-user cap is checked.
        if ($link->exists === false || $link->requested_by !== $actor->id) {
            GuardrailPolicy::assertWithinLimits(game: null, config: [], requester: $actor);
        }

        $link->manual = true;
        $link->status = ServerLinkStatus::Ready;
        $link->join_info = $info;
        $link->requested_by = $actor->id;
        $link->save();

        if ($match->server_link_id !== $link->id) {
            $match->forceFill(['server_link_id' => $link->id])->save();
        }

        ServerLinkUpdated::dispatch($link);

        return $link;
    }
}
