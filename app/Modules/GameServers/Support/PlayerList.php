<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

/**
 * Normalises the "who is on the server" list reported by a game server query.
 * The names are in-game nicknames (not LANoMAT accounts, never linked to one),
 * so they are treated as untrusted text: control characters and markup are
 * dropped, names are cut to {@see self::MAX_NAME} characters and the list to
 * {@see self::MAX_ENTRIES} entries, best score first.
 */
final class PlayerList
{
    public const int MAX_ENTRIES = 64;

    public const int MAX_NAME = 32;

    /**
     * @param  iterable<mixed>  $rows  Each row an array with `name` and optional `score` / `seconds`.
     * @return list<array{name: string, score: int|null, seconds: int|null}>
     */
    public static function normalize(iterable $rows): array
    {
        $list = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = self::name(is_string($row['name'] ?? null) ? $row['name'] : '');

            if ($name === '') {
                continue;
            }

            $list[] = [
                'name' => $name,
                'score' => is_int($row['score'] ?? null) ? $row['score'] : null,
                'seconds' => is_int($row['seconds'] ?? null) && $row['seconds'] >= 0 ? $row['seconds'] : null,
            ];
        }

        usort($list, static fn (array $a, array $b): int => [$b['score'] ?? PHP_INT_MIN, $a['name']] <=> [$a['score'] ?? PHP_INT_MIN, $b['name']]);

        return array_slice($list, 0, self::MAX_ENTRIES);
    }

    public static function name(string $raw): string
    {
        $text = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $text);
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim((string) preg_replace('/[<>]/', '', $text));

        return mb_substr($text, 0, self::MAX_NAME);
    }
}
