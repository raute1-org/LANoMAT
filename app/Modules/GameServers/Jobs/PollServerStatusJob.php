<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Jobs;

use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polls a provisioned server's install status
 * ({@see PelicanClient::getServer()}) after
 * {@see ProvisionMatchServerJob} kicked off creation:
 *
 * - {@see ServerState::Running} -> writes the join details as a
 *   {@see JoinInfo}, flips the {@see ServerLink} to
 *   {@see ServerLinkStatus::Ready}, and dispatches {@see ServerLinkUpdated}
 *   (Task 5 pushes this onto the match page/Discord embed).
 * - {@see ServerState::Installing}/{@see ServerState::Provisioning} -> not
 *   ready yet; re-dispatches itself (a fresh job instance with `$attempt`
 *   incremented) with a delay, bounded by {@see MAX_ATTEMPTS} so a panel that
 *   never finishes installing does not poll forever.
 * - {@see ServerState::Failed}, or the retry budget being exhausted -> flips
 *   the link to {@see ServerLinkStatus::Failed}, surfacing the manual
 *   fallback ({@see SetManualJoinInfo}) in
 *   the UI.
 *
 * The retry count is tracked via the explicit `$attempt` constructor
 * argument rather than the queue's own `$job->attempts()`/`$tries`: each
 * "still installing" outcome dispatches a brand-new job instance (see
 * bottom of {@see handle()}) rather than releasing this one back onto the
 * queue, so the framework's own attempt counter would never advance past 1.
 */
class PollServerStatusJob implements ShouldQueue
{
    use Queueable;

    /**
     * Bounds the polling window: with a 10s delay between attempts, 30
     * attempts is a 5-minute ceiling before giving up and surfacing manual
     * fallback.
     */
    private const MAX_ATTEMPTS = 30;

    private const DELAY_SECONDS = 10;

    public function __construct(
        public readonly int $serverLinkId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(PelicanClient $client): void
    {
        $link = ServerLink::query()->find($this->serverLinkId);

        if ($link === null || $link->status !== ServerLinkStatus::Provisioning) {
            return;
        }

        if ($link->pelican_server_id === null) {
            return;
        }

        $server = $client->getServer($link->pelican_server_id);

        if ($server->state === ServerState::Running) {
            $link->join_info = new JoinInfo(
                address: $server->address,
                port: $server->port,
            );
            $link->status = ServerLinkStatus::Ready;
            $link->save();

            ServerLinkUpdated::dispatch($link);

            return;
        }

        if ($server->state === ServerState::Failed) {
            $link->status = ServerLinkStatus::Failed;
            $link->save();

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $link->status = ServerLinkStatus::Failed;
            $link->save();

            return;
        }

        self::dispatch($this->serverLinkId, $this->attempt + 1)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }
}
