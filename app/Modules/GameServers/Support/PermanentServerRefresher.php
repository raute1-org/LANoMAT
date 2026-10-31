<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\ServerInfo;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;

/**
 * Takes one status reading of a permanent server and writes the result onto its
 * {@see ServerLink}: `Ready` (answers, with the player count when its protocol
 * has one), `NotResponding` (switched on but silent) or `Offline`.
 *
 * Two questions are asked, depending on the source:
 *
 * 1. Is it switched on? Pelican: the panel's runtime state; Docker: the
 *    container state; external: unknowable (null).
 * 2. Does it answer its query (A2S or Minecraft ping)? A single missed answer
 *    never flips a server that answered within the last three minutes.
 *
 * It never throws for an unreachable panel, host or server: not knowing is
 * treated as "unknown", not as a failure. Match/event servers are not handled
 * here (see `RefreshQueriesCommand`).
 */
final class PermanentServerRefresher
{
    private const int SILENT_AFTER_MINUTES = 3;

    public function __construct(
        private readonly PelicanClient $client,
        private readonly ServerQuery $a2s,
        private readonly MinecraftQuery $minecraft,
        private readonly DockerServerControl $docker,
    ) {}

    public function refresh(ServerLink $link): void
    {
        if (! $link->permanent) {
            return;
        }

        if ($link->source === ServerSource::Pelican) {
            $panel = $this->panelServer($link);

            // Still installing, or the install failed: the provisioning job owns the status.
            if (in_array($link->status, [ServerLinkStatus::Provisioning, ServerLinkStatus::Pending, ServerLinkStatus::Failed, ServerLinkStatus::InstallFailed], true)
                || ($panel !== null && in_array($panel->state, [ServerState::Installing, ServerState::Provisioning, ServerState::Failed], true))) {
                return;
            }

            $running = match ($panel?->state) {
                ServerState::Running => true,
                ServerState::Stopped => false,
                default => null,
            };
        } elseif ($link->source === ServerSource::Docker) {
            $running = $link->customServer !== null ? $this->docker->isRunning($link->customServer) : null;
        } else {
            $running = null;
        }

        $this->apply($link, $running);
    }

    private function apply(ServerLink $link, ?bool $running): void
    {
        if ($running === false) {
            $link->status = ServerLinkStatus::Offline;
            $link->query_info = null;
            $link->save();

            return;
        }

        if ($link->query_protocol === null) {
            // Nothing to ask: switched on (or registered without a probe) counts as ready.
            $link->status = ServerLinkStatus::Ready;
            $link->save();

            return;
        }

        $info = $this->query($link);

        if ($info !== null) {
            $link->query_info = $info->toArray();
            $link->queried_at = now();
            $link->status = ServerLinkStatus::Ready;
            $link->save();

            return;
        }

        $recentlyAnswered = $link->status === ServerLinkStatus::Ready
            && $link->queried_at !== null
            && $link->queried_at->gte(now()->subMinutes(self::SILENT_AFTER_MINUTES));

        if ($recentlyAnswered) {
            return;
        }

        // Switched on but silent is "not responding"; when we cannot tell whether it is on, it is offline.
        $link->status = $running === true ? ServerLinkStatus::NotResponding : ServerLinkStatus::Offline;
        $link->query_info = null;
        $link->save();
    }

    private function query(ServerLink $link): ?ServerInfo
    {
        $address = $link->join_info->address;
        $port = $link->join_info->port;

        if ($address === null || $port === null) {
            return null;
        }

        return match ($link->query_protocol) {
            QueryProtocol::A2s => $this->a2s->info($address, $port),
            QueryProtocol::Minecraft => $this->minecraft->info($address, $port),
            null => null,
        };
    }

    private function panelServer(ServerLink $link): ?PelicanServer
    {
        if ($link->pelican_server_id === null) {
            return null;
        }

        try {
            return $this->client->getServer($link->pelican_server_id);
        } catch (GameServerException) {
            return null;
        }
    }
}
