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

it('breaks a wins tie by score difference', function () {
    // 3 entries, cycle: 1 beats 2 (5:0), 2 beats 3 (1:0), 3 beats 1 (1:0) -> all 1 win.
    $plan = playRoundRobin([1, 2, 3], function (int $a, int $b): array {
        return match ([min($a, $b), max($a, $b)]) {
            [1, 2] => $a === 1 ? [5, 0] : [0, 5],
            [2, 3] => $a === 2 ? [1, 0] : [0, 1],
            default => $a === 3 ? [1, 0] : [0, 1],
        };
    });

    $rows = RoundRobinStandings::fromPlan($plan);

    // diffs: 1 = +5-1 = +4, 2 = -5+1 = -4, 3 = -1+1 = 0
    expect(array_map(fn ($r) => $r->entryId, $rows))->toBe([1, 3, 2])
        ->and($rows[0]->scoreDifference)->toBe(4)
        ->and($rows[0]->pointsFor)->toBe(5)
        ->and($rows[0]->pointsAgainst)->toBe(1);
});

it('breaks a two-way tie on wins and difference by the head-to-head result', function () {
    // 4 entries. 1 and 2 end level on wins (2) and difference (0): 2 beat 1 directly.
    $plan = playRoundRobin([1, 2, 3, 4], function (int $a, int $b): array {
        $key = min($a, $b).'-'.max($a, $b);
        $win = match ($key) {
            '1-2' => 2, '1-3' => 1, '1-4' => 1,
            '2-3' => 3, '2-4' => 2, '3-4' => 4,
            default => throw new RuntimeException($key),
        };

        // every result 1:0 -> difference equals wins - losses
        return $win === $a ? [1, 0] : [0, 1];
    });
    // wins: 1 -> {3,4} = 2; 2 -> {1,4} = 2; 3 -> {2} = 1; 4 -> {3} = 1. diff 1 and 2 are equal (+1).

    $rows = RoundRobinStandings::fromPlan($plan);

    expect(array_map(fn ($r) => $r->entryId, $rows)[0])->toBe(2) // head to head beats entry id order
        ->and(array_map(fn ($r) => $r->entryId, $rows)[1])->toBe(1);
});

it('falls back to points scored and finally to the lowest entry id', function () {
    // everybody beats nobody consistently: all 1:1-ish impossible (no draws) -> 2 entries, one match.
    $plan = playRoundRobin([7, 9], fn (int $a, int $b): array => [3, 1]);
    $rows = RoundRobinStandings::fromPlan($plan);
    expect($rows[0]->entryId)->toBe($plan->matches()[1]->slot1->entryId());

    // 3-way perfect cycle with identical scores: wins, diff, points all equal -> entry id ascending, same rank.
    $cycle = playRoundRobin([1, 2, 3], function (int $a, int $b): array {
        $beats = [1 => 2, 2 => 3, 3 => 1];

        return $beats[$a] === $b ? [1, 0] : [0, 1];
    });
    $rows = RoundRobinStandings::fromPlan($cycle);

    expect(array_map(fn ($r) => $r->entryId, $rows))->toBe([1, 2, 3])
        ->and(array_map(fn ($r) => $r->rank, $rows))->toBe([1, 1, 1]);
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
