<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Listeners;

use App\Modules\Discord\Listeners\AnnounceAndCleanupOnCompleted;
use App\Modules\GameServers\Jobs\CleanupServerJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a tournament finishing by queuing a delayed
 * {@see CleanupServerJob} for every {@see ServerLink} it provisioned: one per
 * match (mirrors {@see AnnounceAndCleanupOnCompleted}'s
 * grace-period cleanup) plus any tournament-level link. The delay gives
 * players a grace period to finish up before their server is torn down.
 */
class CleanupServersOnCompleted implements ShouldQueue
{
    private const CLEANUP_DELAY_MINUTES = 15;

    public function handle(TournamentCompleted $event): void
    {
        $tournament = $event->tournament;

        // GameMatch is the FK-holding side (matches.server_link_id), so the
        // per-match links are found via GameMatch, not ServerLink::match()
        // (which points the other way, at ServerLink.match_id — used by
        // manual links created directly on a ServerLink rather than through
        // GameMatch::server_link_id).
        $matchLinkIds = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('server_link_id')
            ->pluck('server_link_id');

        $tournamentLinkIds = ServerLink::query()
            ->where('tournament_id', $tournament->id)
            ->pluck('id');

        $linkIds = $matchLinkIds->merge($tournamentLinkIds)->unique();

        foreach ($linkIds as $linkId) {
            CleanupServerJob::dispatch($linkId)->delay(now()->addMinutes(self::CLEANUP_DELAY_MINUTES));
        }
    }
}
