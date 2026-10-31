<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

/**
 * Reachability of the web voice SFU's HTTP port (LiveKit answers a plain GET on it). Self-contained:
 * reads only config, so Preflight keeps no dependency on the WebVoice module. Whether media can
 * flow (UDP, certificate, microphone) is a manual check, see docs/infra/livekit-web-voice.md.
 */
class LiveKitCheck implements HealthCheck
{
    use ProbesHttp;

    public function key(): string
    {
        return 'livekit';
    }

    public function label(): string
    {
        return __('preflight.checks.livekit');
    }

    public function run(): HealthResult
    {
        if (blank(config('webvoice.api_key'))) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        return $this->probe(config('webvoice.api_url'));
    }
}
