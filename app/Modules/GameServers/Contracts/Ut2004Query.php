<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Asks an Unreal Tournament 2004 server about itself (UT2004 query protocol over
 * UDP). Callers pass the GAME port; the implementation derives the query port.
 * Injectable so tests never open a socket: `UdpUt2004Query` is the real
 * implementation, the fake lives in `Testing/FakeUt2004Query`.
 */
interface Ut2004Query
{
    /**
     * Null when the server did not answer in time or answered something that is
     * not a valid info response. Never throws for network trouble.
     */
    public function info(string $host, int $gamePort): ?ServerInfo;
}
