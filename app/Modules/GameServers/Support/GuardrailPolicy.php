<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\GameServers\Models\ServerLink;

/**
 * Resource guardrails against a misconfigured preset/upload freezing the
 * host box, or one user spawning an unbounded number of servers (roadmap
 * 6.7). **Enforced in the provisioning Job/Action, not only the UI** — see
 * {@see ProvisionMatchServerJob} (called against the resolved
 * {@see EffectiveConfig} before {@see PelicanClient::createServer()})
 * and {@see SetManualJoinInfo}.
 *
 * Caps live in `config('services.pelican')`: `max_ram_mb`, `max_slots`,
 * `max_servers_per_user`.
 *
 * **Requester attribution for the per-user cap:** `ServerLink` has no
 * interactive "who asked for this" concept for the automatic
 * match-provisioning path — `ProvisionMatchServerJob` reacts to
 * `MatchReady`, with no human in the loop to attribute the server to. Only
 * the manual/interactive path (`SetManualJoinInfo`, a helper typing in join
 * info by hand) has an actual actor, recorded on `ServerLink::requested_by`.
 * `$requester` is therefore nullable: the manual path always passes its
 * actor and gets the full per-user-cap check; the automatic job path passes
 * `null` and the per-user check is skipped entirely for that call (the
 * RAM/slot caps still apply unconditionally to both paths). This is a
 * deliberate scope decision, not an oversight — see roadmap 6.7's task
 * brief, which frames the per-user cap around "who requests a server".
 *
 * `$game`/`$config` are likewise nullable/optional-in-effect: `SetManualJoinInfo`
 * never calls `PelicanClient::createServer()` (it just records a human-typed
 * address/port), so there is no config to estimate RAM/slots from — that
 * call site passes `null`/`[]` and only the per-user cap applies.
 */
final class GuardrailPolicy
{
    /**
     * Statuses that still occupy a "server slot" for the per-user
     * concurrency cap — Failed/Stopped are terminal and don't count.
     *
     * @var list<ServerLinkStatus>
     */
    private const array RUNNING_STATUSES = [
        ServerLinkStatus::Pending,
        ServerLinkStatus::Provisioning,
        ServerLinkStatus::Ready,
    ];

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws GameServerException
     */
    public static function assertWithinLimits(?Game $game, array $config, ?User $requester): void
    {
        $caps = config('services.pelican');

        if ($game !== null) {
            $estimatedMb = ResourceEstimate::for($game, $config);
            $maxRamMb = (int) $caps['max_ram_mb'];

            if ($estimatedMb > $maxRamMb) {
                throw GameServerException::ramCapExceeded($estimatedMb, $maxRamMb);
            }

            $slots = ResourceEstimate::slots($config);
            $maxSlots = (int) $caps['max_slots'];

            if ($slots > $maxSlots) {
                throw GameServerException::slotsCapExceeded($slots, $maxSlots);
            }
        }

        if ($requester === null) {
            return;
        }

        $maxServersPerUser = (int) $caps['max_servers_per_user'];

        $runningCount = ServerLink::query()
            ->where('requested_by', $requester->id)
            ->whereIn('status', self::RUNNING_STATUSES)
            ->count();

        if ($runningCount >= $maxServersPerUser) {
            throw GameServerException::userServerCapExceeded($maxServersPerUser);
        }
    }
}
