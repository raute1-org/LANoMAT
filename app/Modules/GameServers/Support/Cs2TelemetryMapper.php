<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Http\MatchTelemetryController;

/**
 * Maps a MatchZy/G5API round/score webhook payload to a normalized
 * `{ team1, team2, score1, score2, round }` shape (roadmap 6.9 — a per-game
 * recipe, not a universal claim: this mapper only understands MatchZy's
 * shape, and only the events that carry a live score).
 *
 * MatchZy (https://github.com/shobhit-pathak/MatchZy) posts an `event` key
 * identifying the payload kind. This mapper recognizes the score-carrying
 * events (`round_end`, `map_result`, `series_end` — every one of them
 * repeats the current `team1`/`team2` score block) and reads the nested
 * `team1.name`/`team1.score` (and `team2.*`) plus `round_number`. Any other
 * `event` value, or a payload missing the fields this mapper needs, is
 * "not our shape" and yields null — the caller
 * ({@see MatchTelemetryController}) treats
 * that as "ignore gracefully", never a crash.
 */
final class Cs2TelemetryMapper
{
    private const SCORE_EVENTS = ['round_end', 'map_result', 'series_end'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{team1: string, team2: string, score1: int, score2: int, round: int}|null
     */
    public static function map(array $payload): ?array
    {
        $event = $payload['event'] ?? null;

        if (! is_string($event) || ! in_array($event, self::SCORE_EVENTS, true)) {
            return null;
        }

        $team1 = $payload['team1'] ?? null;
        $team2 = $payload['team2'] ?? null;

        if (! is_array($team1) || ! is_array($team2)) {
            return null;
        }

        $team1Name = $team1['name'] ?? null;
        $team2Name = $team2['name'] ?? null;
        $score1 = $team1['score'] ?? null;
        $score2 = $team2['score'] ?? null;

        if (! is_string($team1Name) || ! is_string($team2Name)) {
            return null;
        }

        if (! is_int($score1) || ! is_int($score2)) {
            return null;
        }

        $round = $payload['round_number'] ?? 0;

        if (! is_int($round)) {
            return null;
        }

        return [
            'team1' => $team1Name,
            'team2' => $team2Name,
            'score1' => $score1,
            'score2' => $score2,
            'round' => $round,
        ];
    }
}
