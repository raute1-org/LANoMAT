<?php

namespace App\Modules\Infoscreen\Policies;

use App\Models\User;
use App\Modules\Infoscreen\Models\InfoscreenScene;

/**
 * Infoscreen scenes are orga-managed content driving the public infoscreen
 * display; unlike the public-viewable Schedule/Catering resources, there is
 * no participant-facing read surface here (the infoscreen page itself is a
 * separate, unauthenticated display route, not gated by this policy).
 */
class InfoscreenScenePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, InfoscreenScene $scene): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, InfoscreenScene $scene): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, InfoscreenScene $scene): bool
    {
        return $user->isOrga();
    }

    public function reorder(User $user): bool
    {
        return $user->isOrga();
    }
}
