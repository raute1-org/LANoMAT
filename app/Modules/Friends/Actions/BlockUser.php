<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Exceptions\FriendshipException;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use Illuminate\Support\Facades\DB;

class BlockUser
{
    public function handle(User $blocker, User $blocked): UserBlock
    {
        if ($blocker->id === $blocked->id) {
            throw FriendshipException::cannotBlockSelf();
        }

        return DB::transaction(function () use ($blocker, $blocked): UserBlock {
            $block = UserBlock::query()->firstOrCreate([
                'blocker_id' => $blocker->id,
                'blocked_id' => $blocked->id,
            ]);

            // A block supersedes any friendship (accepted or pending, either
            // direction) between the two users — harmless to run again when
            // the block already existed, since there would be no lingering
            // friendship left to remove in that case.
            Friendship::query()
                ->betweenUsers($blocker->id, $blocked->id)
                ->delete();

            return $block;
        });
    }
}
