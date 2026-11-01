<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Pure packet building and parsing of the Minecraft Java Edition Server List
 * Ping (no IO, so it is tested with crafted bytes): a handshake with next state
 * 1, an empty status request, and a status response carrying a JSON document
 * with version, players and description.
 */
final class MinecraftPing
{
    /** Upper bound for an answer we are willing to read (a favicon makes them a few KB). */
    public const int MAX_RESPONSE_BYTES = 262144;

    public static function varInt(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $out = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $out;
    }

    /**
     * Reads a VarInt at `$offset` (advanced past it); null when the bytes end first or it is too long.
     */
    public static function readVarInt(string $data, int &$offset): ?int
    {
        $result = 0;

        for ($i = 0; $i < 5; $i++) {
            if (! isset($data[$offset])) {
                return null;
            }

            $byte = ord($data[$offset++]);
            $result |= ($byte & 0x7F) << (7 * $i);

            if (($byte & 0x80) === 0) {
                return $result;
            }
        }

        return null;
    }

    /** Handshake packet (next state: status) followed by the status request packet. */
    public static function request(string $host, int $port): string
    {
        $payload = self::varInt(0)
            .self::varInt(0xFFFFFFFF) // protocol -1: "I just want the status"
            .self::varInt(strlen($host)).$host
            .pack('n', $port)
            .self::varInt(1);

        return self::varInt(strlen($payload)).$payload.self::varInt(1).self::varInt(0);
    }

    /**
     * Total number of bytes the answer will have (length prefix included), or null while the
     * prefix is still incomplete.
     */
    public static function expectedLength(string $data): ?int
    {
        $offset = 0;
        $length = self::readVarInt($data, $offset);

        return $length === null ? null : $offset + $length;
    }

    public static function parse(string $data): ?ServerInfo
    {
        $offset = 0;
        $length = self::readVarInt($data, $offset);

        if ($length === null || $length < 2 || strlen($data) < $offset + $length) {
            return null;
        }

        $packetId = self::readVarInt($data, $offset);
        $jsonLength = self::readVarInt($data, $offset);

        if ($packetId !== 0 || $jsonLength === null || strlen($data) < $offset + $jsonLength) {
            return null;
        }

        $status = json_decode(substr($data, $offset, $jsonLength), true);

        if (! is_array($status) || ! is_array($status['players'] ?? null)) {
            return null;
        }

        $players = $status['players'];
        $version = is_array($status['version'] ?? null) ? (string) ($status['version']['name'] ?? '') : '';

        return new ServerInfo(
            name: self::motd($status['description'] ?? ''),
            map: '',
            folder: 'minecraft',
            game: $version,
            players: (int) ($players['online'] ?? 0),
            maxPlayers: (int) ($players['max'] ?? 0),
            playerNames: self::sampleNames($players['sample'] ?? null),
        );
    }

    /**
     * The names in the status `players.sample`. Servers may leave it out, cap it (vanilla shows at
     * most 12) or fill it with a fake entry such as a hint text; entries whose id is the all-zero
     * UUID are such decoys and are skipped.
     *
     * @return list<string>
     */
    private static function sampleNames(mixed $sample): array
    {
        if (! is_array($sample)) {
            return [];
        }

        $names = [];

        foreach ($sample as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || $entry['name'] === '') {
                continue;
            }

            if (($entry['id'] ?? null) === '00000000-0000-0000-0000-000000000000') {
                continue;
            }

            $names[] = mb_substr(preg_replace('/§[0-9a-fk-or]/iu', '', $entry['name']) ?? '', 0, 32);
        }

        return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
    }

    /**
     * The description is a plain string or a chat component (`text` plus nested `extra`);
     * formatting codes are stripped.
     */
    private static function motd(mixed $description): string
    {
        $text = self::component($description);
        $text = preg_replace('/§[0-9a-fk-or]/iu', '', $text) ?? '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 120);
    }

    private static function component(mixed $component): string
    {
        if (is_string($component)) {
            return $component;
        }

        if (! is_array($component)) {
            return '';
        }

        $text = is_string($component['text'] ?? null) ? $component['text'] : '';

        foreach (is_array($component['extra'] ?? null) ? $component['extra'] : [] as $child) {
            $text .= self::component($child);
        }

        return $text;
    }
}
