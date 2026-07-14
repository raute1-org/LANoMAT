<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * Builds {@see BracketPlan} instances from a seeded list of entries. Pure
 * domain computation: no IO, no persistence, no framework dependencies.
 */
final class BracketGenerator
{
    /**
     * Generates a standard single-elimination bracket.
     *
     * @param  array<int, int>  $entryIds  already seeded, in order (index 0 = seed 1, index 1 = seed 2, ...)
     */
    public function singleElimination(array $entryIds): BracketPlan
    {
        $n = count($entryIds);

        if ($n < 2) {
            throw new InvalidArgumentException('Single elimination requires at least 2 entries.');
        }

        $size = $this->nextPowerOfTwo($n);
        $order = $this->seedOrder($size);

        // seed 1..n -> entryIds[seed-1]; seed n+1..size -> bye.
        $slotForSeed = function (int $seed) use ($entryIds, $n): Slot {
            return $seed <= $n ? Slot::entry($entryIds[$seed - 1]) : Slot::bye();
        };

        $matches = [];
        $id = 1;

        // Round 1: pair up consecutive positions in the seed order.
        $roundMatchIds = [];
        $matchesInRound = intdiv($size, 2);

        for ($i = 0; $i < $matchesInRound; $i++) {
            $seedA = $order[2 * $i];
            $seedB = $order[2 * $i + 1];

            $matches[$id] = new BracketMatch(
                id: $id,
                round: 1,
                bracket: Bracket::Winners,
                position: $i + 1,
                slot1: $slotForSeed($seedA),
                slot2: $slotForSeed($seedB),
                nextMatch: null,
                nextSlot: null,
                loserNextMatch: null,
                loserNextSlot: null,
            );

            $roundMatchIds[] = $id;
            $id++;
        }

        // Subsequent rounds: halve the match count each time, chaining the
        // previous round's winners into this round's slots.
        $round = 2;
        $previousRoundIds = $roundMatchIds;

        while (count($previousRoundIds) > 1) {
            $matchesInRound = intdiv(count($previousRoundIds), 2);
            $currentRoundIds = [];

            for ($i = 0; $i < $matchesInRound; $i++) {
                $feederA = $previousRoundIds[2 * $i];
                $feederB = $previousRoundIds[2 * $i + 1];

                $matches[$id] = new BracketMatch(
                    id: $id,
                    round: $round,
                    bracket: Bracket::Winners,
                    position: $i + 1,
                    slot1: Slot::pendingFrom($feederA),
                    slot2: Slot::pendingFrom($feederB),
                    nextMatch: null,
                    nextSlot: null,
                    loserNextMatch: null,
                    loserNextSlot: null,
                );

                // Wire the two feeder matches of the previous round forward.
                $matches[$feederA] = $matches[$feederA]->withRouting(nextMatch: $id, nextSlot: 1);
                $matches[$feederB] = $matches[$feederB]->withRouting(nextMatch: $id, nextSlot: 2);

                $currentRoundIds[] = $id;
                $id++;
            }

            $previousRoundIds = $currentRoundIds;
            $round++;
        }

        return new BracketPlan($matches);
    }

    /**
     * Standard bracket seeding sequence for a bracket of the given size
     * (a power of two), recursively derived so that top seeds meet as late
     * as possible: [1,2] -> [1,4,3,2] -> [1,8,5,4,3,6,7,2] -> ...
     *
     * @return array<int, int> 1-indexed seed numbers in bracket slot order
     */
    private function seedOrder(int $size): array
    {
        $sequence = [1, 2];

        while (count($sequence) < $size) {
            $next = [];
            $currentSize = count($sequence) * 2;

            foreach ($sequence as $pos) {
                $next[] = $pos;
                $next[] = $currentSize + 1 - $pos;
            }

            $sequence = $next;
        }

        return $sequence;
    }

    /**
     * Smallest power of two greater than or equal to $n.
     */
    private function nextPowerOfTwo(int $n): int
    {
        $size = 1;

        while ($size < $n) {
            $size *= 2;
        }

        return $size;
    }
}
