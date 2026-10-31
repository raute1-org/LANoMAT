<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/** How a server is asked about its status and players online. */
enum QueryProtocol: string
{
    /** Source engine A2S_INFO over UDP. */
    case A2s = 'a2s';
    /** Minecraft Java Edition Server List Ping over TCP. */
    case Minecraft = 'minecraft';

    public function label(): string
    {
        return __('gameservers.permanent.protocol.'.$this->value);
    }
}
