<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Actions;

use App\Modules\Mcp\Models\McpDeviceCode;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\Mcp\Support\McpConfig;

/** Step 1 of the agent pairing flow: mint a code pair (unauthenticated, throttled). */
class StartDeviceAuthorization
{
    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function handle(string $deviceName): array
    {
        // The endpoint is unauthenticated: without a sweep, expired rows would
        // accumulate forever (the rows are the only state an anonymous caller
        // can create). A cheap indexed delete per start keeps the table bounded.
        McpDeviceCode::query()->where('expires_at', '<', now())->delete();

        $deviceCode = McpCodes::deviceCode();
        $userCode = McpCodes::userCode();
        $formatted = McpCodes::format($userCode);

        (new McpDeviceCode)->forceFill([
            'device_code_hash' => McpCodes::hash($deviceCode),
            'user_code_hash' => McpCodes::hash($userCode),
            'device_name' => self::cleanName($deviceName),
            'status' => McpDeviceCode::PENDING,
            'interval' => McpConfig::pollInterval(),
            'expires_at' => now()->addSeconds(McpConfig::codeTtlSeconds()),
        ])->save();

        $verification = route('mcp.connect');

        return [
            'device_code' => $deviceCode,
            'user_code' => $formatted,
            'verification_uri' => $verification,
            'verification_uri_complete' => $verification.'?code='.urlencode($formatted),
            'expires_in' => McpConfig::codeTtlSeconds(),
            'interval' => McpConfig::pollInterval(),
        ];
    }

    public static function cleanName(string $name, string $fallback = 'LANoMAT agent'): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        if ($clean === '') {
            return $fallback;
        }

        return mb_substr($clean, 0, 60);
    }
}
