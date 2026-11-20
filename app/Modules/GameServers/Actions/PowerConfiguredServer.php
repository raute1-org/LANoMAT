<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;

/**
 * Start/stop/restart from the settings page: the same panel call as
 * {@see PowerPermanentServer}, but authorized by `configure` (orga and admin) instead of
 * the admin-only `power`, and only for a server with a Pelican link.
 */
final class PowerConfiguredServer
{
    use ConfiguresLinkedServer;

    public function __construct(private readonly PowerServer $power) {}

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link, PowerAction $action): void
    {
        $this->authorizeConfigure($actor, $link);

        $this->power->handle($link, $action);

        if ($action === PowerAction::Stop || $action === PowerAction::Kill) {
            $link->status = ServerLinkStatus::Offline;
            $link->query_info = null;
            $link->save();
        }
    }
}
