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
        // Int timestamp, not a Carbon — see HeartbeatCommand for why (a cached
        // object does not survive the prod cache's serialization allowlist).
        Cache::put('preflight.queue_tick', now()->getTimestamp(), now()->addMinutes(10));
    }
}
