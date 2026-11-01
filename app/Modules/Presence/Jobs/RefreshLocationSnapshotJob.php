<?php

declare(strict_types=1);

namespace App\Modules\Presence\Jobs;

use App\Modules\Presence\Support\LocationSnapshotBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Queued by a request that found no snapshot (cold cache), so the next read has one. Guarded by a
 * short lock: a burst of such requests builds the snapshot once.
 */
class RefreshLocationSnapshotJob implements ShouldQueue
{
    use Queueable;

    public function handle(LocationSnapshotBuilder $builder): void
    {
        Cache::lock('presence.locations.refresh', 20)->get(fn () => $builder->refresh());
    }
}
