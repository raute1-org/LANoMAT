<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use Illuminate\Support\Facades\Log;

/**
 * Reloads the current map of a running server (`changelevel <map>`). The map is the one
 * from the last A2S answer and is only used when it is on the standard-map allowlist,
 * so a workshop map (unknown name) simply cannot be reloaded this way.
 */
final class ReloadMap
{
    use ConfiguresLinkedServer;

    public function __construct(private readonly PelicanClient $client) {}

    /** The current standard map from the last query, or null when unknown. */
    public static function currentMap(ServerLink $link): ?string
    {
        $map = $link->query_info['map'] ?? null;

        return is_string($map) && in_array($map, Cs2Settings::MAPS, true) ? $map : null;
    }

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link): void
    {
        $identifier = $this->authorizeConfigure($actor, $link);
        $map = self::currentMap($link) ?? throw GameServerException::mapUnknown();
        $this->assertRunning($identifier);

        $this->client->sendCommand($identifier, Cs2Settings::mapCommand($map, null));

        Log::info('Permanent server map reloaded.', ['server_link' => $link->id, 'actor' => $actor->id]);
    }
}
