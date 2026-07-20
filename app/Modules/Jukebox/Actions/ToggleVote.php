<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Actions;

use App\Models\User;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxVote;
use Illuminate\Support\Facades\Gate;

/**
 * Toggles a user's up-vote for a queued item: creates the row if absent,
 * removes it if present. Up-votes only — there is no down-vote, see
 * SkipThreshold/ToggleSkipVote for the skip mechanism.
 */
class ToggleVote
{
    public function handle(User $user, JukeboxItem $item): void
    {
        $event = $item->event()->firstOrFail();

        if (! Gate::forUser($user)->allows('jukebox.participate', $event)) {
            throw JukeboxException::notCheckedIn();
        }

        $vote = JukeboxVote::query()
            ->where('jukebox_item_id', $item->id)
            ->where('user_id', $user->id)
            ->first();

        if ($vote !== null) {
            $vote->delete();

            return;
        }

        JukeboxVote::query()->create([
            'jukebox_item_id' => $item->id,
            'user_id' => $user->id,
        ]);
    }
}
