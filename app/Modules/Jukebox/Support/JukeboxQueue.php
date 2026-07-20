<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pure read-model over an event's jukebox items — the single place that
 * defines "upcoming order" (net up-votes desc, then age asc) so the
 * participant queue view and the Music Assistant sync (Task 5) always agree.
 */
class JukeboxQueue
{
    /**
     * @return Collection<int, JukeboxItem>
     */
    public function upcoming(Event $event): Collection
    {
        return JukeboxItem::query()
            ->where('event_id', $event->id)
            ->where('status', QueueItemStatus::Queued)
            ->withCount('votes')
            ->with(['addedBy', 'votes'])
            ->orderByDesc('votes_count')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function current(Event $event): ?JukeboxItem
    {
        return JukeboxItem::query()
            ->where('event_id', $event->id)
            ->where('status', QueueItemStatus::Playing)
            ->first();
    }
}
