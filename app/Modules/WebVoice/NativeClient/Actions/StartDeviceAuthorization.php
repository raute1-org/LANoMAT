<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Actions;

use App\Modules\WebVoice\NativeClient\Models\VoiceClientDeviceCode;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use App\Modules\WebVoice\NativeClient\Support\NativeClientConfig;

/** Step 1 of the device flow: the client asks for a device code and a short user code. */
class StartDeviceAuthorization
{
    /**
     * @return array{device_code: string, user_code: string, verification_url: string, verification_url_complete: string, expires_in: int, interval: int}
     */
    public function handle(string $deviceName): array
    {
        $deviceCode = DeviceCodes::deviceCode();
        $userCode = DeviceCodes::userCode();
        $ttl = NativeClientConfig::codeTtlSeconds();
        $interval = NativeClientConfig::pollInterval();

        $row = new VoiceClientDeviceCode;
        $row->forceFill([
            'device_code_hash' => DeviceCodes::hash($deviceCode),
            'user_code_hash' => DeviceCodes::hash($userCode),
            'device_name' => self::cleanName($deviceName),
            'status' => VoiceClientDeviceCode::PENDING,
            'interval' => $interval,
            'expires_at' => now()->addSeconds($ttl),
        ])->save();

        $formatted = DeviceCodes::format($userCode);
        $url = route('webvoice.connect');

        return [
            'device_code' => $deviceCode,
            'user_code' => $formatted,
            'verification_url' => $url,
            'verification_url_complete' => $url.'?code='.$formatted,
            'expires_in' => $ttl,
            'interval' => $interval,
        ];
    }

    /** The name is shown to the user on the approval page: plain text, no control characters. */
    public static function cleanName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        return mb_substr($clean === '' ? 'Unbenanntes Gerät' : $clean, 0, 60);
    }
}
