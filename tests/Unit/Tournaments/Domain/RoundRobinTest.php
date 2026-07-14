<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketGenerator;

it('generates n*(n-1)/2 matches for a round robin', function (int $n) {
    $plan = app(BracketGenerator::class)->roundRobin(entries($n));

    expect($plan->matches())->toHaveCount(intdiv($n * ($n - 1), 2));
})->with([3, 4, 5, 6]);

it('pairs every participant with every other participant exactly once', function (int $n) {
    $plan = app(BracketGenerator::class)->roundRobin(entries($n));

    $pairs = collect($plan->matches())
        ->filter(fn ($m) => $m->slot1->isEntry() && $m->slot2->isEntry())
        ->map(function ($m) {
            $a = $m->slot1->entryId();
            $b = $m->slot2->entryId();

            return [min($a, $b), max($a, $b)];
        });

    // Every pairing must be unique (a set of {min,max} pairs, size n*(n-1)/2).
    $unique = $pairs->unique(fn ($p) => $p[0].'-'.$p[1]);
    expect($unique)->toHaveCount(intdiv($n * ($n - 1), 2));

    // And every real entry meets every other real entry (no duplicates, no omissions).
    $expectedPairs = collect();
    foreach (entries($n) as $a) {
        foreach (entries($n) as $b) {
            if ($a < $b) {
                $expectedPairs->push([$a, $b]);
            }
        }
    }

    expect($unique->values()->sort()->values()->all())->toEqual($expectedPairs->sort()->values()->all());
})->with([3, 4, 5, 6]);

it('gives every match bracket = Winners and no next match', function (int $n) {
    $plan = app(BracketGenerator::class)->roundRobin(entries($n));

    collect($plan->matches())->each(function ($m) {
        expect($m->bracket)->toBe(Bracket::Winners)
            ->and($m->nextMatch)->toBeNull()
            ->and($m->nextSlot)->toBeNull()
            ->and($m->loserNextMatch)->toBeNull()
            ->and($m->loserNextSlot)->toBeNull();
    });
})->with([3, 4, 5, 6]);

it('sits exactly one participant out per round when the participant count is odd', function (int $n) {
    $plan = app(BracketGenerator::class)->roundRobin(entries($n));

    // n rounds are played when n is odd (one bye "ghost" per round, one
    // participant sits out — bye pairings are not stored as matches).
    $byRound = collect($plan->matches())->groupBy('round');
    expect($byRound)->toHaveCount($n);

    $byRound->each(function ($matches) use ($n) {
        $playing = $matches
            ->flatMap(fn ($m) => array_filter([$m->slot1->entryId(), $m->slot2->entryId()], fn ($e) => $e !== null));

        // One entry (out of n) is missing from this round's matches: the bye.
        expect($playing->unique())->toHaveCount($n - 1);
    });
})->with([3, 5]);

it('has no byes and every entry plays every round when the participant count is even', function (int $n) {
    $plan = app(BracketGenerator::class)->roundRobin(entries($n));

    $byes = collect($plan->matches())
        ->flatMap(fn ($m) => [$m->slot1, $m->slot2])
        ->filter(fn ($s) => $s->isBye());

    expect($byes)->toHaveCount(0);

    // n-1 rounds are played when n is even, every entry plays every round.
    $byRound = collect($plan->matches())->groupBy('round');
    expect($byRound)->toHaveCount($n - 1);

    $byRound->each(function ($matches) use ($n) {
        $playing = $matches->flatMap(fn ($m) => [$m->slot1->entryId(), $m->slot2->entryId()]);
        expect($playing->unique())->toHaveCount($n);
    });
})->with([4, 6]);

it('gives every real entry the same number of matches', function (int $n) {
    $plan = app(BracketGenerator::class)->roundRobin(entries($n));

    $matchesPerEntry = collect($plan->matches())
        ->flatMap(fn ($m) => array_filter([$m->slot1->entryId(), $m->slot2->entryId()], fn ($e) => $e !== null))
        ->countBy();

    expect($matchesPerEntry->unique()->count())->toBe(1)
        ->and($matchesPerEntry->first())->toBe($n - 1);
})->with([3, 4, 5, 6]);
