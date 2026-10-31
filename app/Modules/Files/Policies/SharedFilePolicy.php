<?php

namespace App\Modules\Files\Policies;

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;

class SharedFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, SharedFile $file): bool
    {
        return $file->visibility === FileVisibility::Approved
            || $file->user_id === $user->id
            || $user->isOrga();
    }

    /**
     * Stricter than view(): only an approved file is downloadable by
     * participants. A pending file is downloadable by its uploader and by orga
     * (who must inspect it before approving); a rejected file never is: its
     * bytes are removed from the store on rejection.
     */
    public function download(User $user, SharedFile $file): bool
    {
        return match ($file->visibility) {
            FileVisibility::Approved => true,
            FileVisibility::Pending => $file->user_id === $user->id || $user->isOrga(),
            FileVisibility::Rejected => false,
        };
    }

    public function delete(User $user, SharedFile $file): bool
    {
        return $file->user_id === $user->id || $user->isOrga();
    }

    /**
     * Used starting Task 6 (the moderation Filament UI); defined now so the
     * ability exists alongside the rest of the policy.
     */
    public function approve(User $user, SharedFile $file): bool
    {
        return $user->isHelper();
    }

    public function reject(User $user, SharedFile $file): bool
    {
        return $user->isHelper();
    }
}
