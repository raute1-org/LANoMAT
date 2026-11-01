<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Policies;

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;

/**
 * Pairing a desktop client and managing paired devices. Admins pass through the app-wide
 * `Gate::before`; everybody else manages only their own devices.
 */
class VoiceClientTokenPolicy
{
    /** Approve or deny a pairing request for oneself. Every logged-in user. */
    public function connect(User $user): bool
    {
        return true;
    }

    /** The client download page and its files: every logged-in user (the route group is `auth`). */
    public function downloadClient(User $user): bool
    {
        return true;
    }

    public function revoke(User $user, VoiceClientToken $token): bool
    {
        return $token->user_id === $user->getKey();
    }
}
