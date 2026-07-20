<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Actions;

use App\Models\User;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use Illuminate\Support\Facades\Gate;

/**
 * Orga/helper override: remove any item regardless of its current status
 * (queued, playing, …). Marks it Skipped rather than hard-deleting so vote
 * history and "already queued" bookkeeping stay consistent.
 */
class RemoveItem
{
    public function handle(User $user, JukeboxItem $item): void
    {
        if (! Gate::forUser($user)->allows('jukebox.moderate', $item->event()->firstOrFail())) {
            throw JukeboxException::notModerator();
        }

        $item->forceFill(['status' => QueueItemStatus::Skipped])->save();
    }
}
