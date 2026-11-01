<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Modules\Identity\Support\SteamActivity;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Domain\ServerRoster;
use App\Modules\Presence\Domain\VoiceLocation;
use App\Modules\Presence\Domain\VoiceSighting;

/**
 * Pure part of the location read model: turns what the sources saw (voice sightings, Steam
 * statuses, server player lists) into one {@see PersonLocation} per user. No IO, no clock (the
 * caller passes `$now`), so every rule is tested with plain arrays.
 *
 * The one hard rule: a name is only ever turned into a person when exactly one person can have
 * that name. An ambiguous name, a name nobody has, or the same name showing up twice among the
 * occupants of a source is left unknown; it is never guessed.
 */
final class LocationResolver
{
    /** Case and whitespace insensitive comparison key of a display name. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param  list<VoiceSighting>  $sightings
     * @param  array<string, array<string, list<int>>>  $voiceNames  provider => normalized server name => candidate user ids
     * @param  array<int, SteamActivity>  $steam  user id => what Steam says they play (only people who share their location)
     * @param  list<ServerRoster>  $rosters
     * @param  array<string, list<int>>  $gameNames  normalized in-game name => candidate user ids
     * @return array<int, PersonLocation> user id => location, only for people who are somewhere
     */
    public static function resolve(array $sightings, array $voiceNames, array $steam, array $rosters, array $gameNames, string $now): array
    {
        $voice = self::voice($sightings, $voiceNames, $now);
        $game = self::game($steam, $rosters, $gameNames, $now);

        $people = [];

        foreach (array_unique([...array_keys($voice), ...array_keys($game)]) as $userId) {
            $people[$userId] = new PersonLocation($userId, array_values($voice[$userId] ?? []), $game[$userId] ?? null);
        }

        return $people;
    }

    /**
     * @param  list<VoiceSighting>  $sightings
     * @param  array<string, array<string, list<int>>>  $voiceNames
     * @return array<int, array<string, VoiceLocation>>
     */
    private static function voice(array $sightings, array $voiceNames, string $now): array
    {
        // A name that two occupants of the same provider carry cannot be told apart: skip both.
        $nameCount = [];

        foreach ($sightings as $sighting) {
            if ($sighting->userId === null && $sighting->name !== null) {
                $nameCount[$sighting->provider][self::normalize($sighting->name)] = ($nameCount[$sighting->provider][self::normalize($sighting->name)] ?? 0) + 1;
            }
        }

        $out = [];

        foreach ($sightings as $sighting) {
            $userId = $sighting->userId;

            if ($userId === null) {
                if ($sighting->name === null) {
                    continue;
                }

                $key = self::normalize($sighting->name);
                $candidates = $voiceNames[$sighting->provider][$key] ?? [];

                if (count($candidates) !== 1 || ($nameCount[$sighting->provider][$key] ?? 0) !== 1) {
                    continue;
                }

                $userId = $candidates[0];
            }

            $location = new VoiceLocation(
                provider: $sighting->provider,
                roomLabel: $sighting->roomLabel,
                roomRef: $sighting->roomRef,
                kind: $sighting->kind,
                audience: $sighting->audience,
                joinPath: $sighting->joinPath,
                since: $now,
            );

            $out[$userId][$location->key()] ??= $location;
        }

        return $out;
    }

    /**
     * @param  array<int, SteamActivity>  $steam
     * @param  list<ServerRoster>  $rosters
     * @param  array<string, list<int>>  $gameNames
     * @return array<int, GameLocation>
     */
    private static function game(array $steam, array $rosters, array $gameNames, string $now): array
    {
        $out = [];

        // 1. Player lists of LANoMAT's own servers: the exact join info is known.
        foreach ($rosters as $roster) {
            $seen = array_count_values(array_map(self::normalize(...), $roster->playerNames));

            foreach ($roster->playerNames as $playerName) {
                $key = self::normalize($playerName);
                $candidates = $gameNames[$key] ?? [];

                if (count($candidates) !== 1 || ($seen[$key] ?? 0) !== 1) {
                    continue;
                }

                $out[$candidates[0]] ??= self::fromRoster(GameLocation::SOURCE_QUERY, $roster, $steam[$candidates[0]]->gameName ?? null, $now);
            }
        }

        // 2. Steam: on one of our servers when the address says so, else just the game.
        foreach ($steam as $userId => $activity) {
            if (isset($out[$userId])) {
                continue;
            }

            $roster = $activity->serverAddress === null ? null : self::rosterAt($rosters, $activity->serverAddress);

            if ($roster !== null) {
                $out[$userId] = self::fromRoster(GameLocation::SOURCE_STEAM, $roster, $activity->gameName, $now);

                continue;
            }

            [$address, $port] = self::splitEndpoint($activity->serverAddress);

            $out[$userId] = new GameLocation(GameLocation::SOURCE_STEAM, $activity->gameName, null, $address, $port, null, $now);
        }

        return $out;
    }

    private static function fromRoster(string $source, ServerRoster $roster, ?string $steamGame, string $now): GameLocation
    {
        return new GameLocation($source, $roster->game ?? $steamGame, $roster->title, $roster->address, $roster->port, $roster->linkId, $now);
    }

    /**
     * @param  list<ServerRoster>  $rosters
     */
    private static function rosterAt(array $rosters, string $endpoint): ?ServerRoster
    {
        foreach ($rosters as $roster) {
            if ($roster->endpoint() === $endpoint) {
                return $roster;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private static function splitEndpoint(?string $endpoint): array
    {
        if ($endpoint === null || preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3}):([0-9]{1,5})$/', $endpoint, $m) !== 1) {
            return [null, null];
        }

        return [$m[1], (int) $m[2]];
    }
}
