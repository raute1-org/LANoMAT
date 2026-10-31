<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * Pure standings calculator for a round-robin {@see BracketPlan} (no IO).
 *
 * Only decided matches count. Ranking (product decision, QA round 2):
 *
 *  1. wins, descending
 *  2. head-to-head among the entries that are level on wins: a mini table
 *     that counts only the matches those entries played against each other,
 *     applied recursively to every group that is still level
 *  3. entries that are still level share the same rank ("Gleichstand") —
 *     there is NO silent winner by lowest entry id; the orga decides.
 *
 * Within a shared rank the rows are listed by ascending entry id, which is a
 * stable display order only and carries no meaning.
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

        // Group by wins, best first; resolve each group by head-to-head.
        $byWins = [];
        foreach ($ids as $id) {
            $byWins[$wins[$id]][] = $id;
        }
        krsort($byWins);

        /** @var list<list<int>> $blocks ordered blocks of entries that are level */
        $blocks = [];
        foreach ($byWins as $group) {
            foreach (self::resolveByHeadToHead($group, $headToHead) as $block) {
                $blocks[] = $block;
            }
        }

        $rows = [];
        $position = 0;

        foreach ($blocks as $block) {
            sort($block);
            $rank = $position + 1;

            foreach ($block as $id) {
                $rows[] = new StandingsRow($id, $rank, $played[$id], $wins[$id], $losses[$id], $for[$id], $against[$id]);
                $position++;
            }
        }

        return $rows;
    }

    /**
     * The entry ids sharing rank 1 (empty for a plan without entries). A
     * single id means a clear winner; more than one means a tie the orga has
     * to decide.
     *
     * @return list<int>
     */
    public static function leaders(BracketPlan $plan): array
    {
        $leaders = [];

        foreach (self::fromPlan($plan) as $row) {
            if ($row->rank === 1) {
                $leaders[] = $row->entryId;
            }
        }

        return $leaders;
    }

    /**
     * Splits a group of entries that are level on wins into ordered blocks of
     * entries that are still level after the head-to-head mini table.
     *
     * @param  list<int>  $group
     * @param  array<string, int>  $headToHead  "a:b" (a < b) => winner entry id
     * @return list<list<int>>
     */
    private static function resolveByHeadToHead(array $group, array $headToHead): array
    {
        if (count($group) < 2) {
            return [$group];
        }

        $miniWins = array_fill_keys($group, 0);

        foreach ($group as $x) {
            foreach ($group as $y) {
                if ($x < $y) {
                    $winner = $headToHead[$x.':'.$y] ?? null;

                    if ($winner !== null) {
                        $miniWins[$winner]++;
                    }
                }
            }
        }

        $buckets = [];
        foreach ($group as $id) {
            $buckets[$miniWins[$id]][] = $id;
        }
        krsort($buckets);

        // No separation at all (e.g. a perfect cycle, or nothing played
        // between them): the whole group shares the rank.
        if (count($buckets) === 1) {
            return [$group];
        }

        $blocks = [];
        foreach ($buckets as $bucket) {
            foreach (self::resolveByHeadToHead($bucket, $headToHead) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
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
