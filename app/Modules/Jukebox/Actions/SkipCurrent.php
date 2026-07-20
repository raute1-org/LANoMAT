<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use Illuminate\Support\Facades\Gate;

/**
 * Orga/helper override: skip the currently playing item regardless of the
 * community skip-vote count.
 */
class SkipCurrent
{
    public function handle(User $user, Event $event): void
    {
        if (! Gate::forUser($user)->allows('jukebox.moderate', $event)) {
            throw JukeboxException::notModerator();
        }

        $current = JukeboxItem::query()
            ->where('event_id', $event->id)
            ->where('status', QueueItemStatus::Playing)
            ->first();

        if ($current === null) {
            throw JukeboxException::noItemPlaying();
        }

        $current->forceFill(['status' => QueueItemStatus::Skipped])->save();
    }
}
