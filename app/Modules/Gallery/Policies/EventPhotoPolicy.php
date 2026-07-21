<?php

namespace App\Modules\Gallery\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;

class EventPhotoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, EventPhoto $photo): bool
    {
        return $photo->visibility === PhotoVisibility::Approved
            || $photo->uploaded_by === $user->id
            || $user->isOrga();
    }

    public function download(User $user, EventPhoto $photo): bool
    {
        return $this->view($user, $photo);
    }

    public function delete(User $user, EventPhoto $photo): bool
    {
        return $photo->uploaded_by === $user->id || $user->isOrga();
    }

    /**
     * Used starting the moderation UI task; defined now so the ability
     * exists alongside the rest of the policy.
     */
    public function approve(User $user, EventPhoto $photo): bool
    {
        return $user->isHelper();
    }

    public function reject(User $user, EventPhoto $photo): bool
    {
        return $user->isHelper();
    }

    public function highlight(User $user, EventPhoto $photo): bool
    {
        return $user->isOrga();
    }

    /**
     * Any authenticated viewer of a public event may download the zip — this
     * ability is about "who", not "when"; the event-status gate
     * (Finished/Archived only) is enforced in the controller.
     */
    public function downloadZip(User $user, Event $event): bool
    {
        return true;
    }
}
