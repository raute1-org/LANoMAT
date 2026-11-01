<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Actions;

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientDeviceCode;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use Illuminate\Support\Facades\Gate;

/** Finds the pending, unexpired request behind a user code typed on the approval page. */
class ResolveUserCode
{
    public function handle(User $user, string $input): ?VoiceClientDeviceCode
    {
        Gate::forUser($user)->authorize('connect', VoiceClientToken::class);

        $code = DeviceCodes::normalize($input);

        if ($code === null) {
            return null;
        }

        $row = VoiceClientDeviceCode::query()->where('user_code_hash', DeviceCodes::hash($code))->first();

        if ($row === null || $row->status !== VoiceClientDeviceCode::PENDING || $row->isExpired()) {
            return null;
        }

        return $row;
    }
}
