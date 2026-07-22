<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class QueueWorkerCheck implements HealthCheck
{
    public function key(): string
    {
        return 'queue_worker';
    }

    public function label(): string
    {
        return __('preflight.checks.queue_worker');
    }

    public function run(): HealthResult
    {
        $tick = Cache::get('preflight.queue_tick');

        if (! $tick instanceof CarbonInterface || $tick->lt(now()->subMinutes(2))) {
            return HealthResult::down(__('preflight.messages.stale'));
        }

        return HealthResult::ok();
    }
}
