<?php

namespace App\Modules\Preflight\Console;

use App\Modules\Preflight\Jobs\QueueHeartbeatJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class HeartbeatCommand extends Command
{
    protected $signature = 'lanomat:heartbeat';

    protected $description = 'Writes scheduler + queue-worker liveness markers for preflight.';

    public function handle(): int
    {
        // Store a Unix timestamp, not a Carbon: prod caches (redis/file)
        // serialize values, and config('cache.serializable_classes') === false
        // returns any cached *object* as __PHP_Incomplete_Class — so a Carbon
        // marker never round-trips and the check would always read "down". A
        // plain timestamp needs no class deserialization and survives every
        // cache store. Note: it round-trips as an int on file/array stores but
        // as a numeric STRING on redis, so readers must accept both (see the
        // checks, which use is_numeric + (int) cast).
        Cache::put('preflight.scheduler_tick', now()->getTimestamp(), now()->addMinutes(10));
        QueueHeartbeatJob::dispatch();

        return self::SUCCESS;
    }
}
