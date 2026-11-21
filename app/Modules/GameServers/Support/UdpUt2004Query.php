<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\Ut2004Query;
use App\Modules\GameServers\Domain\ServerInfo;

/** UT2004 info query over a plain UDP stream socket; see {@see Ut2004Protocol}. */
final class UdpUt2004Query implements Ut2004Query
{
    public function __construct(private readonly float $timeoutSeconds = 3.0) {}

    public function info(string $host, int $gamePort): ?ServerInfo
    {
        $port = Ut2004Protocol::queryPort($gamePort);

        if ($host === '' || $gamePort < 1 || $port > 65535) {
            return null;
        }

        $target = str_contains($host, ':') ? "udp://[{$host}]:{$port}" : "udp://{$host}:{$port}";
        $socket = @stream_socket_client($target, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            return null;
        }

        try {
            $seconds = (int) floor($this->timeoutSeconds);
            stream_set_timeout($socket, $seconds, (int) (($this->timeoutSeconds - $seconds) * 1_000_000));

            if (@fwrite($socket, Ut2004Protocol::infoRequest()) === false) {
                return null;
            }

            $answer = @fread($socket, 4096);
            $info = is_string($answer) && $answer !== '' ? Ut2004Protocol::parseInfo($answer) : null;

            // Nobody on: nothing to list, and no point in waiting for another answer.
            if ($info === null || $info->players < 1) {
                return $info;
            }

            // Best effort: a silent or odd player answer only means an empty list.
            if (@fwrite($socket, Ut2004Protocol::playersRequest()) === false) {
                return $info;
            }

            $players = @fread($socket, 4096);

            return $info->withPlayerList(is_string($players) && $players !== '' ? (Ut2004Protocol::parsePlayers($players) ?? []) : []);
        } finally {
            fclose($socket);
        }
    }
}
