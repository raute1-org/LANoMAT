<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Console;

use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PermanentServerRefresher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the status and player count of the permanent servers fresh (scheduled
 * every minute, never overlapping). The set is the handful of permanent servers
 * an admin registered, handled one after the other; each one is isolated, so an
 * unreachable panel, Docker host or game server never fails the run.
 */
class RefreshPermanentServersCommand extends Command
{
    protected $signature = 'gameservers:refresh-permanent';

    protected $description = 'Refresh status and players online of the permanent game servers.';

    public function handle(PermanentServerRefresher $refresher): int
    {
        foreach (ServerLink::query()->permanent()->with('customServer.host')->orderBy('id')->get() as $link) {
            try {
                $refresher->refresh($link);
            } catch (Throwable $e) {
                Log::warning('Refreshing a permanent server failed.', ['server_link' => $link->id, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
