<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The gateway sidecar's `/healthz` answers 200 only while its connection to Discord is up
 * and 503 otherwise, so unlike the other sidecar probes a non-2xx status counts as down.
 */
class DiscordGatewaySidecarCheck implements HealthCheck
{
    public function key(): string
    {
        return 'discord_gateway';
    }

    public function label(): string
    {
        return __('preflight.checks.discord_gateway');
    }

    public function run(): HealthResult
    {
        $url = config('services.discord.gateway_health_url');

        if (blank($url)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            $response = Http::timeout(3)->get((string) $url);
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }

        return $response->successful()
            ? HealthResult::ok()
            : HealthResult::down(__('preflight.messages.gateway_not_connected', ['status' => $response->status()]));
    }
}
