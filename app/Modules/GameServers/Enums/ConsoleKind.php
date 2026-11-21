<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/**
 * The console tool inside a remote container. An allowlist on purpose: only a case
 * here can ever become part of a `docker exec` command line.
 */
enum ConsoleKind: string
{
    /** itzg/minecraft-server ships `rcon-cli`, RCON itself is never exposed publicly. */
    case RconCli = 'rcon-cli';

    /** The program `docker exec` runs in the container. */
    public function program(): string
    {
        return match ($this) {
            self::RconCli => 'rcon-cli',
        };
    }
}
