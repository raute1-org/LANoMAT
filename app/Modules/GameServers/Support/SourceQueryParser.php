<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Pure parser for the Source engine query protocol (A2S_INFO): no IO, tested
 * with byte fixtures. Wire format: `FF FF FF FF`, a type byte (`0x49` info,
 * `0x41` challenge), then for info the protocol byte, four NUL terminated strings
 * (name, map, folder, game), the app id (2 bytes), players, max players and bots.
 */
final class SourceQueryParser
{
    public const string REQUEST = "\xff\xff\xff\xffTSource Engine Query\x00";

    private const string HEADER = "\xff\xff\xff\xff";

    /**
     * The four challenge bytes of an S2C_CHALLENGE answer, or null for any other packet.
     */
    public static function challenge(string $packet): ?string
    {
        if (strlen($packet) >= 9 && str_starts_with($packet, self::HEADER) && $packet[4] === "\x41") {
            return substr($packet, 5, 4);
        }

        return null;
    }

    public static function parseInfo(string $packet): ?ServerInfo
    {
        if (strlen($packet) < 6 || ! str_starts_with($packet, self::HEADER) || $packet[4] !== "\x49") {
            return null;
        }

        $offset = 6;
        $strings = [];

        foreach (range(1, 4) as $ignored) {
            $end = strpos($packet, "\0", $offset);

            if ($end === false) {
                return null;
            }

            $strings[] = substr($packet, $offset, $end - $offset);
            $offset = $end + 1;
        }

        // app id (2 bytes) + players + max players + bots
        if (strlen($packet) < $offset + 5) {
            return null;
        }

        return new ServerInfo(
            name: self::utf8($strings[0]),
            map: self::utf8($strings[1]),
            folder: self::utf8($strings[2]),
            game: self::utf8($strings[3]),
            players: ord($packet[$offset + 2]),
            maxPlayers: ord($packet[$offset + 3]),
            bots: ord($packet[$offset + 4]),
        );
    }

    private static function utf8(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
