<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ControlDriver;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\DockerServerControl;
use Illuminate\Support\Facades\Gate;

/**
 * Starts, stops or restarts a permanent server: through the Pelican panel for a
 * Pelican server, through `docker` on its host for a Docker server, over SSH for an
 * external server adopted as a remote container. An external server can only be
 * controlled once it is linked to a Pelican server or a remote container. A stop shows as Offline at once; everything else is
 * confirmed by the next scheduled status reading.
 */
final class PowerPermanentServer
{
    public function __construct(
        private readonly PowerServer $pelican,
        private readonly DockerServerControl $docker,
        private readonly ControlRemoteDockerPower $remote,
    ) {}

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link, PowerAction $action): void
    {
        Gate::forUser($actor)->authorize('power', $link);

        // An adopted container on a remote host is powered over SSH (its own action authorizes and sets the status).
        if ($link->controlDriver() === ControlDriver::RemoteDocker) {
            $this->remote->handle($actor, $link, $action);

            return;
        }

        // An adopted external server (linked to Pelican) is powered through the panel.
        $source = $link->source === ServerSource::External && $link->hasPelicanLink() ? ServerSource::Pelican : $link->source;

        match ($source) {
            ServerSource::Pelican => $this->pelican->handle($link, $action),
            ServerSource::Docker => $this->docker->power(
                $link->customServer ?? throw GameServerException::dockerUnavailable(),
                $action,
                $actor,
            ),
            ServerSource::External => throw GameServerException::notControllable(),
        };

        if ($action === PowerAction::Stop || $action === PowerAction::Kill) {
            $link->status = ServerLinkStatus::Offline;
            $link->query_info = null;
            $link->save();
        }
    }
}
