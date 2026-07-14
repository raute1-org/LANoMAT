<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketGenerator;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Domain\BracketProgressor;
use Random\Engine\Mt19937;
use Random\Randomizer;

function playRandomly(BracketPlan $plan, int $seed): BracketPlan
{
    $rng = new Randomizer(new Mt19937($seed));
    $progressor = app(BracketProgressor::class);

    // Repeatedly find a playable match (both real slots filled, not yet decided) and apply a random result.
    do {
        $playable = collect($plan->matches())->first(fn ($m) => $m->isPlayable() && ! $m->isDecided());
        if ($playable === null) {
            break;
        }
        $s1 = $rng->getInt(0, 2);
        $s2 = $s1 === 2 ? $rng->getInt(0, 1) : 2; // ensure a winner, no draw
        $plan = $progressor->apply($plan, $playable->id, $s1, $s2);
    } while (true);

    return $plan;
}

it('yields exactly one champion and no open matches for random single-elim results', function (int $n) {
    foreach (range(1, 5) as $seed) {
        $plan = playRandomly(app(BracketGenerator::class)->singleElimination(range(1, $n)), $seed);

        $open = collect($plan->matches())->filter(fn ($m) => $m->isPlayable() && ! $m->isDecided());
        expect($open)->toBeEmpty();
        expect($plan->finalMatch()->winnerEntryId())->not->toBeNull();
    }
})->with([2, 3, 5, 8, 13, 16, 32]);

it('yields exactly one champion for random double-elim results', function (int $n) {
    foreach (range(1, 5) as $seed) {
        $plan = playRandomly(app(BracketGenerator::class)->doubleElimination(range(1, $n)), $seed);

        $open = collect($plan->matches())->filter(fn ($m) => $m->isPlayable() && ! $m->isDecided());
        expect($open)->toBeEmpty();
        // Champion is the winner of the last decided finals/reset match.
        $champion = collect($plan->matches())
            ->filter(fn ($m) => $m->bracket === Bracket::Finals && $m->isDecided())
            ->sortByDesc(fn ($m) => $m->round)->first();
        expect($champion?->winnerEntryId())->not->toBeNull();
    }
})->with([4, 8, 16]);
