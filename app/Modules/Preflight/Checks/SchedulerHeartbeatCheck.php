<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeatCheck implements HealthCheck
{
    public function key(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return __('preflight.checks.scheduler');
    }

    public function run(): HealthResult
    {
        // Marker is a Unix timestamp written by HeartbeatCommand — see HeartbeatCommand for
        // why it is not a Carbon. Redis returns numeric values as numeric strings
        // (not ints), so accept any numeric value and compare it as an int.
        $tick = Cache::get('preflight.scheduler_tick');

        if (! is_numeric($tick) || (int) $tick < now()->subMinutes(2)->getTimestamp()) {
            return HealthResult::down(__('preflight.messages.stale'));
        }

        return HealthResult::ok();
    }
}
