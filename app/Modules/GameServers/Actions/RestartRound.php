<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use Illuminate\Support\Facades\Log;

/**
 * Restarts the running game on a linked permanent CS2 server through the console:
 * `mp_restartgame 1` (scores reset) or, on a GunGame server, `gg_restart` (the plugin
 * resets the GunGame match). Fixed commands only, nothing from the request.
 */
final class RestartRound
{
    use ConfiguresLinkedServer;

    public const string COMMAND = 'mp_restartgame 1';

    public const string GUNGAME_COMMAND = 'gg_restart';

    public function __construct(private readonly PelicanClient $client) {}

    public static function commandFor(ServerLink $link): string
    {
        return $link->preset_key === GunGameConfig::PRESET_KEY ? self::GUNGAME_COMMAND : self::COMMAND;
    }

    /**
     * @throws GameServerException
     */
    public function handle(User $actor, ServerLink $link): void
    {
        $identifier = $this->authorizeConfigure($actor, $link);
        $this->assertRunning($identifier);

        $this->client->sendCommand($identifier, self::commandFor($link));

        Log::info('Permanent server round restarted.', ['server_link' => $link->id, 'actor' => $actor->id]);
    }
}
