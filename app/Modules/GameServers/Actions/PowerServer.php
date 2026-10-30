<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Models\ServerLink;

/**
 * Sends a power signal for a linked Pelican server. The Client API addresses
 * servers by their short identifier; links created before that column existed
 * (or half-provisioned ones) get it resolved from the Application API once
 * and stored. Authorization stays with the caller (Filament `power` policy).
 */
class PowerServer
{
    public function handle(ServerLink $link, PowerAction $action): void
    {
        if ($link->pelican_server_id === null) {
            return;
        }

        $client = app(PelicanClient::class);

        if ($link->pelican_server_identifier === null) {
            $link->pelican_server_identifier = $client->getServer($link->pelican_server_id)->identifier;
            $link->save();
        }

        if ($link->pelican_server_identifier === null) {
            return;
        }

        $client->powerAction($link->pelican_server_identifier, $action);
    }
}
