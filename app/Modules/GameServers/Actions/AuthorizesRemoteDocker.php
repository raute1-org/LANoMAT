<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Enums\ControlDriver;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Shared guard and audit line of the remote Docker actions: the actor may configure this
 * permanent server (`ServerLinkPolicy::configure`, orga and admin) and it is linked to a
 * container on a remote host. The actor is always an argument, never read from a request.
 */
trait AuthorizesRemoteDocker
{
    /**
     * @return array{0: RemoteHost, 1: string} The host and the container name.
     *
     * @throws GameServerException
     */
    private function authorizeRemote(User $actor, ServerLink $link): array
    {
        Gate::forUser($actor)->authorize('configure', $link);

        $host = $link->controlDriver() === ControlDriver::RemoteDocker ? $link->remoteHost : null;

        if ($host === null || $link->remote_container === null) {
            throw GameServerException::remoteNotLinked();
        }

        return [$host, $link->remote_container];
    }

    /**
     * Audit trail: who did what on which server. A console command is shortened, the
     * remote output never goes into the log.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function audit(string $action, User $actor, ServerLink $link, array $context = []): void
    {
        Log::info("remote_docker.{$action}", ['user_id' => $actor->id, 'server_id' => $link->id] + $context);
    }
}
