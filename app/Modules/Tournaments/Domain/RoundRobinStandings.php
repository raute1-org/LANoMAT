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
        /** @var array<int, int> $played */
        $played = [];
        /** @var array<int, int> $wins */
        $wins = [];
        /** @var array<int, int> $losses */
        $losses = [];
        /** @var array<int, int> $for */
        $for = [];
        /** @var array<int, int> $against */
        $against = [];
        /** @var array<string, int> $headToHead "a:b" => winner entry id */
        $headToHead = [];

        foreach ($plan->matches() as $match) {
            $a = $match->slot1->entryId();
            $b = $match->slot2->entryId();

            foreach ([$a, $b] as $entryId) {
                if ($entryId !== null && ! isset($played[$entryId])) {
                    $played[$entryId] = 0;
                    $wins[$entryId] = 0;
                    $losses[$entryId] = 0;
                    $for[$entryId] = 0;
                    $against[$entryId] = 0;
                }
            }

            $winner = $match->winnerEntryId();

            if ($winner === null || $a === null || $b === null || $match->score1 === null || $match->score2 === null) {
                continue;
            }

            $loser = $winner === $a ? $b : $a;

            $played[$a]++;
            $played[$b]++;
            $for[$a] += $match->score1;
            $against[$a] += $match->score2;
            $for[$b] += $match->score2;
            $against[$b] += $match->score1;
            $wins[$winner]++;
            $losses[$loser]++;
            $headToHead[min($a, $b).':'.max($a, $b)] = $winner;
        }

        /** @var list<int> $ids */
        $ids = array_map('intval', array_keys($played));

        // Group by (wins, difference) to apply the two-way head-to-head rule.
        $groups = [];
        foreach ($ids as $id) {
            $groups[$wins[$id].'|'.($for[$id] - $against[$id])][] = $id;
        }

        /** @var array<int, int> $h2hBonus */
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
            $wins[$id],
            $for[$id] - $against[$id],
            $h2hBonus[$id] ?? 0,
            $for[$id],
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
            $rows[] = new StandingsRow($id, $rank, $played[$id], $wins[$id], $losses[$id], $for[$id], $against[$id]);
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
