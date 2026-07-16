<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

/**
 * A power signal sent to a Pelican server via the Client API's
 * `POST /api/client/servers/{uuid}/power` endpoint (`{"signal": "..."}`).
 */
enum PowerAction: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';
    case Kill = 'kill';
}
