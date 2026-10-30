<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * Pure standings calculator for a round-robin {@see BracketPlan} (no IO).
 *
 * Only decided matches count. Order (documented tiebreak chain):
 *
 *  1. wins, descending
 *  2. score difference (points for minus points against), descending
 *  3. head-to-head result, only when EXACTLY two entries are level on 1 and 2
 *     (a mini-table for three or more would be a different, circular problem)
 *  4. points scored, descending
 *  5. lowest entry id — a stable ordering only: entries that are still level
 *     after step 4 share the same rank.
 *
 * The champion of a finished round robin is the first row.
 */
final class RoundRobinStandings
{
    /**
     * @return list<StandingsRow> best first, every entry of the plan present
     */
    public static function fromPlan(BracketPlan $plan): array
    {
        /** @var array<int, array{played: int, wins: int, losses: int, for: int, against: int}> $stats */
        $stats = [];
        /** @var array<string, int> $headToHead "a:b" => winner entry id */
        $headToHead = [];

        foreach ($plan->matches() as $match) {
            foreach ([$match->slot1->entryId(), $match->slot2->entryId()] as $entryId) {
                if ($entryId !== null && ! isset($stats[$entryId])) {
                    $stats[$entryId] = ['played' => 0, 'wins' => 0, 'losses' => 0, 'for' => 0, 'against' => 0];
                }
            }

            $a = $match->slot1->entryId();
            $b = $match->slot2->entryId();

            if (! $match->isDecided() || $a === null || $b === null || $match->score1 === null || $match->score2 === null) {
                continue;
            }

            $winner = $match->winnerEntryId();
            $loser = $winner === $a ? $b : $a;

            $stats[$a]['played']++;
            $stats[$b]['played']++;
            $stats[$a]['for'] += $match->score1;
            $stats[$a]['against'] += $match->score2;
            $stats[$b]['for'] += $match->score2;
            $stats[$b]['against'] += $match->score1;
            $stats[$winner]['wins']++;
            $stats[$loser]['losses']++;
            $headToHead[min($a, $b).':'.max($a, $b)] = $winner;
        }

        $ids = array_keys($stats);

        // Group by (wins, difference) to apply the two-way head-to-head rule.
        $groups = [];
        foreach ($ids as $id) {
            $groups[$stats[$id]['wins'].'|'.($stats[$id]['for'] - $stats[$id]['against'])][] = $id;
        }

        $h2hBonus = [];
        foreach ($groups as $members) {
            if (count($members) === 2) {
                [$x, $y] = $members;
                $winner = $headToHead[min($x, $y).':'.max($x, $y)] ?? null;
                $h2hBonus[$x] = $winner === $x ? 1 : 0;
                $h2hBonus[$y] = $winner === $y ? 1 : 0;
            }
        }

        $key = static fn (int $id): array => [
            $stats[$id]['wins'],
            $stats[$id]['for'] - $stats[$id]['against'],
            $h2hBonus[$id] ?? 0,
            $stats[$id]['for'],
        ];

        usort($ids, static function (int $x, int $y) use ($key): int {
            // arrays compare element-wise; descending on every criterion
            return ($key($y) <=> $key($x)) ?: ($x <=> $y);
        });

        $rows = [];
        $previousKey = null;
        $rank = 0;

        foreach ($ids as $position => $id) {
            $current = $key($id);

            if ($previousKey === null || $current !== $previousKey) {
                $rank = $position + 1;
            }

            $previousKey = $current;
            $s = $stats[$id];
            $rows[] = new StandingsRow($id, $rank, $s['played'], $s['wins'], $s['losses'], $s['for'], $s['against']);
        }

        return $rows;
    }

    /**
     * True once every match of the plan has a result (and there is at least one).
     */
    public static function isComplete(BracketPlan $plan): bool
    {
        $matches = $plan->matches();

        if ($matches === []) {
            return false;
        }

        foreach ($matches as $match) {
            if (! $match->isDecided()) {
                return false;
            }
        }

        return true;
    }
}
