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
        Cache::put('preflight.scheduler_tick', now(), now()->addMinutes(10));
        QueueHeartbeatJob::dispatch();

        return self::SUCCESS;
    }
}
