<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\SshRemoteDockerControl;
use App\Modules\GameServers\Testing\FakeRemoteDockerControl;
use App\Modules\Hosts\Models\RemoteHost;

/**
 * Controls one existing Docker container on a managed remote host: power, state, console
 * and logs. The only way the game server code talks to such a container; real SSH sits in
 * {@see SshRemoteDockerControl}, tests use {@see FakeRemoteDockerControl}.
 *
 * Every method validates its own input (container name, command, line count), so a caller
 * cannot smuggle anything into the shell by skipping an earlier check.
 */
interface RemoteDockerControl
{
    /**
     * Start, stop or restart. Kill is not offered.
     *
     * @throws GameServerException
     */
    public function power(RemoteHost $host, string $container, PowerAction $action): void;

    /**
     * Docker's state of the container (`running`, `exited`, ...), null when the host cannot be asked.
     * Never throws.
     */
    public function status(RemoteHost $host, string $container): ?string;

    /**
     * Whether the container exists on the host (running or not).
     *
     * @throws GameServerException when the host cannot be asked
     */
    public function exists(RemoteHost $host, string $container): bool;

    /**
     * Runs one console command in the container and returns its sanitized answer.
     *
     * @throws GameServerException
     */
    public function command(RemoteHost $host, string $container, ConsoleKind $console, string $command): string;

    /**
     * The last `$lines` (1 to 500) log lines, sanitized and size-capped.
     *
     * @throws GameServerException
     */
    public function logs(RemoteHost $host, string $container, int $lines): string;
}
