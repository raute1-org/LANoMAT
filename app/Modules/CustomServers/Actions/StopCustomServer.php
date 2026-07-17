<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Actions;

use App\Models\User;
use App\Modules\CustomServers\Enums\CustomServerStatus;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Stops an orga-defined docker "escape hatch" game server by force-removing
 * its container on the linked {@see RemoteHost}
 * via {@see RemoteExecutor}. The container name is escapeshellarg-quoted
 * — see StartCustomServer's docblock for the shared injection-guard
 * reasoning.
 */
class StopCustomServer
{
    public function __construct(
        private readonly RemoteExecutor $executor,
    ) {}

    public function handle(CustomServer $server, User $actor): CustomServer
    {
        Gate::forUser($actor)->authorize('stop', $server);

        $command = 'docker rm -f '.escapeshellarg($server->container_name);

        $host = $server->host ?? throw new LogicException(
            "CustomServer {$server->id} references a missing remote_host_id={$server->remote_host_id}.",
        );

        $result = $this->executor->run($host, $command);

        if ($result->ok()) {
            $server->status = CustomServerStatus::Stopped;
            $server->last_output = null;
        } else {
            $server->status = CustomServerStatus::Failed;
            $server->last_output = trim($result->stderr);
        }

        $server->save();

        return $server;
    }
}
