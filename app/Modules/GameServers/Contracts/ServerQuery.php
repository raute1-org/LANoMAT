<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Asks a running game server about itself (Source engine A2S_INFO over UDP).
 * Injectable so tests never open a socket: {@see UdpServerQuery} is the real
 * implementation, the fake lives in `Testing/FakeServerQuery`.
 */
interface ServerQuery
{
    /**
     * Null when the server did not answer in time or answered something that is
     * not a valid info response. Never throws for network trouble.
     */
    public function info(string $host, int $port): ?ServerInfo;
}
