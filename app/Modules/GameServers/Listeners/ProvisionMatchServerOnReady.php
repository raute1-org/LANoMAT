<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Listeners;

use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Voice\Listeners\ProvisionMatchVoiceOnReady;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a match becoming playable by queuing
 * {@see ProvisionMatchServerJob} — kept as a thin dispatch wrapper, mirroring
 * {@see ProvisionMatchVoiceOnReady}.
 *
 * Unlike the Voice listener, provisioning is not always desired: a game
 * server is only auto-provisioned when the tournament's game is configured
 * with a `pelican_egg_id`. Without one, the event is a no-op and the match
 * stays in "manual mode" — an orga/helper sets join info by hand via
 * {@see SetManualJoinInfo}.
 */
class ProvisionMatchServerOnReady implements ShouldQueue
{
    public function handle(MatchReady $event): void
    {
        $match = $event->match;
        $game = $match->tournament?->game;

        if ($game === null || $game->pelican_egg_id === null) {
            return;
        }

        ProvisionMatchServerJob::dispatch($match->id);
    }
}
