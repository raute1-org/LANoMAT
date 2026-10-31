<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\ServerInfo;

/**
 * A2S_INFO over a plain UDP stream socket (no ext-sockets needed). Handles the
 * challenge round trip newer servers demand (an `S2C_CHALLENGE` answer is
 * answered by repeating the request with the four challenge bytes appended).
 */
final class UdpServerQuery implements ServerQuery
{
    public function __construct(private readonly float $timeoutSeconds = 3.0) {}

    public function info(string $host, int $port): ?ServerInfo
    {
        if ($host === '' || $port < 1 || $port > 65535) {
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

            $answer = $this->exchange($socket, SourceQueryParser::REQUEST);
            $challenge = $answer === null ? null : SourceQueryParser::challenge($answer);

            if ($challenge !== null) {
                $answer = $this->exchange($socket, SourceQueryParser::REQUEST.$challenge);
            }

            return $answer === null ? null : SourceQueryParser::parseInfo($answer);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function exchange($socket, string $request): ?string
    {
        if (@fwrite($socket, $request) === false) {
            return null;
        }

        $answer = @fread($socket, 4096);

        return is_string($answer) && $answer !== '' ? $answer : null;
    }
}
