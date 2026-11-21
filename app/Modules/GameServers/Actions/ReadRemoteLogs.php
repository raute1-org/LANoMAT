<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\RemoteDockerGuard;

/** Reads the last lines of the remote container's log (sanitized, size-capped). The text itself is never logged. */
final class ReadRemoteLogs
{
    use AuthorizesRemoteDocker;

    public const DEFAULT_LINES = 100;

    public function __construct(private readonly RemoteDockerControl $control) {}

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link, int $lines = self::DEFAULT_LINES): string
    {
        [$host, $container] = $this->authorizeRemote($actor, $link);
        $lines = RemoteDockerGuard::lines($lines);

        $this->audit('logs', $actor, $link, ['lines' => $lines]);

        return $this->control->logs($host, $container, $lines);
    }
}
