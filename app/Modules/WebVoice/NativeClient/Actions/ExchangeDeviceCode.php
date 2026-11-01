<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Actions;

use App\Modules\WebVoice\NativeClient\Models\VoiceClientDeviceCode;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use App\Modules\WebVoice\NativeClient\Support\DeviceFlowError;
use App\Modules\WebVoice\NativeClient\Support\NativeClientConfig;
use Illuminate\Support\Facades\DB;

/**
 * Step 3: the client polls with its device code. An approved request is consumed exactly once:
 * the bearer token is minted here (so the plain token exists only in this response) and the
 * request row is deleted.
 */
class ExchangeDeviceCode
{
    private const SLOW_DOWN_STEP = 5;

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws DeviceFlowError
     */
    public function handle(string $deviceCode): array
    {
        $hash = DeviceCodes::hash($deviceCode);

        $failure = null;
        $result = null;

        DB::transaction(function () use ($hash, &$failure, &$result): void {
            $row = VoiceClientDeviceCode::query()->where('device_code_hash', $hash)->lockForUpdate()->first();

            // The lookup is by digest; the compare keeps the check constant-time regardless.
            if ($row === null || ! hash_equals($row->device_code_hash, $hash)) {
                $failure = new DeviceFlowError('invalid_grant');

                return;
            }

            if ($row->isExpired()) {
                $row->delete();
                $failure = new DeviceFlowError('expired_token');

                return;
            }

            $tooFast = $row->last_polled_at !== null && $row->last_polled_at->copy()->addSeconds($row->interval)->isFuture();
            $row->last_polled_at = now();

            if ($tooFast) {
                $row->interval += self::SLOW_DOWN_STEP;
                $row->save();
                $failure = new DeviceFlowError('slow_down', $row->interval);

                return;
            }

            if ($row->status === VoiceClientDeviceCode::DENIED) {
                $row->delete();
                $failure = new DeviceFlowError('access_denied');

                return;
            }

            if ($row->status !== VoiceClientDeviceCode::APPROVED || $row->user_id === null) {
                $row->save();
                $failure = new DeviceFlowError('authorization_pending');

                return;
            }

            $plain = DeviceCodes::bearerToken();
            $ttl = NativeClientConfig::tokenTtlDays();

            $token = new VoiceClientToken;
            $token->forceFill([
                'user_id' => $row->user_id,
                'device_name' => $row->device_name,
                'token_hash' => DeviceCodes::hash($plain),
                'expires_at' => now()->addDays($ttl),
            ])->save();

            $row->delete();

            $result = ['access_token' => $plain, 'token_type' => 'Bearer', 'expires_in' => $ttl * 86400];
        });

        if ($failure instanceof DeviceFlowError) {
            throw $failure;
        }

        /** @var array{access_token: string, token_type: string, expires_in: int} $result */
        return $result;
    }
}
