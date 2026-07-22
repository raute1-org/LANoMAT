<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class TeamSpeakSidecarCheck implements HealthCheck
{
    use ProbesHttp;

    public function key(): string
    {
        return 'teamspeak';
    }

    public function label(): string
    {
        return __('preflight.checks.teamspeak');
    }

    public function run(): HealthResult
    {
        if (! in_array('teamspeak', config('services.voice.providers'), true)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        return $this->probe(config('services.teamspeak.rest_url'));
    }
}
