<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Support\Str;

if (! function_exists('accountBearer')) {
    /**
     * A valid bearer header for the user, like the device flow would have issued. Own name on purpose:
     * `nativeBearer` is declared unguarded in ChatNotificationsApiTest, which may load before or after.
     *
     * @return array<string, string>
     */
    function accountBearer(User $user): array
    {
        $plain = 'lvc_'.Str::random(48);
        $token = new VoiceClientToken;
        $token->forceFill([
            'user_id' => $user->id,
            'device_name' => 'Test PC',
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(90),
        ])->save();

        return ['Authorization' => 'Bearer '.$plain];
    }
}
