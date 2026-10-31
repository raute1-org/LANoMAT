<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a permanent server. A Pelican server is deleted on the panel first
 * (data included); when that fails the entry stays. A Docker or external server
 * only loses its entry here, the container/server itself keeps running.
 */
final class DeletePermanentServer
{
    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link): void
    {
        Gate::forUser($actor)->authorize('delete', $link);

        if (! $link->permanent) {
            throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.not_permanent');
        }

        if ($link->source === ServerSource::Pelican && $link->pelican_server_id !== null) {
            $this->client->deleteServer($link->pelican_server_id);
        }

        $link->delete();
    }
}
