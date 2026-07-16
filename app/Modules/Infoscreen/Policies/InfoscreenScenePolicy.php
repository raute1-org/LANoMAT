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

    /**
     * The "show now" remote (Filament row action for orga, and the
     * helper-reachable control page) is helper-or-above, unlike the rest of
     * this policy — helpers manage the live show but don't configure scenes.
     */
    public function showNow(User $user): bool
    {
        return $user->isHelper();
    }
}
