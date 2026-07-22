<?php

namespace App\Modules\Preflight\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Proves a worker is consuming the queue by stamping a cache marker. */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('preflight.queue_tick', now(), now()->addMinutes(10));
    }
}
