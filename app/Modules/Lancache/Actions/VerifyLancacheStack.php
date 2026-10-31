<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Actions;

use App\Models\User;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Lancache\Domain\LancacheConfig;
use App\Modules\Lancache\Support\LancacheStack;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only checks of the compose-based stack, run ON the cache host: both
 * containers up with matching files, ports bound to the cache IP only, and the
 * heartbeat answers 204. (DNS answers and cache hits are measured from another
 * host, see docs/infra/proxmox-test-vm-lancache.md, gotcha 1.)
 */
class VerifyLancacheStack
{
    public function __construct(private readonly RemoteExecutor $executor) {}

    /** @return list<string> human-readable failures, empty when everything checks out */
    public function handle(RemoteHost $host, LancacheConfig $config, User $actor): array
    {
        Gate::forUser($actor)->authorize('view', $host);

        $failures = [];

        if (! $this->ok($host, LancacheStack::satisfied($config))) {
            $failures[] = 'The stack files or running containers do not match the configuration.';
        }

        if (! $this->ok($host, LancacheStack::portsBoundOnlyToCacheIp($config))) {
            $failures[] = 'Ports 53, 80 and 443 are not bound to the cache IP only.';
        }

        $heartbeat = $this->executor->run($host, 'bash -c '.escapeshellarg(LancacheStack::heartbeat($config)));

        if (trim($heartbeat->stdout) !== '204') {
            $failures[] = 'The LanCache heartbeat did not answer 204.';
        }

        return $failures;
    }

    private function ok(RemoteHost $host, string $script): bool
    {
        return $this->executor->run($host, 'bash -c '.escapeshellarg("set -euo pipefail\n".$script))->ok();
    }
}
