<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\RemoteDockerGuard;

/**
 * Sends one console command (RCON through the container's console tool) and returns the
 * server's sanitized answer. The command is validated here and again by the control, and
 * reaches the shell as a single escaped argument.
 */
final class SendRemoteConsoleCommand
{
    use AuthorizesRemoteDocker;

    public function __construct(private readonly RemoteDockerControl $control) {}

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link, string $command): string
    {
        [$host, $container] = $this->authorizeRemote($actor, $link);
        $command = RemoteDockerGuard::command($command);

        $this->audit('command', $actor, $link, ['command' => RemoteDockerGuard::auditSnippet($command)]);

        return $this->control->command($host, $container, $link->console_kind ?? throw GameServerException::remoteNotLinked(), $command);
    }
}
