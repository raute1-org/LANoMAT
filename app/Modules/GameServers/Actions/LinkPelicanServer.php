<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;

/**
 * Adopts a registered external permanent server into the Pelican panel's
 * management: stores the panel server's numeric id and short identifier on the
 * existing link, so LANoMAT can write its startup variables and files, run console
 * commands and send power signals.
 *
 * Decision: the link stays `source=External`. The server keeps its registered
 * address and A2S probing, and removing the entry never deletes the panel server
 * ({@see DeletePermanentServer} only deletes for `source=Pelican`). The existing
 * `pelican_server_id`/`pelican_server_identifier` columns carry the link, see
 * {@see ServerLink::hasPelicanLink()}. No new table, no source change.
 *
 * Authorization is the caller's: the artisan command is an operator tool, the
 * Filament action checks `managePermanent` (admin).
 */
final class LinkPelicanServer
{
    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @param  string  $reference  The panel server's numeric id, short identifier or full uuid.
     *
     * @throws GameServerException
     */
    public function handle(ServerLink $link, string $reference, bool $gunGame = false): PelicanServer
    {
        if (! $link->permanent) {
            throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.not_permanent');
        }

        if ($link->source !== ServerSource::External) {
            throw GameServerException::permanentInputInvalid('gameservers.settings.errors.only_external');
        }

        $reference = strtolower(trim($reference));
        $server = null;

        foreach ($this->client->listServers() as $candidate) {
            if ($reference !== '' && in_array($reference, [$candidate->id, strtolower((string) $candidate->identifier), strtolower((string) $candidate->uuid)], true)) {
                $server = $candidate;

                break;
            }
        }

        if ($server === null || $server->identifier === null) {
            throw GameServerException::pelicanNotFound("server {$reference}");
        }

        $link->pelican_server_id = $server->id;
        $link->pelican_server_identifier = $server->identifier;

        if ($gunGame) {
            $link->preset_key = GunGameConfig::PRESET_KEY;
        }

        $link->save();

        return $server;
    }
}
