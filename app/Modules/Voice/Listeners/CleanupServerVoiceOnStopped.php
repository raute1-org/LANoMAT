<?php

declare(strict_types=1);

namespace App\Modules\Voice\Listeners;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\Voice\Jobs\CleanupEventServerVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Removes the voice channels of an event-level game server once it is
 * stopped (the orga's "stop and delete" action announces `Stopped`). Only the
 * `Stopped` status acts; the cleanup job is idempotent, so a repeated event is
 * harmless.
 */
class CleanupServerVoiceOnStopped implements ShouldQueue
{
    public function handle(ServerLinkUpdated $event): void
    {
        if ($event->serverLink->status !== ServerLinkStatus::Stopped) {
            return;
        }

        CleanupEventServerVoiceJob::dispatch($event->serverLink->id);
    }
}
