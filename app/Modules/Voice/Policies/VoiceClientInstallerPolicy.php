<?php

declare(strict_types=1);

namespace App\Modules\Voice\Policies;

use App\Models\User;
use App\Modules\Files\Policies\SharedFilePolicy;
use App\Modules\Voice\Models\VoiceClientInstaller;

/**
 * Managing installers (upload/replace/mark current/delete) is orga-only —
 * mirrors {@see SharedFilePolicy}'s orga gate.
 * Downloading, however, is open to any authenticated participant: the whole
 * point of hosting installers in LANoMAT is that every attendee can grab the
 * right client, so `download` is intentionally permissive (true for any
 * authed user) rather than orga-gated. It still goes through the policy —
 * and the route still requires `auth` — rather than being a public URL.
 */
class VoiceClientInstallerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, VoiceClientInstaller $installer): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, VoiceClientInstaller $installer): bool
    {
        return $user->isOrga();
    }

    public function download(User $user, VoiceClientInstaller $installer): bool
    {
        return true;
    }
}
