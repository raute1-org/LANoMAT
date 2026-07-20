<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Support\TrackDto;
use Illuminate\Support\Facades\Gate;

/**
 * Queues a track for an event's jukebox. Anti-flood: a user may have at most
 * one still-unplayed item (Queued or Playing) per event at a time.
 */
class AddToQueue
{
    public function handle(User $user, Event $event, TrackDto $track): JukeboxItem
    {
        if (! Gate::forUser($user)->allows('jukebox.participate', $event)) {
            throw JukeboxException::notCheckedIn();
        }

        $hasUnplayedItem = JukeboxItem::query()
            ->where('event_id', $event->id)
            ->where('added_by', $user->id)
            ->whereIn('status', [QueueItemStatus::Queued, QueueItemStatus::Playing])
            ->exists();

        if ($hasUnplayedItem) {
            throw JukeboxException::alreadyQueued();
        }

        $item = new JukeboxItem([
            'event_id' => $event->id,
            'added_by' => $user->id,
            'uri' => $track->uri,
            'title' => $track->title,
            'artist' => $track->artist,
            'duration_seconds' => $track->durationSeconds,
            'image_url' => $track->imageUrl,
        ]);
        $item->forceFill(['status' => QueueItemStatus::Queued]);
        $item->save();

        return $item;
    }
}
