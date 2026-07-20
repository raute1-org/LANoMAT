<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Actions;

use App\Models\User;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxSkipVote;
use App\Modules\Jukebox\Support\SkipThreshold;
use Illuminate\Support\Facades\Gate;

/**
 * Toggles a user's skip-vote for an item. Once the item is Playing and its
 * skip-vote count reaches the community SkipThreshold, marks it Skipped —
 * Task 5's Music Assistant sync advances playback from there.
 */
class ToggleSkipVote
{
    public function handle(User $user, JukeboxItem $item): void
    {
        $event = $item->event()->firstOrFail();

        if (! Gate::forUser($user)->allows('jukebox.participate', $event)) {
            throw JukeboxException::notCheckedIn();
        }

        $vote = JukeboxSkipVote::query()
            ->where('jukebox_item_id', $item->id)
            ->where('user_id', $user->id)
            ->first();

        if ($vote !== null) {
            $vote->delete();

            return;
        }

        JukeboxSkipVote::query()->create([
            'jukebox_item_id' => $item->id,
            'user_id' => $user->id,
        ]);

        $item->refresh();

        if ($item->status !== QueueItemStatus::Playing) {
            return;
        }

        $skipVoteCount = $item->skipVotes()->count();

        if ($skipVoteCount >= SkipThreshold::for($event)) {
            $item->forceFill(['status' => QueueItemStatus::Skipped])->save();
        }
    }
}
