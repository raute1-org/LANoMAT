<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class MusicAssistantCheck implements HealthCheck
{
    use ProbesHttp;

    public function key(): string
    {
        return 'music_assistant';
    }

    public function label(): string
    {
        return __('preflight.checks.music_assistant');
    }

    public function run(): HealthResult
    {
        if (blank(config('services.music_assistant.token'))) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        return $this->probe(config('services.music_assistant.base_url'));
    }
}
