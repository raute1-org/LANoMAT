<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;

/**
 * Small Jukebox read-model exposing aggregate counts to other modules (the
 * M12 recap board) without letting them query `jukebox_items` directly,
 * mirroring {@see JukeboxQueue}'s "single source of truth" role for queue
 * ordering.
 */
final class JukeboxStats
{
    public function playedCount(Event $event): int
    {
        return JukeboxItem::query()
            ->where('event_id', $event->id)
            ->where('status', QueueItemStatus::Played)
            ->count();
    }
}
