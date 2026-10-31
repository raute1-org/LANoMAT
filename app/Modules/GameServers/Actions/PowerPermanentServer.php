<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\DockerServerControl;
use Illuminate\Support\Facades\Gate;

/**
 * Starts, stops or restarts a permanent server: through the Pelican panel for a
 * Pelican server, through `docker` on its host for a Docker server. An external
 * server cannot be controlled. A stop shows as Offline at once; everything else is
 * confirmed by the next scheduled status reading.
 */
final class PowerPermanentServer
{
    public function __construct(
        private readonly PowerServer $pelican,
        private readonly DockerServerControl $docker,
    ) {}

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link, PowerAction $action): void
    {
        Gate::forUser($actor)->authorize('power', $link);

        match ($link->source) {
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
