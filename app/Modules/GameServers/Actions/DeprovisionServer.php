<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Jobs\CleanupServerJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Voice\Jobs\CleanupTournamentVoiceJob;

/**
 * The orga-triggered "stop and delete this server now" action — the
 * synchronous counterpart to {@see CleanupServerJob},
 * for an orga tearing down a server before the tournament naturally
 * completes (e.g. after ruling a match void). Authorization for this
 * destructive action is left to the caller (a Filament table action or
 * controller gated on `TournamentPolicy::manage`), matching how
 * {@see CleanupTournamentVoiceJob} has no in-Action
 * check of its own.
 */
class DeprovisionServer
{
    public function handle(ServerLink $link): void
    {
        if ($link->status === ServerLinkStatus::Stopped) {
            return;
        }

        if ($link->pelican_server_id !== null) {
            app(PelicanClient::class)->deleteServer($link->pelican_server_id);
        }

        $link->status = ServerLinkStatus::Stopped;
        $link->save();
    }
}
