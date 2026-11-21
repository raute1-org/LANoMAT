<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Exceptions\LightingRateLimited;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Policy check plus the per-user rate limit for manual lighting actions
 * (`lighting.rate_limit_per_minute`, default 60).
 */
trait GuardsLightingAction
{
    private function guard(User $actor): void
    {
        Gate::forUser($actor)->authorize('lighting.control');

        $key = 'lighting-manual:'.$actor->id;
        $max = (int) config('lighting.rate_limit_per_minute', 60);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new LightingRateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }
}
