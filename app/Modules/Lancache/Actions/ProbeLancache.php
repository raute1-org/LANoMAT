<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Actions;

use App\Models\User;
use App\Modules\CustomServers\Actions\ProbeCustomServer;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Lancache\Exceptions\LancacheException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Probes a role=lancache {@see RemoteHost} for reachability by running a
 * `docker inspect` health check against the `lancache` container name on the
 * host, via {@see RemoteExecutor} — mirrors
 * {@see ProbeCustomServer}'s read-only
 * reconciliation-check shape.
 */
class ProbeLancache
{
    public function __construct(
        private readonly RemoteExecutor $executor,
    ) {}

    public function handle(RemoteHost $host, User $actor): CommandResult
    {
        if (! $actor->isOrga()) {
            throw new AuthorizationException;
        }

        if ($host->role !== HostRole::Lancache) {
            throw LancacheException::notALancacheHost($host);
        }

        $command = "docker inspect -f '{{.State.Running}}' ".escapeshellarg('lancache');

        return $this->executor->run($host, $command);
    }
}
