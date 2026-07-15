<?php

namespace App\Modules\Lfg\Policies;

use App\Models\User;
use App\Modules\Lfg\Models\LfgPost;

class LfgPostPolicy
{
    /**
     * LFG posts are public — anyone (including guests) may view the list,
     * mirroring FoodOrderPolicy/ScheduleItemPolicy.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, LfgPost $post): bool
    {
        return $user->isOrga() || $post->user_id === $user->id;
    }
}
