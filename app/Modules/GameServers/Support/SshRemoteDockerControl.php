<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

/**
 * {@see RemoteDockerControl} over the {@see RemoteExecutor} (no SSH code of its own).
 *
 * Shell safety: every command is assembled from fixed words plus values that went through
 * {@see RemoteDockerGuard} and then `escapeshellarg`. A console command is exactly ONE
 * shell argument, so `;`, `&&`, `$()` or backticks inside it are plain text for the
 * console tool, never shell syntax. Nothing here reads a container's environment (it
 * holds the RCON password): `docker inspect` only ever uses the state format.
 */
final class SshRemoteDockerControl implements RemoteDockerControl
{
    /** Seconds to wait for output of a quick command. */
    private const QUICK_TIMEOUT = 15;

    /** `docker stop` waits for the game to save and shut down. */
    private const POWER_TIMEOUT = 120;

    public function __construct(
        private readonly RemoteExecutor $executor,
        private readonly Modules $modules,
    ) {}

    public function power(RemoteHost $host, string $container, PowerAction $action): void
    {
        $verb = match ($action) {
            PowerAction::Start => 'start',
            PowerAction::Stop => 'stop',
            PowerAction::Restart => 'restart',
            PowerAction::Kill => throw GameServerException::remoteInvalid('command'),
        };

        $this->succeed($this->run($host, "docker {$verb} ".escapeshellarg(RemoteDockerGuard::container($container)), self::POWER_TIMEOUT));
    }

    public function status(RemoteHost $host, string $container): ?string
    {
        try {
            $result = $this->run($host, $this->inspectCommand($container), self::QUICK_TIMEOUT);
        } catch (GameServerException) {
            return null;
        }

        $state = trim($result->stdout);

        return $result->ok() && preg_match('/^[a-z]{3,16}$/', $state) === 1 ? $state : null;
    }

    public function exists(RemoteHost $host, string $container): bool
    {
        $result = $this->run($host, $this->inspectCommand($container), self::QUICK_TIMEOUT);

        if ($result->ok()) {
            return true;
        }

        if ($this->says($result, 'no such')) {
            return false;
        }

        throw GameServerException::remoteCommandFailed();
    }

    public function command(RemoteHost $host, string $container, ConsoleKind $console, string $command): string
    {
        $name = escapeshellarg(RemoteDockerGuard::container($container));
        $argument = escapeshellarg(RemoteDockerGuard::command($command));

        $result = $this->run($host, "docker exec {$name} {$console->program()} {$argument}", self::QUICK_TIMEOUT);
        $this->succeed($result);

        return RemoteDockerGuard::sanitizeOutput($result->stdout.$result->stderr);
    }

    public function logs(RemoteHost $host, string $container, int $lines): string
    {
        $name = escapeshellarg(RemoteDockerGuard::container($container));
        $result = $this->run($host, 'docker logs --tail '.RemoteDockerGuard::lines($lines)." {$name}", self::QUICK_TIMEOUT);
        $this->succeed($result);

        // A container writes its console to both streams; docker logs replays them separately.
        return RemoteDockerGuard::sanitizeOutput($result->stdout.$result->stderr);
    }

    /** State format only: the full inspect dump contains the container's environment. */
    private function inspectCommand(string $container): string
    {
        return "docker inspect -f '{{.State.Status}}' ".escapeshellarg(RemoteDockerGuard::container($container));
    }

    /**
     * @throws GameServerException
     */
    private function run(RemoteHost $host, string $command, int $timeout): CommandResult
    {
        if (! $this->modules->enabled(Module::Hosts)) {
            throw GameServerException::remoteUnavailable();
        }

        try {
            return $this->executor->run($host, $command, $timeout);
        } catch (RemoteExecutionException) {
            throw GameServerException::remoteUnavailable();
        }
    }

    /**
     * @throws GameServerException
     */
    private function succeed(CommandResult $result): void
    {
        if ($result->ok()) {
            return;
        }

        throw match (true) {
            $this->says($result, 'no such') => GameServerException::remoteContainerMissing(),
            $this->says($result, 'is not running') => GameServerException::remoteNotRunning(),
            default => GameServerException::remoteCommandFailed(),
        };
    }

    private function says(CommandResult $result, string $needle): bool
    {
        return str_contains(strtolower($result->stderr), $needle);
    }
}
