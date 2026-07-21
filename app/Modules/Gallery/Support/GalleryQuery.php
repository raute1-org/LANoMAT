<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Jukebox\Support\JukeboxQueue;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pure read-model over an event's approved gallery photos — the single place
 * that defines "gallery order" (highlights first, then most recent) so the
 * participant gallery page, the beamer scene, and the public recap always
 * agree, mirroring {@see JukeboxQueue}.
 */
class GalleryQuery
{
    /**
     * @return Collection<int, EventPhoto>
     */
    public function approvedFor(Event $event): Collection
    {
        return EventPhoto::query()
            ->where('event_id', $event->id)
            ->where('visibility', PhotoVisibility::Approved)
            ->with('uploader')
            ->orderByDesc('is_highlight')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Highlights first, then the most recent approved photos, capped at
     * `$limit` — reused by the beamer scene and the public recap.
     *
     * @return Collection<int, EventPhoto>
     */
    public function highlightsFor(Event $event, int $limit = 6): Collection
    {
        return $this->approvedFor($event)->take($limit)->values();
    }
}
