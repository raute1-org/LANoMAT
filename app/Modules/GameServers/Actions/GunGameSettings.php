<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Reads and saves the GunGame plugin config of a linked server through the Pelican
 * file API. Saving merges into the files as they are on the server (unknown keys and
 * `_comment.*` survive), writes both, then asks the plugin to reload with
 * `gg_restart` over the console. The file contents are never logged.
 */
final class GunGameSettings
{
    use ConfiguresLinkedServer;

    public function __construct(private readonly PelicanClient $client) {}

    /**
     * The form state from the server's current files.
     *
     * @return array<string, mixed>
     *
     * @throws GameServerException
     */
    public function load(User $actor, ServerLink $link): array
    {
        $identifier = $this->authorizeConfigure($actor, $link);

        return GunGameConfig::formState(
            GunGameConfig::decode($this->client->readFile($identifier, GunGameConfig::GENERAL_FILE), GunGameConfig::GENERAL_FILE),
            GunGameConfig::decode($this->client->readFile($identifier, GunGameConfig::WEAPONS_FILE), GunGameConfig::WEAPONS_FILE),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return bool Whether the running plugin was told to reload (false: the server is down, restart needed).
     *
     * @throws GameServerException
     * @throws ValidationException
     */
    public function save(User $actor, ServerLink $link, array $input): bool
    {
        $identifier = $this->authorizeConfigure($actor, $link);

        $data = GunGameConfig::validate($input);

        // Read both first: an unreadable file must abort before anything is written.
        $general = GunGameConfig::decode($this->client->readFile($identifier, GunGameConfig::GENERAL_FILE), GunGameConfig::GENERAL_FILE);
        $weapons = GunGameConfig::decode($this->client->readFile($identifier, GunGameConfig::WEAPONS_FILE), GunGameConfig::WEAPONS_FILE);

        $this->client->writeFile($identifier, GunGameConfig::GENERAL_FILE, GunGameConfig::encode(GunGameConfig::mergeGeneral($general, $data)));
        $this->client->writeFile($identifier, GunGameConfig::WEAPONS_FILE, GunGameConfig::encode(GunGameConfig::mergeWeapons($weapons, $data)));

        Log::info('GunGame config written.', ['server_link' => $link->id, 'actor' => $actor->id]);

        try {
            $this->client->sendCommand($identifier, 'gg_restart');

            return true;
        } catch (GameServerException) {
            return false;
        }
    }
}
