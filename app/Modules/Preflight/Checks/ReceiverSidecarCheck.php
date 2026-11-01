<?php

declare(strict_types=1);

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Probes the denon-admin sidecar's unauthenticated GET /healthz, then GET /state
 * with the bearer token so a wrong or missing token is caught. The sidecar
 * answers `connected` for its telnet session to the receiver, so "sidecar down"
 * (down) and "sidecar up, receiver offline" (warn) stay distinguishable. The
 * check reads the sidecar directly instead of through the RoomAudio module:
 * Preflight must not import RoomAudio (architecture ratchet).
 */
class ReceiverSidecarCheck implements HealthCheck
{
    public function key(): string
    {
        return 'room_audio';
    }

    public function label(): string
    {
        return __('preflight.checks.room_audio');
    }

    public function run(): HealthResult
    {
        if (blank(config('services.room_audio.token')) || blank(config('services.room_audio.rest_url'))) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $base = rtrim((string) config('services.room_audio.rest_url'), '/');
        $url = $base.'/healthz';

        try {
            $response = Http::timeout(3)->withoutRedirecting()->get($url);
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }

        if ($response->failed()) {
            return HealthResult::down(__('preflight.messages.sidecar_http', ['status' => $response->status()]));
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_key_exists('connected', $payload)) {
            return HealthResult::down(__('preflight.messages.sidecar_unreadable'));
        }

        // /healthz is open; /state needs the bearer, so a wrong or missing
        // token on either side only shows up here.
        try {
            $state = Http::timeout(3)->withoutRedirecting()->withToken((string) config('services.room_audio.token'))->get($base.'/state');
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }

        if (in_array($state->status(), [401, 503], true)) {
            return HealthResult::down(__('preflight.messages.sidecar_token'));
        }

        if ($state->failed()) {
            return HealthResult::down(__('preflight.messages.sidecar_http', ['status' => $state->status()]));
        }

        return $payload['connected'] === true
            ? HealthResult::ok()
            : HealthResult::warn(__('preflight.messages.receiver_offline'));
    }
}
