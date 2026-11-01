<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Policies;

use App\Models\User;

/**
 * Registered as the `room-audio.control` gate in AppServiceProvider. Only the
 * orga team (Admin|Orga) steers the room receiver.
 */
class RoomAudioPolicy
{
    public function control(User $user): bool
    {
        return $user->isOrga();
    }
}
