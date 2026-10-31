<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Asks a Minecraft Java Edition server about itself (Server List Ping over TCP).
 * Injectable so tests never open a socket: `TcpMinecraftQuery` is the real
 * implementation, the fake lives in `Testing/FakeMinecraftQuery`.
 */
interface MinecraftQuery
{
    /**
     * Null when the server did not answer in time or answered something that is
     * not a valid status response. Never throws for network trouble. The
     * returned {@see ServerInfo} carries the MOTD as name, the version as game
     * and an empty map.
     */
    public function info(string $host, int $port): ?ServerInfo;
}
