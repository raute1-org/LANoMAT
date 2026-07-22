<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisCheck implements HealthCheck
{
    public function key(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        return __('preflight.checks.redis');
    }

    public function run(): HealthResult
    {
        try {
            Redis::connection()->ping();

            return HealthResult::ok();
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
