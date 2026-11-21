<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Support\Facades\Gate;

/**
 * Shared guard of the settings actions: the actor may configure this permanent
 * server (`ServerLinkPolicy::configure`, orga and admin) and it has a Pelican link.
 */
trait ConfiguresLinkedServer
{
    /**
     * @return string The server's Client API identifier.
     *
     * @throws GameServerException
     */
    private function authorizeConfigure(User $actor, ServerLink $link): string
    {
        Gate::forUser($actor)->authorize('configure', $link);

        return $link->pelican_server_identifier !== null && $link->pelican_server_id !== null
            ? $link->pelican_server_identifier
            : throw GameServerException::notLinkedToPelican();
    }

    /**
     * Console commands only reach a running server.
     *
     * @throws GameServerException
     */
    private function assertRunning(string $identifier): void
    {
        if (app(PelicanClient::class)->getResources($identifier)->state !== ServerState::Running) {
            throw GameServerException::notRunning();
        }
    }
}
