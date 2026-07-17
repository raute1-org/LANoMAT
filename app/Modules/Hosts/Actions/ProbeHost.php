<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Actions;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Enums\HostStatus;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Carbon;

/**
 * Probes a {@see RemoteHost} for reachability and records the outcome.
 * `host_fingerprint`, `status`, and `last_probed_at` are deliberately not
 * fillable (see {@see RemoteHost::$fillable}), so this action writes them
 * via direct assignment/forceFill rather than mass assignment — it is the
 * sole writer of this system-managed state.
 */
class ProbeHost
{
    public function __construct(
        private readonly RemoteExecutor $executor,
    ) {}

    public function handle(RemoteHost $host): RemoteHost
    {
        $probe = $this->executor->probe($host);

        $host->status = $probe->reachable ? HostStatus::Reachable : HostStatus::Unreachable;

        if ($probe->reachable && $host->host_fingerprint === null && $probe->fingerprint !== null) {
            $host->host_fingerprint = $probe->fingerprint;
        }

        $host->last_probed_at = Carbon::now();

        $host->save();

        return $host;
    }
}
