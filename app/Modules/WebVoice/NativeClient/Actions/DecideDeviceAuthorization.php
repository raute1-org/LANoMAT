<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Actions;

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientDeviceCode;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Step 2: the logged-in user approves or denies a pending request. Single use. */
class DecideDeviceAuthorization
{
    /** @return bool false when the code is unknown, expired or already decided */
    public function handle(User $user, string $userCode, bool $approve): bool
    {
        Gate::forUser($user)->authorize('connect', VoiceClientToken::class);

        $code = DeviceCodes::normalize($userCode);

        if ($code === null) {
            return false;
        }

        return DB::transaction(function () use ($user, $code, $approve): bool {
            $row = VoiceClientDeviceCode::query()
                ->where('user_code_hash', DeviceCodes::hash($code))
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->status !== VoiceClientDeviceCode::PENDING || $row->isExpired()) {
                return false;
            }

            $row->forceFill([
                'status' => $approve ? VoiceClientDeviceCode::APPROVED : VoiceClientDeviceCode::DENIED,
                'user_id' => $approve ? $user->getKey() : null,
            ])->save();

            return true;
        });
    }
}
