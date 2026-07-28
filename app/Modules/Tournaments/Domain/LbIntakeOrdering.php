<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * Computes the losers-bracket intake orderings for a double-elimination
 * bracket of a given size. Pure positional computation: no IO, no match ids,
 * no framework dependencies.
 *
 * ## What an intake ordering is for
 *
 * The losers bracket alternates *intake* rounds (which absorb a fresh batch of
 * winners-bracket losers) and *minor* rounds (which halve the LB survivors
 * internally). Before a batch of WB losers is zipped index-for-index against
 * the current LB survivors, the batch is permuted, so that an entrant dropping
 * out of the WB does not immediately re-meet an opponent it already played.
 *
 * ## How the permutation is chosen
 *
 * The unit of reasoning is *lineage*: the set of WB round-1 match indices that
 * can reach a given LB slot. A WB round-`k` match `j` spans round-1 indices
 * `[j*2^(k-1), (j+1)*2^(k-1))`, and every WB opponent a dropped entrant could
 * have faced lives inside its own span. A pairing is *clean* when span and
 * lineage are disjoint.
 *
 * Candidates are the four canonical permutation families used by mainstream
 * bracket generators (natural, reverse, half-shift, reverse-half-shift). The
 * chosen sequence is the one minimising the total number of non-clean intake
 * pairings across all rounds, with the candidate order as the tie-break.
 *
 * ## Two properties worth knowing before changing this
 *
 * 1. **The search must look ahead.** Greedily taking the first clean ordering
 *    is wrong: at size 16, `reverse` at k=2 is locally clean but saturates the
 *    survivor lineages and strands k=3 with no clean option at all. Only
 *    whole-sequence scoring finds the working combination.
 *
 * 2. **Zero overlap is impossible above size 16 — by counting, not by
 *    weakness of this search.** At size 32 a survivor entering the k=4 intake
 *    has a lineage of at least 12 of the 16 round-1 indices (2 after LB round
 *    1; +2 at k=2 -> 4; the minor round before k=3 unions two of those -> 8;
 *    +4 at k=3 -> 12; the minor round before k=4 only unions further), while
 *    the incoming span covers 8. Since 12 + 8 > 16 the sets cannot be
 *    disjoint. The minimum total overlap is therefore 2 for sizes 32 and 64,
 *    confined to the second-to-last intake round. That is inherent to double
 *    elimination at those field sizes, not a defect.
 *
 * The computed result reproduces the hand-maintained per-size table this class
 * replaced (size 8 -> reverse; size 16 -> reverse-half-shift then reverse),
 * which is locked by test.
 */
final class LbIntakeOrdering
{
    /**
     * Permutation families to try at each intake round, in tie-break
     * preference order. Named rather than stored as callables, so Larastan
     * level 8 can see through the dispatch in {@see permute()}.
     *
     * @var list<'natural'|'reverse'|'half_shift'|'reverse_half_shift'>
     */
    private const CANDIDATES = ['natural', 'reverse', 'half_shift', 'reverse_half_shift'];

    /**
     * Applies one named permutation family to a natural position list.
     *
     * @param  'natural'|'reverse'|'half_shift'|'reverse_half_shift'  $candidate
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function permute(string $candidate, array $positions): array
    {
        return match ($candidate) {
            'natural' => $positions,
            'reverse' => array_reverse($positions),
            'half_shift' => self::halfShift($positions),
            'reverse_half_shift' => self::reverseHalfShift($positions),
        };
    }

    /**
     * The intake permutation for every WB round `k` whose losers drop into
     * the losers bracket (k = 2..log2(size); WB round 1's losers are paired
     * among themselves naturally and never appear here).
     *
     * Position `i` of a returned list names the WB-loser index that faces LB
     * survivor `i`. A round with a single incoming loser (the LB final) maps
     * to `[0]`.
     *
     * @return array<int, list<int>> keyed by WB round number
     */
    public static function forSize(int $size): array
    {
        $rounds = (int) log($size, 2);

        // Size 2: a single WB round, no losers bracket at all.
        if ($rounds < 2) {
            return [];
        }

        // LB round 1 pairs WB round-1 losers naturally: LB match i can be
        // reached from WB round-1 matches 2i and 2i+1.
        $survivors = [];
        for ($i = 0; $i < intdiv($size, 4); $i++) {
            $survivors[] = [2 * $i, 2 * $i + 1];
        }

        $best = self::search($size, $rounds, 2, $survivors);

        // A solution always exists: `natural` is always applicable, so the
        // search can never exhaust its candidates without reaching the end.
        return $best['ordering'];
    }

    /**
     * Exhaustively scores every candidate sequence from round `$k` onward and
     * returns the cheapest. Bounded by 4^(log2(size)-1) leaves — at most 1024
     * for size 64, over sets of at most 64 small integers.
     *
     * @param  list<list<int>>  $survivors  lineage per LB survivor, in position order
     * @return array{score: int, ordering: array<int, list<int>>}
     */
    private static function search(int $size, int $rounds, int $k, array $survivors): array
    {
        if ($k > $rounds) {
            return ['score' => 0, 'ordering' => []];
        }

        $intakeSize = intdiv($size, 2 ** $k);
        $survivors = self::applyMinorRounds($survivors, $intakeSize);
        $spans = self::spansFor($size, $k);

        // The LB final: exactly one incoming loser, so there is nothing to
        // permute and any overlap is structurally unavoidable.
        if ($intakeSize <= 1) {
            $next = [self::union($survivors[0], $spans[0])];
            $rest = self::search($size, $rounds, $k + 1, $next);

            return [
                'score' => $rest['score'],
                'ordering' => [$k => [0]] + $rest['ordering'],
            ];
        }

        $best = null;

        foreach (self::CANDIDATES as $candidate) {
            $permutation = self::permute($candidate, range(0, $intakeSize - 1));

            $overlap = 0;
            $next = [];

            for ($i = 0; $i < $intakeSize; $i++) {
                $incoming = $spans[$permutation[$i]];

                if (array_intersect($incoming, $survivors[$i]) !== []) {
                    $overlap++;
                }

                $next[] = self::union($survivors[$i], $incoming);
            }

            $rest = self::search($size, $rounds, $k + 1, $next);
            $total = $rest['score'] + $overlap;

            if ($best === null || $total < $best['score']) {
                $best = [
                    'score' => $total,
                    'ordering' => [$k => $permutation] + $rest['ordering'],
                ];
            }
        }

        // CANDIDATES is never empty, so a best branch always exists.
        return $best;
    }

    /**
     * Halves the LB survivors (pairing neighbours, exactly as the generator
     * builds its minor rounds) until their count matches the incoming batch.
     *
     * @param  list<list<int>>  $survivors
     * @return list<list<int>>
     */
    private static function applyMinorRounds(array $survivors, int $intakeSize): array
    {
        while (count($survivors) > $intakeSize) {
            $merged = [];

            for ($i = 0; $i < intdiv(count($survivors), 2); $i++) {
                $merged[] = self::union($survivors[2 * $i], $survivors[2 * $i + 1]);
            }

            $survivors = $merged;
        }

        return $survivors;
    }

    /**
     * The WB round-1 index span of each WB round-`k` match, in position order.
     *
     * @return list<list<int>>
     */
    private static function spansFor(int $size, int $k): array
    {
        $count = intdiv($size, 2 ** $k);
        $width = (int) (2 ** ($k - 1));

        $spans = [];
        for ($j = 0; $j < $count; $j++) {
            $start = $j * $width;
            $end = ($j + 1) * $width - 1;

            $spans[] = array_map(intval(...), range($start, $end));
        }

        return $spans;
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return list<int>
     */
    private static function union(array $a, array $b): array
    {
        return array_values(array_unique(array_merge($a, $b)));
    }

    /**
     * Swaps the two halves ([0,1,2,3] -> [2,3,0,1]).
     *
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function halfShift(array $positions): array
    {
        $half = intdiv(count($positions), 2);

        return array_merge(
            array_slice($positions, $half),
            array_slice($positions, 0, $half),
        );
    }

    /**
     * Reverses each half in place ([0,1,2,3] -> [1,0,3,2]).
     *
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function reverseHalfShift(array $positions): array
    {
        $half = intdiv(count($positions), 2);

        return array_merge(
            array_reverse(array_slice($positions, 0, $half)),
            array_reverse(array_slice($positions, $half)),
        );
    }
}
