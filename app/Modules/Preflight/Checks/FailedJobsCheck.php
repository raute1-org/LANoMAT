<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\DB;

class FailedJobsCheck implements HealthCheck
{
    public function key(): string
    {
        return 'failed_jobs';
    }

    public function label(): string
    {
        return __('preflight.checks.failed_jobs');
    }

    public function run(): HealthResult
    {
        $count = DB::table('failed_jobs')->count();

        return $count === 0
            ? HealthResult::ok()
            : HealthResult::warn(__('preflight.messages.failed_jobs', ['count' => $count]));
    }
}
