<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Actions;

use App\Models\User;
use App\Modules\CustomServers\Enums\CustomServerStatus;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

/**
 * Probes an orga-defined docker "escape hatch" game server for its actual
 * running state via `docker inspect` on the linked
 * {@see RemoteHost}, reconciling `status` with
 * reality (e.g. after a crash outside the app's Start/Stop actions). The
 * container name is escapeshellarg-quoted — see StartCustomServer's
 * docblock for the shared injection-guard reasoning. Read-only, so it is
 * gated on `view` rather than `start`/`stop`.
 */
class ProbeCustomServer
{
    public function __construct(
        private readonly RemoteExecutor $executor,
    ) {}

    public function handle(CustomServer $server, User $actor): CustomServer
    {
        Gate::forUser($actor)->authorize('view', $server);

        $command = "docker inspect -f '{{.State.Running}}' ".escapeshellarg($server->container_name);

        $host = $server->host ?? throw new LogicException(
            "CustomServer {$server->id} references a missing remote_host_id={$server->remote_host_id}.",
        );

        $result = $this->executor->run($host, $command);

        $server->status = $result->ok() && Str::contains($result->stdout, 'true')
            ? CustomServerStatus::Running
            : CustomServerStatus::Stopped;

        $server->save();

        return $server;
    }
}
