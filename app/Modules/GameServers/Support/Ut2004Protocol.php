<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Unreal Tournament 2004 query protocol as pure functions (no IO).
 *
 * Request: `80 00 00 00 <type>`; type 0 asks for the basic info. Answer: the
 * same four header bytes and type byte, then little-endian int32 server id,
 * int32 game port, int32 ip, three length-prefixed strings (one length byte
 * counting the trailing NUL: name, map, game type) and int32 players, int32
 * max players, int32 ping, int32 flags. The query port is the game port + 1.
 * Player names (type 2) are deliberately never asked for.
 */
final class Ut2004Protocol
{
    public const string HEADER = "\x80\x00\x00\x00";

    public const int QUERY_PORT_OFFSET = 1;

    public const int MAX_PLAYERS = 256;

    public static function queryPort(int $gamePort): int
    {
        return $gamePort + self::QUERY_PORT_OFFSET;
    }

    public static function infoRequest(): string
    {
        return self::HEADER."\x00";
    }

    public static function playersRequest(): string
    {
        return self::HEADER."\x02";
    }

    /**
     * Player list answer (type 2): header, type, a count byte, then per player an
     * int32 id, a length-prefixed name, int32 ping, int32 score and int32 stats id.
     * The server does not report the connected time. A malformed or truncated entry
     * ends the list (what was read before stays); never throws. Null when it is no
     * player answer.
     *
     * @return list<array{name: string, score: int|null, seconds: int|null}>|null
     */
    public static function parsePlayers(string $packet): ?array
    {
        if (strlen($packet) < 6 || ! str_starts_with($packet, self::HEADER) || $packet[4] !== "\x02") {
            return null;
        }

        $count = ord($packet[5]);
        $offset = 6;
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            if (strlen($packet) < $offset + 4) {
                break;
            }

            $offset += 4; // player id
            $name = self::readString($packet, $offset);

            if ($name === null || strlen($packet) < $offset + 12) {
                break;
            }

            /** @var array{ping: int, score: int}|false $numbers */
            $numbers = unpack('lping/lscore', $packet, $offset);
            $offset += 12; // ping, score, stats id

            if ($numbers === false) {
                break;
            }

            $rows[] = ['name' => $name, 'score' => $numbers['score'], 'seconds' => null];
        }

        return PlayerList::normalize($rows);
    }

    public static function parseInfo(string $packet): ?ServerInfo
    {
        // header(4) + type(1) + id(4) + port(4) + ip(4)
        if (strlen($packet) < 17 || ! str_starts_with($packet, self::HEADER) || $packet[4] !== "\x00") {
            return null;
        }

        $offset = 17;
        $strings = [];

        foreach (range(1, 3) as $ignored) {
            $string = self::readString($packet, $offset);

            if ($string === null) {
                return null;
            }

            $strings[] = $string;
        }

        // players + max players (ping and flags follow but are not needed)
        if (strlen($packet) < $offset + 8) {
            return null;
        }

        /** @var array{players: int, max: int}|false $numbers */
        $numbers = unpack('Vplayers/Vmax', $packet, $offset);

        if ($numbers === false || $numbers['players'] > self::MAX_PLAYERS || $numbers['max'] > self::MAX_PLAYERS) {
            return null;
        }

        return new ServerInfo(
            name: $strings[0],
            map: $strings[1],
            folder: 'ut2004',
            game: $strings[2],
            players: $numbers['players'],
            maxPlayers: $numbers['max'],
            bots: 0,
        );
    }

    /**
     * Reads one length-prefixed, NUL-terminated string. Null for a length that
     * overruns the packet, a missing NUL, or a high-bit (UCS-2) length.
     */
    private static function readString(string $packet, int &$offset): ?string
    {
        if (strlen($packet) <= $offset) {
            return null;
        }

        $length = ord($packet[$offset]);
        $offset++;

        if ($length >= 0x80 || strlen($packet) < $offset + $length) {
            return null;
        }

        if ($length === 0) {
            return '';
        }

        $raw = substr($packet, $offset, $length);
        $offset += $length;

        if (! str_ends_with($raw, "\x00")) {
            return null;
        }

        return self::clean(substr($raw, 0, -1));
    }

    /** Drops colour codes (0x1b + rgb) and control bytes; the wire text is Latin-1. */
    private static function clean(string $raw): string
    {
        $withoutColour = (string) preg_replace('/\x1b.{3}/s', '', $raw);
        $withoutControl = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $withoutColour);

        return trim(mb_convert_encoding($withoutControl, 'UTF-8', 'ISO-8859-1'));
    }
}
