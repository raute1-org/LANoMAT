<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Enums\ServerLinkStatus;

/**
 * Per-game summary of the servers people can play on right now: the event's
 * servers merged with the permanent ones, built on {@see ServerListProjection::dto()}
 * so status and game resolution stay in one place. Only Ready and NotResponding
 * servers count, the same set the public server list shows; players are summed over Ready
 * servers only (a NotResponding server's query data is stale).
 *
 * Feeds the "Jetzt" home page's now-playing block and the nav badge.
 */
class ServerGameSummary
{
    /** @var list<ServerLinkStatus> */
    private const LIVE = [ServerLinkStatus::Ready, ServerLinkStatus::NotResponding];

    /**
     * Groups come out in first-appearance order, unsorted; the consumer sorts them.
     *
     * @return list<array{game: string, servers: int, players: int, maxPlayers: int, join: array{serverId: int, title: string, connectString: string, connectIsLink: bool, address: string|null, port: int|null, players: int, maxPlayers: int}|null}>
     */
    public static function forEvent(?Event $event): array
    {
        /** @var array<string, array{game: string, servers: int, players: int, maxPlayers: int, join: array{serverId: int, title: string, connectString: string, connectIsLink: bool, address: string|null, port: int|null, players: int, maxPlayers: int}|null, candidates: list<array<string, mixed>>}> $groups */
        $groups = [];

        foreach (self::liveDtos($event) as $dto) {
            $label = self::label($dto);

            if ($label === null) {
                continue;
            }

            $players = self::countedPlayers($dto);
            $maxPlayers = self::queryCount($dto, 'maxPlayers');

            $groups[$label] ??= ['game' => $label, 'servers' => 0, 'players' => 0, 'maxPlayers' => 0, 'join' => null, 'candidates' => []];
            $groups[$label]['servers']++;
            $groups[$label]['players'] += $players;
            $groups[$label]['maxPlayers'] += $maxPlayers;

            if (self::joinable($dto)) {
                $groups[$label]['candidates'][] = $dto;
            }
        }

        return array_values(array_map(static function (array $group): array {
            $join = self::pickJoin($group['candidates']);

            return [
                'game' => $group['game'],
                'servers' => $group['servers'],
                'players' => $group['players'],
                'maxPlayers' => $group['maxPlayers'],
                'join' => $join,
            ];
        }, $groups));
    }

    /** Players on all live servers of the event and the permanent ones (nav badge). */
    public static function totalPlayers(?Event $event): int
    {
        $total = 0;

        foreach (self::liveDtos($event) as $dto) {
            $total += self::countedPlayers($dto);
        }

        return $total;
    }

    /**
     * The live DTOs of the event plus the permanent servers, each server once.
     *
     * @return list<array<string, mixed>>
     */
    private static function liveDtos(?Event $event): array
    {
        $links = $event !== null ? ServerListProjection::linksForEvent($event)->all() : [];
        $links = [...$links, ...ServerListProjection::permanentLinks()->all()];

        $dtos = [];
        $seen = [];

        foreach ($links as $link) {
            if (! in_array($link->status, self::LIVE, true) || isset($seen[$link->id])) {
                continue;
            }

            $seen[$link->id] = true;
            $dtos[] = ServerListProjection::dto($link);
        }

        return $dtos;
    }

    /**
     * The game name, or the server title for a server without a game; null when neither is set.
     *
     * @param  array<string, mixed>  $dto
     */
    private static function label(array $dto): ?string
    {
        $label = $dto['game'] ?? $dto['title'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * A server is joinable when it has a connect string and is not known to be full. Unknown
     * capacity (no query data) is not full, so such a server stays joinable.
     *
     * @param  array<string, mixed>  $dto
     */
    private static function joinable(array $dto): bool
    {
        // A match or tournament server is bound to its participants: it counts in the totals but
        // is never offered as "the server to join".
        if ($dto['connectString'] === '' || $dto['matchLabel'] !== null) {
            return false;
        }

        return $dto['query'] === null
            || self::queryCount($dto, 'maxPlayers') > self::queryCount($dto, 'players');
    }

    /**
     * The best joinable server: Ready before NotResponding, servers with query data before
     * those without, then the most players, lowest id last.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{serverId: int, title: string, connectString: string, connectIsLink: bool, address: string|null, port: int|null, players: int, maxPlayers: int}|null
     */
    private static function pickJoin(array $candidates): ?array
    {
        $best = null;

        foreach ($candidates as $dto) {
            if ($best === null || self::ranksAbove($dto, $best)) {
                $best = $dto;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'serverId' => (int) $best['id'],
            'title' => self::stringOf($best['title'] ?? null) ?? self::stringOf($best['matchLabel'] ?? null) ?? (string) $best['game'],
            'connectString' => (string) $best['connectString'],
            'connectIsLink' => (bool) $best['connectIsLink'],
            'address' => self::stringOf($best['address'] ?? null),
            'port' => is_int($best['port'] ?? null) ? $best['port'] : null,
            'players' => self::queryCount($best, 'players'),
            'maxPlayers' => self::queryCount($best, 'maxPlayers'),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private static function ranksAbove(array $candidate, array $current): bool
    {
        $candidateReady = $candidate['status'] === ServerLinkStatus::Ready->value;
        $currentReady = $current['status'] === ServerLinkStatus::Ready->value;

        if ($candidateReady !== $currentReady) {
            return $candidateReady;
        }

        $candidateKnown = $candidate['query'] !== null;
        $currentKnown = $current['query'] !== null;

        if ($candidateKnown !== $currentKnown) {
            return $candidateKnown;
        }

        $candidatePlayers = self::queryCount($candidate, 'players');
        $currentPlayers = self::queryCount($current, 'players');

        if ($candidatePlayers !== $currentPlayers) {
            return $candidatePlayers > $currentPlayers;
        }

        return (int) $candidate['id'] < (int) $current['id'];
    }

    /**
     * Players of a Ready server only: a NotResponding server's query_info is stale, so it adds
     * a server to the count but no players.
     *
     * @param  array<string, mixed>  $dto
     */
    private static function countedPlayers(array $dto): int
    {
        return $dto['status'] === ServerLinkStatus::Ready->value ? self::queryCount($dto, 'players') : 0;
    }

    /** @param array<string, mixed> $dto */
    private static function queryCount(array $dto, string $key): int
    {
        $query = $dto['query'] ?? null;

        return is_array($query) ? (int) ($query[$key] ?? 0) : 0;
    }

    private static function stringOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
