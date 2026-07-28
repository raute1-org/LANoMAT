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
})->with([4, 8, 16, 32, 64, 5, 7, 17, 23, 31]);

/**
 * The intake ordering's real guarantee: an entrant's FIRST losers-bracket
 * match is not a rematch of a winners-bracket opponent.
 *
 * Two exemptions, both structural rather than incidental:
 *  - the LB final, where a single WB-final loser drops in with no reordering
 *    freedom, and
 *  - above bracket size 16, the second-to-last intake round, where a counting
 *    argument forces overlaps for any permutation whatsoever (see
 *    LbIntakeOrdering's class docblock).
 */
it('never opens an entrant\'s losers-bracket run with a winners-bracket rematch', function (int $n) {
    $bracketSize = 1;
    while ($bracketSize < $n) {
        $bracketSize *= 2;
    }
    $lastLbRound = 2 * ((int) log($bracketSize, 2) - 1);
    // Above size 16 the second-to-last intake round is provably forced; it
    // lands two LB rounds before the LB final (an intake round preceded by a
    // minor round).
    $exemptFrom = $bracketSize > 16 ? $lastLbRound - 2 : $lastLbRound;

    foreach (range(1, 5) as $seed) {
        $plan = app(BracketGenerator::class)->doubleElimination(range(1, $n));
        $rng = new Randomizer(new Mt19937($seed));
        $progressor = app(BracketProgressor::class);

        $wbOpponents = [];
        $playedLb = [];

        do {
            $playable = collect($plan->matches())
                ->first(fn ($m) => $m->isPlayable() && ! $m->isDecided());

            if ($playable === null) {
                break;
            }

            $a = $playable->slot1->entryId();
            $b = $playable->slot2->entryId();

            if ($a !== null && $b !== null) {
                if ($playable->bracket === Bracket::Losers && $playable->round < $exemptFrom) {
                    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                        expect(isset($playedLb[$x]) || ! isset($wbOpponents[$x][$y]))
                            ->toBeTrue("entry {$x} opened its LB run against WB opponent {$y} in LB round {$playable->round} (n={$n}, seed={$seed})");
                    }

                    $playedLb[$a] = true;
                    $playedLb[$b] = true;
                } elseif ($playable->bracket === Bracket::Losers) {
                    $playedLb[$a] = true;
                    $playedLb[$b] = true;
                }

                if ($playable->bracket === Bracket::Winners) {
                    $wbOpponents[$a][$b] = true;
                    $wbOpponents[$b][$a] = true;
                }
            }

            $s1 = $rng->getInt(0, 2);
            $s2 = $s1 === 2 ? $rng->getInt(0, 1) : 2;
            $plan = $progressor->apply($plan, $playable->id, $s1, $s2);
        } while (true);
    }
})->with([4, 8, 16, 32, 64]);
