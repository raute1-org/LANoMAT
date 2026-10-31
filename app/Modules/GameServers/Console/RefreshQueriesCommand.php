<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Console;

use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Console\Command;

/**
 * Keeps the player count and map of query-enabled servers fresh (scheduled
 * every minute): a Ready server that answers gets updated info, a
 * NotResponding one that answers again recovers to Ready, and a Ready one that
 * has been silent for more than three minutes (since its last answer) is marked
 * NotResponding. A single missed query never flips the status.
 */
class RefreshQueriesCommand extends Command
{
    private const int SILENT_AFTER_MINUTES = 3;

    protected $signature = 'gameservers:refresh-queries';

    protected $description = 'Refresh player counts of query-enabled game servers and detect servers that stopped answering.';

    public function handle(ServerQuery $query): int
    {
        $links = ServerLink::query()
            ->whereIn('status', [ServerLinkStatus::Ready, ServerLinkStatus::NotResponding])
            ->whereNotNull('readiness')
            ->get()
            ->filter(fn (ServerLink $link): bool => ($link->readiness['type'] ?? null) === 'a2s');

        foreach ($links as $link) {
            $address = $link->join_info->address;
            $port = $link->join_info->port;

            if ($address === null || $port === null) {
                continue;
            }

            $info = $query->info($address, $port);

            if ($info !== null) {
                $recovered = $link->status === ServerLinkStatus::NotResponding;
                $link->query_info = $info->toArray();
                $link->queried_at = now();
                $link->status = ServerLinkStatus::Ready;
                $link->save();

                if ($recovered) {
                    ServerLinkUpdated::dispatch($link);
                }

                continue;
            }

            if ($link->status === ServerLinkStatus::Ready && ($link->queried_at === null || $link->queried_at->lt(now()->subMinutes(self::SILENT_AFTER_MINUTES)))) {
                $link->status = ServerLinkStatus::NotResponding;
                $link->save();

                ServerLinkUpdated::dispatch($link);
            }
        }

        return self::SUCCESS;
    }
}
