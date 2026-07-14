<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketGenerator;

function entries(int $n): array
{
    return range(1, $n);
}

it('generates a single-elimination bracket for every size 2..64', function (int $n) {
    $plan = app(BracketGenerator::class)->singleElimination(entries($n));

    // Number of real (non-bye) participants preserved as slot occupants across round 1.
    $occupants = collect($plan->matches())
        ->filter(fn ($m) => $m->round === 1)
        ->flatMap(fn ($m) => array_filter([$m->slot1->entryId(), $m->slot2->entryId()], fn ($e) => $e !== null));

    expect($occupants->unique()->count())->toBe($n);

    // Exactly one final (a match with no nextMatch).
    $finals = collect($plan->matches())->filter(fn ($m) => $m->nextMatch === null && $m->bracket === Bracket::Winners);
    expect($finals)->toHaveCount(1);

    // Every non-final match chains forward.
    collect($plan->matches())
        ->reject(fn ($m) => $m->nextMatch === null)
        ->each(fn ($m) => expect($plan->matches())->toHaveKey($m->nextMatch));

    // Bracket size is the next power of two >= n; total matches = size - 1.
    $size = 1;
    while ($size < $n) {
        $size *= 2;
    }
    expect($plan->matches())->toHaveCount($size - 1);

    // Byes only ever occur in round 1.
    collect($plan->matches())
        ->filter(fn ($m) => $m->round !== 1)
        ->each(function ($m) {
            expect($m->slot1->isBye())->toBeFalse();
            expect($m->slot2->isBye())->toBeFalse();
        });

    // No real entry appears in more than one round-1 match.
    $entryOccurrences = collect($plan->matches())
        ->filter(fn ($m) => $m->round === 1)
        ->flatMap(fn ($m) => array_filter([$m->slot1->entryId(), $m->slot2->entryId()], fn ($e) => $e !== null));
    expect($entryOccurrences->count())->toBe($entryOccurrences->unique()->count());

    // Every round halves the previous round's match count.
    $byRound = collect($plan->matches())->groupBy('round')->sortKeys();
    $counts = $byRound->map->count()->values();
    for ($i = 1; $i < $counts->count(); $i++) {
        expect($counts[$i])->toBe(intdiv($counts[$i - 1], 2));
    }
})->with(range(2, 64));

it('places byes for non-power-of-two sizes', function () {
    $plan = app(BracketGenerator::class)->singleElimination(entries(6)); // size 8 -> 2 byes
    $byes = collect($plan->matches())->filter(fn ($m) => $m->round === 1)
        ->flatMap(fn ($m) => [$m->slot1, $m->slot2])
        ->filter(fn ($s) => $s->isBye());
    expect($byes)->toHaveCount(2);
});

it('seeds top seeds apart (1 and 2 in opposite halves)', function () {
    $plan = app(BracketGenerator::class)->singleElimination(entries(8));
    // Seed 1 and seed 2 must not meet before the final -> different first-round match halves.
    // Assert via slot placement of entries 1 and 2 in round 1.
    $round1 = collect($plan->matches())->filter(fn ($m) => $m->round === 1)->values();
    $posOf = fn (int $e) => $round1->search(fn ($m) => in_array($e, [$m->slot1->entryId(), $m->slot2->entryId()], true));
    expect($posOf(1))->toBeLessThan(count($round1) / 2)
        ->and($posOf(2))->toBeGreaterThanOrEqual(count($round1) / 2);
});
