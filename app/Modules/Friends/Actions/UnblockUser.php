<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Models\UserBlock;

class UnblockUser
{
    public function handle(User $blocker, User $blocked): void
    {
        UserBlock::query()
            ->where('blocker_id', $blocker->id)
            ->where('blocked_id', $blocked->id)
            ->delete();
    }
}
