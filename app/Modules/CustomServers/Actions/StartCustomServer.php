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
 * Starts an orga-defined docker "escape hatch" game server by composing a
 * `docker run` command from {@see CustomServer}'s structured fields and
 * running it on the linked {@see RemoteHost} via
 * {@see RemoteExecutor}.
 *
 * SECURITY: every value that becomes part of the shell command — image,
 * command, ports, env, container_name — is passed through
 * {@see escapeshellarg()} individually. Nothing is string-interpolated raw;
 * a malicious `command` value like `; rm -rf /` becomes a single quoted
 * token handed to the shell as one argument, not a metacharacter break-out.
 * See tests/Feature/CustomServers/CustomServerLifecycleTest.php's injection
 * guard test.
 */
class StartCustomServer
{
    public function __construct(
        private readonly RemoteExecutor $executor,
    ) {}

    public function handle(CustomServer $server, User $actor): CustomServer
    {
        Gate::forUser($actor)->authorize('start', $server);

        $command = $this->buildDockerRunCommand($server);

        $host = $server->host ?? throw new LogicException(
            "CustomServer {$server->id} references a missing remote_host_id={$server->remote_host_id}.",
        );

        $result = $this->executor->run($host, $command);

        if ($result->ok()) {
            $server->status = CustomServerStatus::Running;
            $server->last_output = null;
        } else {
            $server->status = CustomServerStatus::Failed;
            $server->last_output = trim($result->stderr);
        }

        $server->save();

        return $server;
    }

    /**
     * Builds `docker run -d --name {container_name} [-p ports] [-e KEY=VAL
     * ...] {image} [command]` with every dynamic value escapeshellarg-quoted
     * — never a raw string spliced in unescaped.
     */
    private function buildDockerRunCommand(CustomServer $server): string
    {
        $parts = ['docker', 'run', '-d', '--name', escapeshellarg($server->container_name)];

        if (filled($server->ports)) {
            $parts[] = '-p';
            $parts[] = escapeshellarg($server->ports);
        }

        if (is_array($server->env)) {
            /** @var array<string, string> $env */
            $env = $server->env;
            foreach ($env as $key => $value) {
                $parts[] = '-e';
                $parts[] = escapeshellarg("{$key}={$value}");
            }
        }

        $parts[] = escapeshellarg($server->image);

        if (filled($server->command)) {
            $parts[] = escapeshellarg($server->command);
        }

        return implode(' ', $parts);
    }
}
