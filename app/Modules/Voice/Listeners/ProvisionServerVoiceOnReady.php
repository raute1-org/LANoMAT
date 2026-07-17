<?php

declare(strict_types=1);

namespace App\Modules\Voice\Listeners;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Listeners\EnterWarmupOnServerReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Voice\Jobs\ProvisionServerVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a match-scoped {@see ServerLinkUpdated} turning Ready by queuing
 * {@see ProvisionServerVoiceJob} — issue #13's "voice channel per running
 * game server". Guard mirrors
 * {@see EnterWarmupOnServerReady}: only
 * a `Ready` status with a `match_id` set is actionable (a tournament-scoped
 * ServerLink, or any other status, is a no-op here). The Voice module reads
 * the {@see ServerLink} only via the event
 * payload — it never queries GameServers' own tables.
 *
 * Job-level idempotency (per provider, keyed on `server_channel_id` already
 * being set) makes a re-fired `ServerLinkUpdated(Ready)` for the same match
 * safe to ignore here, so no additional state is checked in the listener
 * itself.
 */
class ProvisionServerVoiceOnReady implements ShouldQueue
{
    public function handle(ServerLinkUpdated $event): void
    {
        $link = $event->serverLink;

        if ($link->status !== ServerLinkStatus::Ready || $link->match_id === null) {
            return;
        }

        ProvisionServerVoiceJob::dispatch($link->match_id);
    }
}
