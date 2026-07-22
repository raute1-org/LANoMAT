<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class MumbleSidecarCheck implements HealthCheck
{
    use ProbesHttp;

    public function key(): string
    {
        return 'mumble';
    }

    public function label(): string
    {
        return __('preflight.checks.mumble');
    }

    public function run(): HealthResult
    {
        if (! in_array('mumble', config('services.voice.providers'), true)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        return $this->probe(config('services.mumble.rest_url'));
    }
}
