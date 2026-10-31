<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Domain\ServerInfo;

/** Server List Ping over a plain TCP stream socket; see {@see MinecraftPing}. */
final class TcpMinecraftQuery implements MinecraftQuery
{
    public function __construct(private readonly float $timeoutSeconds = 3.0) {}

    public function info(string $host, int $port): ?ServerInfo
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $target = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($target, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            return null;
        }

        try {
            $seconds = (int) floor($this->timeoutSeconds);
            stream_set_timeout($socket, $seconds, (int) (($this->timeoutSeconds - $seconds) * 1_000_000));

            if (@fwrite($socket, MinecraftPing::request($host, $port)) === false) {
                return null;
            }

            $data = '';
            $deadline = microtime(true) + $this->timeoutSeconds;

            while (microtime(true) < $deadline && strlen($data) < MinecraftPing::MAX_RESPONSE_BYTES) {
                $expected = MinecraftPing::expectedLength($data);

                if ($expected !== null && strlen($data) >= $expected) {
                    break;
                }

                $chunk = @fread($socket, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $data .= $chunk;
            }

            return MinecraftPing::parse($data);
        } finally {
            fclose($socket);
        }
    }
}
