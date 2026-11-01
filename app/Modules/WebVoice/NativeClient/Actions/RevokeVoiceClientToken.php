<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Actions;

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Support\Facades\Gate;

/** Revokes one paired device. Owner only (VoiceClientTokenPolicy). */
class RevokeVoiceClientToken
{
    public function handle(User $actor, VoiceClientToken $token): void
    {
        Gate::forUser($actor)->authorize('revoke', $token);

        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
    }
}
