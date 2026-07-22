<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

trait ProbesHttp
{
    /**
     * `skipped` if $configuredUrl is blank; `ok` if the URL responds at all
     * (any HTTP status = reachable) within the timeout; else `down`. A bare
     * reachability probe — real auth/wire correctness is the acceptance
     * checklist's job, not preflight's.
     */
    private function probe(?string $configuredUrl): HealthResult
    {
        if (blank($configuredUrl)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            Http::timeout(3)->get($configuredUrl);

            return HealthResult::ok();
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
