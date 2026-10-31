<?php

use App\Modules\Tournaments\Domain\BracketGenerator;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Domain\RoundRobinStandings;

/**
 * Plays every match of a generated round-robin plan using $scores:
 * a callable (entryA, entryB) => [scoreA, scoreB]; entryA is the entry in slot 1.
 *
 * @param  callable(int, int): array{0: int, 1: int}  $scores
 */
function playRoundRobin(array $entryIds, callable $scores): BracketPlan
{
    $plan = (new BracketGenerator)->roundRobin($entryIds);

    foreach ($plan->matches() as $match) {
        [$s1, $s2] = $scores($match->slot1->entryId(), $match->slot2->entryId());
        $plan = $plan->withMatch($match->withResult($s1, $s2, $s1 > $s2 ? 1 : 2));
    }

    return $plan;
}

it('ranks by wins first', function () {
    // entry id == strength: higher id always wins 1:0
    $plan = playRoundRobin([1, 2, 3, 4], fn (int $a, int $b): array => $a > $b ? [1, 0] : [0, 1]);

    $rows = RoundRobinStandings::fromPlan($plan);

    expect(array_map(fn ($r) => $r->entryId, $rows))->toBe([4, 3, 2, 1])
        ->and(array_map(fn ($r) => $r->wins, $rows))->toBe([3, 2, 1, 0])
        ->and(array_map(fn ($r) => $r->rank, $rows))->toBe([1, 2, 3, 4])
        ->and($rows[0]->played)->toBe(3)
        ->and($rows[3]->losses)->toBe(3);
});

it('does not use score difference: a three-way cycle on wins shares rank 1', function () {
    // 1 beats 2 (5:0), 2 beats 3 (1:0), 3 beats 1 (1:0) -> all 1 win, circular head-to-head.
    $plan = playRoundRobin([1, 2, 3], function (int $a, int $b): array {
        return match ([min($a, $b), max($a, $b)]) {
            [1, 2] => $a === 1 ? [5, 0] : [0, 5],
            [2, 3] => $a === 2 ? [1, 0] : [0, 1],
            default => $a === 3 ? [1, 0] : [0, 1],
        };
    });

    $rows = RoundRobinStandings::fromPlan($plan);

    expect(array_map(fn ($r) => $r->rank, $rows))->toBe([1, 1, 1])
        // listed by ascending entry id, which carries no meaning
        ->and(array_map(fn ($r) => $r->entryId, $rows))->toBe([1, 2, 3])
        ->and(RoundRobinStandings::leaders($plan))->toBe([1, 2, 3])
        ->and($rows[0]->scoreDifference)->toBe(4);
});

it('breaks a two-way tie on wins by the head-to-head result', function () {
    // 1 and 2 end level on wins (2): 2 beat 1 directly. 3 and 4 level on 1 win: 4 beat 3.
    $plan = playRoundRobin([1, 2, 3, 4], function (int $a, int $b): array {
        $win = match (min($a, $b).'-'.max($a, $b)) {
            '1-2' => 2, '1-3' => 1, '1-4' => 1,
            '2-3' => 3, '2-4' => 2, '3-4' => 4,
            default => throw new RuntimeException('unexpected pairing'),
        };

        return $win === $a ? [1, 0] : [0, 1];
    });

    $rows = RoundRobinStandings::fromPlan($plan);

    expect(array_map(fn ($r) => $r->entryId, $rows))->toBe([2, 1, 4, 3])
        ->and(array_map(fn ($r) => $r->rank, $rows))->toBe([1, 2, 3, 4])
        ->and(RoundRobinStandings::leaders($plan))->toBe([2]);
});

it('resolves a three-way tie on wins through the mini table among the three', function () {
    // 5 entries. 1, 2, 3 all end on 3 wins; among themselves 1 beats 2 and 3, 2 beats 3.
    $plan = playRoundRobin([1, 2, 3, 4, 5], function (int $a, int $b): array {
        $pair = [min($a, $b), max($a, $b)];
        $win = match ($pair) {
            [1, 2] => 1, [1, 3] => 1, [2, 3] => 2,
            [1, 4] => 4, [1, 5] => 5,
            [2, 4] => 2, [2, 5] => 2,
            [3, 4] => 3, [3, 5] => 3,
            [4, 5] => 5,
        };

        return $win === $a ? [1, 0] : [0, 1];
    });
    // wins: 1 -> {2,3} = 2; 2 -> {3,4,5} = 3; 3 -> {4,5} = 2; 4 -> {1} = 1; 5 -> {1,4} = 2.
    $rows = RoundRobinStandings::fromPlan($plan);

    expect($rows[0]->entryId)->toBe(2)
        ->and(RoundRobinStandings::leaders($plan))->toBe([2]);
});

it('shares the rank of every group that stays level after the head-to-head', function () {
    // 4 entries: 1 beats everyone; 2, 3, 4 form a cycle 2>3>4>2 -> they share rank 2.
    $plan = playRoundRobin([1, 2, 3, 4], function (int $a, int $b): array {
        $win = match (min($a, $b).'-'.max($a, $b)) {
            '1-2', '1-3', '1-4' => 1,
            '2-3' => 2, '3-4' => 3, '2-4' => 4,
        };

        return $win === $a ? [1, 0] : [0, 1];
    });

    $rows = RoundRobinStandings::fromPlan($plan);

    expect(array_map(fn ($r) => $r->entryId, $rows))->toBe([1, 2, 3, 4])
        ->and(array_map(fn ($r) => $r->rank, $rows))->toBe([1, 2, 2, 2])
        ->and(RoundRobinStandings::leaders($plan))->toBe([1]);
});

it('never lets the lowest entry id win a tie silently', function () {
    $cycle = playRoundRobin([1, 2, 3], function (int $a, int $b): array {
        $beats = [1 => 2, 2 => 3, 3 => 1];

        return $beats[$a] === $b ? [1, 0] : [0, 1];
    });

    expect(RoundRobinStandings::leaders($cycle))->toHaveCount(3);

    // a single match between two entries still has a clear leader
    $plan = playRoundRobin([7, 9], fn (int $a, int $b): array => [3, 1]);
    expect(RoundRobinStandings::leaders($plan))->toHaveCount(1);
});

it('only counts decided matches and lists entries without a result', function () {
    $plan = (new BracketGenerator)->roundRobin([1, 2, 3]);
    $first = array_values($plan->matches())[0];
    $plan = $plan->withMatch($first->withResult(2, 0, 1));

    $rows = RoundRobinStandings::fromPlan($plan);

    expect($rows)->toHaveCount(3);
    expect(array_sum(array_map(fn ($r) => $r->played, $rows)))->toBe(2);
    expect(RoundRobinStandings::isComplete($plan))->toBeFalse();
});

it('reports completion only once every match is decided', function () {
    $plan = playRoundRobin([1, 2, 3, 4, 5], fn (int $a, int $b): array => $a > $b ? [2, 1] : [1, 2]);

    expect(RoundRobinStandings::isComplete($plan))->toBeTrue();
    // 10 matches, each entry plays 4
    expect(array_map(fn ($r) => $r->played, RoundRobinStandings::fromPlan($plan)))->toBe([4, 4, 4, 4, 4]);
});

it('is exhaustive for every field size 2..12 with deterministic results', function () {
    foreach (range(2, 12) as $n) {
        $ids = range(1, $n);
        $plan = playRoundRobin($ids, fn (int $a, int $b): array => $a > $b ? [3, 0] : [0, 3]);
        $rows = RoundRobinStandings::fromPlan($plan);

        expect($rows)->toHaveCount($n)
            ->and($rows[0]->entryId)->toBe($n)
            ->and($rows[0]->wins)->toBe($n - 1)
            ->and(RoundRobinStandings::isComplete($plan))->toBeTrue();
    }
});
