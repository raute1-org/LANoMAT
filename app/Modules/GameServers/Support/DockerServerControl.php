<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Models\User;
use App\Modules\CustomServers\Actions\StartCustomServer;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

/**
 * Controls the Docker container behind a permanent server of source `docker`: a
 * {@see CustomServer} (container definition) on a managed remote host, reached
 * through the {@see RemoteExecutor}.
 *
 * Unlike the Custom Servers admin ("Stop" removes the container) a permanent
 * server is stopped and restarted with `docker stop`/`docker restart`, so the
 * container's data (a Minecraft world, say) survives. Only when the container
 * does not exist yet (first start, or someone removed it) is it created with
 * `docker run` through {@see StartCustomServer}.
 */
final class DockerServerControl
{
    public function __construct(
        private readonly RemoteExecutor $executor,
        private readonly Modules $modules,
        private readonly StartCustomServer $starter,
    ) {}

    /**
     * Whether the container runs: null when the host cannot be asked (never throws).
     */
    public function isRunning(CustomServer $server): ?bool
    {
        $host = $server->host;

        if ($host === null || ! $this->modules->enabled(Module::CustomServers)) {
            return null;
        }

        try {
            $result = $this->executor->run($host, "docker inspect -f '{{.State.Running}}' ".escapeshellarg($server->container_name));
        } catch (RemoteExecutionException) {
            return null;
        }

        if ($result->ok()) {
            return str_contains($result->stdout, 'true');
        }

        // A missing container is a definite "not running"; any other failure means we do not know.
        return str_contains(strtolower($result->stderr), 'no such') ? false : null;
    }

    /**
     * @throws GameServerException when the host is unreachable, the module is off, or the command failed
     */
    public function power(CustomServer $server, PowerAction $action, User $actor): void
    {
        $host = $server->host;

        if ($host === null || ! $this->modules->enabled(Module::CustomServers)) {
            throw GameServerException::dockerUnavailable();
        }

        $name = escapeshellarg($server->container_name);
        $command = match ($action) {
            PowerAction::Start => "docker start {$name}",
            PowerAction::Stop => "docker stop {$name}",
            PowerAction::Restart => "docker restart {$name}",
            PowerAction::Kill => "docker kill {$name}",
        };

        try {
            $result = $this->executor->run($host, $command);

            if (! $result->ok() && in_array($action, [PowerAction::Start, PowerAction::Restart], true)
                && str_contains(strtolower($result->stderr), 'no such')) {
                // The container does not exist (yet): create it from its definition.
                $this->starter->handle($server, $actor);

                return;
            }
        } catch (RemoteExecutionException $e) {
            throw GameServerException::dockerUnavailable($e->getMessage());
        }

        if (! $result->ok()) {
            throw GameServerException::dockerUnavailable(trim($result->stderr));
        }
    }
}
