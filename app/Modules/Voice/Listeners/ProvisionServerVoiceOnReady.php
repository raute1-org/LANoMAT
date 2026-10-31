<?php

declare(strict_types=1);

namespace App\Modules\Voice\Listeners;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Listeners\EnterWarmupOnServerReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Voice\Jobs\ProvisionEventServerVoiceJob;
use App\Modules\Voice\Jobs\ProvisionServerVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a {@see ServerLinkUpdated} turning Ready by queuing a voice
 * channel for the running game server (issue #13): a match-scoped server gets
 * the shared match channel via {@see ProvisionServerVoiceJob}, an event-level
 * preset server gets a channel named after it via
 * {@see ProvisionEventServerVoiceJob}. The match guard mirrors
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

        if ($link->status !== ServerLinkStatus::Ready) {
            return;
        }

        if ($link->match_id !== null) {
            ProvisionServerVoiceJob::dispatch($link->match_id);

            return;
        }

        // An event-level preset server (no tournament match): one channel named
        // after the server, so a whole round (TTT, Prop Hunt, ...) can talk.
        if ($link->event_id !== null) {
            ProvisionEventServerVoiceJob::dispatch($link->id, (string) $link->title);
        }
    }
}
