<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class DiscordGatewaySidecarCheck implements HealthCheck
{
    use ProbesHttp;

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
        return $this->probe(config('services.discord.gateway_health_url'));
    }
}
