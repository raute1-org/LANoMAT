<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseCheck implements HealthCheck
{
    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return __('preflight.checks.database');
    }

    public function run(): HealthResult
    {
        try {
            DB::connection()->select('select 1');

            return HealthResult::ok();
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
