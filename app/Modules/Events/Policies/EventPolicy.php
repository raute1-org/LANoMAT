<?php

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->isOrga();
    }
}
