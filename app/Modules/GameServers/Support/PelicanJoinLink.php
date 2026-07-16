<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\Voice\Support\MumbleJoinLink;

/**
 * Builds a player-facing "join this server" string from a {@see JoinInfo}:
 * a `steam://connect/…` deep link when address+port are known (opening it
 * launches Steam and connects directly, the Source-engine equivalent of
 * {@see MumbleJoinLink}'s `mumble://` link), or —
 * lacking either — a plain copyable `address:port` string. Used both by the
 * match page ("Verbinden" button) and the Discord match embed's server
 * section.
 */
class PelicanJoinLink
{
    public static function for(JoinInfo $info): string
    {
        if ($info->connectString !== null) {
            return $info->connectString;
        }

        if ($info->address !== null && $info->port !== null) {
            return "steam://connect/{$info->address}:{$info->port}";
        }

        if ($info->address !== null) {
            return $info->address;
        }

        return '';
    }
}
