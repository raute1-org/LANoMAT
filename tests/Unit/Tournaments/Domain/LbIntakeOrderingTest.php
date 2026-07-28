<?php

use App\Modules\Tournaments\Domain\LbIntakeOrdering;

/**
 * Applies an ordering result to a natural [0..m-1] list, so a test can assert
 * the resulting permutation rather than an ordering's name.
 */
function permutationFor(int $size, int $k): array
{
    return LbIntakeOrdering::forSize($size)[$k];
}

it('reproduces the previously hand-maintained ordering table for size 8', function () {
    // size 8, k=2: 'reverse' over 2 positions.
    expect(permutationFor(8, 2))->toBe([1, 0]);
})->group('bracket');

it('reproduces the previously hand-maintained ordering table for size 16', function () {
    // size 16, k=2: 'reverse_half_shift' over 4 positions -> [1,0,3,2].
    expect(permutationFor(16, 2))->toBe([1, 0, 3, 2]);
    // size 16, k=3: 'reverse' over 2 positions -> [1,0].
    expect(permutationFor(16, 3))->toBe([1, 0]);
})->group('bracket');

it('leaves the losers-bracket final unpermuted, since a single loser drops in', function () {
    // The last WB round always drops exactly one loser.
    expect(permutationFor(8, 3))->toBe([0])
        ->and(permutationFor(16, 4))->toBe([0]);
})->group('bracket');

it('has no intake rounds at all for the degenerate size 2', function () {
    expect(LbIntakeOrdering::forSize(2))->toBe([]);
})->group('bracket');

it('is deterministic', function (int $size) {
    expect(LbIntakeOrdering::forSize($size))->toBe(LbIntakeOrdering::forSize($size));
})->with([4, 8, 16, 32, 64])->group('bracket');

it('achieves the proven minimum number of overlapping intake pairs', function (int $size, int $expectedOverlap) {
    expect(overlapScoreFor($size))->toBe($expectedOverlap);
})->with([
    [4, 0],
    [8, 0],
    [16, 0],
    // Above size 16 a counting argument forces overlaps in the
    // second-to-last intake round; 2 is the proven minimum, not a shortfall.
    [32, 2],
    [64, 2],
])->group('bracket');

/**
 * Replays the losers-bracket lineage bookkeeping against the computed
 * ordering and counts how many intake pairings are NOT lineage-disjoint,
 * excluding the losers-bracket final (a single incoming loser, no freedom).
 */
function overlapScoreFor(int $size): int
{
    $rounds = (int) log($size, 2);
    $ordering = LbIntakeOrdering::forSize($size);

    // LB round 1 pairs WB round-1 losers naturally: match i covers round-1
    // matches 2i and 2i+1.
    $survivors = [];
    for ($i = 0; $i < intdiv($size, 4); $i++) {
        $survivors[] = [2 * $i, 2 * $i + 1];
    }

    $overlap = 0;

    for ($k = 2; $k <= $rounds; $k++) {
        $intakeSize = intdiv($size, 2 ** $k);

        // Minor (halving) rounds until the survivor count matches the batch.
        while (count($survivors) > $intakeSize) {
            $merged = [];
            for ($i = 0; $i < intdiv(count($survivors), 2); $i++) {
                $merged[] = array_values(array_unique(
                    array_merge($survivors[2 * $i], $survivors[2 * $i + 1]),
                ));
            }
            $survivors = $merged;
        }

        $span = 2 ** ($k - 1);
        $spans = [];
        for ($j = 0; $j < $intakeSize; $j++) {
            $spans[] = range($j * $span, ($j + 1) * $span - 1);
        }

        $permutation = $ordering[$k];
        $next = [];

        for ($i = 0; $i < $intakeSize; $i++) {
            $incoming = $spans[$permutation[$i]];

            if ($intakeSize > 1 && array_intersect($incoming, $survivors[$i]) !== []) {
                $overlap++;
            }

            $next[] = array_values(array_unique(array_merge($survivors[$i], $incoming)));
        }

        $survivors = $next;
    }

    return $overlap;
}
