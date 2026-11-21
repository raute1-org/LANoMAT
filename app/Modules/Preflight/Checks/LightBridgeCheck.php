<?php

declare(strict_types=1);

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Probes the DMX light bridge's GET /api/health. Lights are nice-to-have, so a
 * bridge that is off or unhealthy is a warning, never "down". Skipped when the
 * bridge is not configured. Reads the bridge directly instead of through the
 * Lighting module (Preflight must not import it; architecture ratchet).
 */
class LightBridgeCheck implements HealthCheck
{
    public function key(): string
    {
        return 'lighting';
    }

    public function label(): string
    {
        return __('preflight.checks.lighting');
    }

    public function run(): HealthResult
    {
        $base = config('services.lighting.base_url');

        if (! config('services.lighting.enabled') || blank($base)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            $request = Http::timeout(3)->withoutRedirecting()->acceptJson();
            $token = config('services.lighting.token');
            $response = (filled($token) ? $request->withToken((string) $token) : $request)
                ->get(rtrim((string) $base, '/').'/api/health');
        } catch (Throwable) {
            return HealthResult::warn(__('preflight.messages.lighting_unreachable'));
        }

        if ($response->failed()) {
            return HealthResult::warn(__('preflight.messages.lighting_unhealthy'));
        }

        $json = $response->json();
        $status = is_array($json) ? ($json['status'] ?? $json['ok'] ?? null) : null;
        $healthy = $status === null || $status === true || (is_string($status) && in_array(strtolower($status), ['ok', 'healthy', 'up'], true));

        return $healthy ? HealthResult::ok() : HealthResult::warn(__('preflight.messages.lighting_unhealthy'));
    }
}
