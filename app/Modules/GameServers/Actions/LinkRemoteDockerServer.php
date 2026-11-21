<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\RemoteDockerGuard;
use App\Modules\Hosts\Models\RemoteHost;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Log;

/**
 * Adopts a registered external permanent server into remote Docker control: stores the
 * managed host and the container name (and its console tool) on the existing link, after
 * checking that the host has a pinned key and the container exists.
 *
 * Like {@see LinkPelicanServer} the server stays `source=External` (registered address and
 * probing unchanged, removing the entry never touches the container), and the caller
 * authorizes: the artisan command is an operator tool, the Filament action checks
 * `managePermanent` (admin). A server that is already linked to Pelican is refused, the
 * panel link would win anyway and two control paths would only confuse.
 */
final class LinkRemoteDockerServer
{
    public function __construct(
        private readonly RemoteDockerControl $control,
        private readonly Modules $modules,
    ) {}

    /**
     * @throws GameServerException
     */
    public function handle(ServerLink $link, RemoteHost $host, string $container, ConsoleKind $console = ConsoleKind::RconCli): void
    {
        if (! $link->permanent) {
            throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.not_permanent');
        }

        if ($link->source !== ServerSource::External) {
            throw GameServerException::permanentInputInvalid('gameservers.settings.errors.only_external');
        }

        if ($link->hasPelicanLink()) {
            throw GameServerException::permanentInputInvalid('gameservers.remote.errors.already_pelican');
        }

        $container = RemoteDockerGuard::container($container);

        // A host without a pinned key is never trusted for control, see the Hosts module.
        if (! $this->modules->enabled(Module::Hosts) || $host->host_fingerprint === null) {
            throw GameServerException::permanentInputInvalid('gameservers.remote.errors.host_not_linkable');
        }

        if (! $this->control->exists($host, $container)) {
            throw GameServerException::remoteContainerMissing();
        }

        $link->remote_host_id = $host->id;
        $link->remote_container = $container;
        $link->console_kind = $console;
        $link->save();

        Log::info('remote_docker.linked', ['server_id' => $link->id, 'host_id' => $host->id]);
    }
}
