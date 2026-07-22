<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class PelicanCheck implements HealthCheck
{
    use ProbesHttp;

    public function key(): string
    {
        return 'pelican';
    }

    public function label(): string
    {
        return __('preflight.checks.pelican');
    }

    public function run(): HealthResult
    {
        return $this->probe(config('services.pelican.panel_url'));
    }
}
