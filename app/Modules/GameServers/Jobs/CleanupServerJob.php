<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Jobs;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Listeners\CleanupServersOnCompleted;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tears down a single provisioned game server a grace period after its
 * tournament completed (see
 * {@see CleanupServersOnCompleted}):
 * deletes it via {@see PelicanClient::deleteServer()} (skipped for manual
 * links, which never had a Pelican server) and marks the
 * {@see ServerLink} {@see ServerLinkStatus::Stopped}.
 *
 * Idempotent: a link already {@see ServerLinkStatus::Stopped} is a no-op, so
 * re-running this job (e.g. a retried queue message) never double-deletes.
 */
class CleanupServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $serverLinkId,
    ) {}

    public function handle(PelicanClient $client): void
    {
        $link = ServerLink::query()->find($this->serverLinkId);

        if ($link === null || $link->status === ServerLinkStatus::Stopped) {
            return;
        }

        if ($link->pelican_server_id !== null) {
            $client->deleteServer($link->pelican_server_id);
        }

        $link->status = ServerLinkStatus::Stopped;
        $link->save();
    }
}
