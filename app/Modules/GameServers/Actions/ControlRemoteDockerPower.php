<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;

/**
 * Starts, stops or restarts the remote container of a server (`docker start|stop|restart`),
 * so its data survives. A stop shows as Offline at once; the next scheduled reading confirms the rest.
 */
final class ControlRemoteDockerPower
{
    use AuthorizesRemoteDocker;

    public function __construct(private readonly RemoteDockerControl $control) {}

    /**
     * @return string|null Docker's state after the call (`running`, `exited`, ...), null when unknown.
     *
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link, PowerAction $action): ?string
    {
        [$host, $container] = $this->authorizeRemote($actor, $link);

        $this->audit('power', $actor, $link, ['action' => $action->value]);
        $this->control->power($host, $container, $action);

        if ($action === PowerAction::Stop || $action === PowerAction::Kill) {
            $link->status = ServerLinkStatus::Offline;
            $link->query_info = null;
            $link->save();
        }

        return $this->control->status($host, $container);
    }
}
