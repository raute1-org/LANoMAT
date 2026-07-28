<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketGenerator;
use App\Modules\Tournaments\Domain\BracketProgressor;
use App\Modules\Tournaments\Domain\MatchOutcome;

it('advances winners and eliminates losers through a single-elimination bracket', function () {
    $plan = app(BracketGenerator::class)->singleElimination(entries(4));
    $progressor = app(BracketProgressor::class);

    // Round 1: two matches, ids 1 and 2, feeding into match 3 (the final).
    $plan = $progressor->apply($plan, 1, 3, 1);
    $plan = $progressor->apply($plan, 2, 0, 2);

    $final = $plan->match(3);

    expect($final->slot1->isEntry())->toBeTrue()
        ->and($final->slot1->entryId())->toBe($plan->match(1)->winnerEntryId())
        ->and($final->slot2->isEntry())->toBeTrue()
        ->and($final->slot2->entryId())->toBe($plan->match(2)->winnerEntryId())
        ->and($final->isPlayable())->toBeTrue()
        ->and($final->isDecided())->toBeFalse();

    $plan = $progressor->apply($plan, 3, 2, 1);

    expect($plan->finalMatch()->isDecided())->toBeTrue()
        ->and($plan->finalMatch()->winnerEntryId())->toBe($final->slot1->entryId());
});

it('auto-advances a bye winner without requiring a report', function () {
    $plan = app(BracketGenerator::class)->singleElimination(entries(6)); // size 8 -> 2 byes
    $progressor = app(BracketProgressor::class);

    // Applying any playable round-1 result should immediately resolve the
    // generator's pre-existing bye matches too: their real occupant should
    // already sit in round 2 without any report against them.
    $byeMatches = collect($plan->matches())
        ->filter(fn ($m) => $m->round === 1 && ($m->slot1->isBye() || $m->slot2->isBye()));

    expect($byeMatches)->toHaveCount(2);

    // Trigger auto-resolution by applying a result to one real match — the
    // progressor must resolve all resolvable byes as part of every apply().
    $playable = collect($plan->matches())->first(fn ($m) => $m->isPlayable());
    $plan = $progressor->apply($plan, $playable->id, 2, 0);

    foreach ($byeMatches as $byeMatch) {
        $real = $byeMatch->slot1->isBye() ? $byeMatch->slot2 : $byeMatch->slot1;
        $advanced = $plan->match($byeMatch->id);

        expect($advanced->isDecided())->toBeTrue()
            ->and($advanced->winnerEntryId())->toBe($real->entryId());

        $next = $plan->match($advanced->nextMatch);
        $nextSlot = $advanced->nextSlot === 1 ? $next->slot1 : $next->slot2;

        expect($nextSlot->isEntry())->toBeTrue()
            ->and($nextSlot->entryId())->toBe($real->entryId());
    }
});

it('routes a winners-bracket loser into the correct losers-bracket match', function () {
    $plan = app(BracketGenerator::class)->doubleElimination(entries(4));
    $progressor = app(BracketProgressor::class);

    $wbRound1 = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners && $m->round === 1)
        ->values();

    $match = $wbRound1[0];
    $loserEntryId = $match->slot2->entryId();

    $plan = $progressor->apply($plan, $match->id, 3, 1);

    $lbMatch = $plan->match($match->loserNextMatch);
    $lbSlot = $match->loserNextSlot === 1 ? $lbMatch->slot1 : $lbMatch->slot2;

    expect($lbSlot->isEntry())->toBeTrue()
        ->and($lbSlot->entryId())->toBe($loserEntryId);
});

it('lets the losers-bracket champion reach the grand final', function () {
    $plan = app(BracketGenerator::class)->doubleElimination(entries(4));
    $progressor = app(BracketProgressor::class);

    // Play out the whole bracket with slot 1 always winning, except we let
    // the losers bracket run its course naturally via the auto-propagation.
    do {
        $playable = collect($plan->matches())->first(fn ($m) => $m->isPlayable() && ! $m->isDecided());
        if ($playable === null) {
            break;
        }
        $plan = $progressor->apply($plan, $playable->id, 1, 0);
    } while (true);

    $gf1 = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Finals)
        ->sortBy('round')
        ->first();

    expect($gf1->isDecided())->toBeTrue()
        ->and($gf1->winnerEntryId())->not->toBeNull();
});

it('applyForfeit awards the win to the opponent with a 0 score for the forfeiter', function () {
    $plan = app(BracketGenerator::class)->singleElimination(entries(4));
    $progressor = app(BracketProgressor::class);

    $match = collect($plan->matches())->first(fn ($m) => $m->round === 1);
    $opponentEntryId = $match->slot2->entryId();

    $plan = $progressor->applyForfeit($plan, $match->id, MatchOutcome::ForfeitSlot1);

    $decided = $plan->match($match->id);

    expect($decided->score1)->toBe(0)
        ->and($decided->winnerSlot)->toBe(2)
        ->and($decided->winnerEntryId())->toBe($opponentEntryId)
        ->and($decided->isDecided())->toBeTrue();
});

it('activates the grand-final reset when the losers-bracket entrant wins GF1', function () {
    $plan = app(BracketGenerator::class)->doubleElimination(entries(4));
    $progressor = app(BracketProgressor::class);

    // Play the WB side straight through (slot 1 always wins) so the WB
    // champion never loses before the grand final; play the LB side so
    // that eventually an LB champion reaches GF1 slot 2.
    do {
        $playable = collect($plan->matches())->first(fn ($m) => $m->isPlayable() && ! $m->isDecided() && $m->bracket !== Bracket::Finals);
        if ($playable === null) {
            break;
        }
        $plan = $progressor->apply($plan, $playable->id, 1, 0);
    } while (true);

    $gf1 = collect($plan->matches())->first(fn ($m) => $m->bracket === Bracket::Finals && $m->round === 1);
    $gf2 = $plan->finalMatch();

    expect($gf1->isPlayable())->toBeTrue();

    // LB entrant (slot 2) wins GF1 -> reset becomes live.
    $plan = $progressor->apply($plan, $gf1->id, 1, 3);

    $gf1Decided = $plan->match($gf1->id);
    $gf2Live = $plan->match($gf2->id);

    expect($gf1Decided->winnerSlot)->toBe(2)
        ->and($gf2Live->isPlayable())->toBeTrue()
        ->and($gf2Live->slot1->entryId())->toBe($gf1Decided->slot1->entryId())
        ->and($gf2Live->slot2->entryId())->toBe($gf1Decided->slot2->entryId());

    $plan = $progressor->apply($plan, $gf2->id, 2, 3);

    expect($plan->match($gf2->id)->isDecided())->toBeTrue()
        ->and($plan->match($gf2->id)->winnerEntryId())->not->toBeNull();
});

it('auto-advances past a losers-bracket match whose both feeders were byes', function () {
    // Bracket size 8 with only 5 entrants leaves 3 byes. The two winners-
    // bracket round-1 bye matches sit next to each other, so the losers-
    // bracket round-1 match that would collect *their* losers can never
    // receive an entrant on either side — nobody actually loses a bye. That
    // match can never be played ("dead"), and every match waiting on its
    // winner must therefore auto-advance its own real entrant instead of
    // waiting forever.
    $plan = app(BracketGenerator::class)->doubleElimination(entries(5));
    $progressor = app(BracketProgressor::class);

    $phantom = collect($plan->matches())
        ->first(fn ($m) => $m->bracket === Bracket::Losers
            && $m->round === 1
            && $m->slot1->isEmpty()
            && $m->slot2->isEmpty()
            && ! collect($plan->matches())->contains(fn ($other) => $other->loserNextMatch === $m->id));

    expect($phantom)->not->toBeNull('n=5 must produce a losers-bracket match with two bye feeders.');

    $downstreamId = $phantom->nextMatch;
    expect($downstreamId)->not->toBeNull();

    // Play every reportable match, slot 1 always winning.
    do {
        $playable = collect($plan->matches())->first(fn ($m) => $m->isPlayable() && ! $m->isDecided());
        if ($playable === null) {
            break;
        }
        $plan = $progressor->apply($plan, $playable->id, 1, 0);
    } while (true);

    // The dead match itself stays undecided — no phantom winner is invented.
    expect($plan->match($phantom->id)->isDecided())->toBeFalse()
        ->and($plan->match($phantom->id)->winnerEntryId())->toBeNull();

    // Its downstream match advanced its one real entrant instead of stalling.
    $downstream = $plan->match($downstreamId);
    expect($downstream->isDecided())->toBeTrue()
        ->and($downstream->winnerEntryId())->not->toBeNull();

    // And the bracket ran all the way to a champion.
    $champion = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Finals && $m->isDecided())
        ->sortByDesc(fn ($m) => $m->round)
        ->first();

    expect($champion?->winnerEntryId())->not->toBeNull();
});

it('ends the tournament without a reset when the winners-bracket entrant wins GF1', function () {
    $plan = app(BracketGenerator::class)->doubleElimination(entries(4));
    $progressor = app(BracketProgressor::class);

    do {
        $playable = collect($plan->matches())->first(fn ($m) => $m->isPlayable() && ! $m->isDecided() && $m->bracket !== Bracket::Finals);
        if ($playable === null) {
            break;
        }
        $plan = $progressor->apply($plan, $playable->id, 1, 0);
    } while (true);

    $gf1 = collect($plan->matches())->first(fn ($m) => $m->bracket === Bracket::Finals && $m->round === 1);
    $gf2 = $plan->finalMatch();

    // WB entrant (slot 1) wins GF1 -> tournament is decided, reset is obsolete.
    $plan = $progressor->apply($plan, $gf1->id, 3, 1);

    $gf1Decided = $plan->match($gf1->id);
    $gf2AfterGf1 = $plan->match($gf2->id);

    expect($gf1Decided->winnerSlot)->toBe(1)
        ->and($gf1Decided->winnerEntryId())->not->toBeNull()
        ->and($gf2AfterGf1->isDecided())->toBeFalse()
        ->and($gf2AfterGf1->isPlayable())->toBeFalse();

    // No match remains open (playable and undecided) anywhere in the plan.
    $open = collect($plan->matches())->filter(fn ($m) => $m->isPlayable() && ! $m->isDecided());
    expect($open)->toBeEmpty();
});

it('never treats the unused grand-final reset as a dead match', function (int $n) {
    // Regression pin for the dead-match propagation: GF2's slots await GF1,
    // and when the winners-bracket side wins GF1 the reset is deliberately
    // left unarmed and undecided forever. That is a *decided* feeder choosing
    // not to deliver, not a dead one — the reset must therefore never be
    // classified as dead and never auto-resolve a phantom champion.
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));
    $progressor = app(BracketProgressor::class);

    do {
        $playable = collect($plan->matches())
            ->first(fn ($m) => $m->isPlayable() && ! $m->isDecided() && $m->bracket !== Bracket::Finals);
        if ($playable === null) {
            break;
        }
        $plan = $progressor->apply($plan, $playable->id, 1, 0);
    } while (true);

    $gf1 = collect($plan->matches())->first(fn ($m) => $m->bracket === Bracket::Finals && $m->round === 1);
    $gf2 = $plan->finalMatch();

    expect($progressor->deadMatchIds($plan))->not->toContain($gf2->id);

    // Winners-bracket side (slot 1) wins GF1 -> the reset stays unarmed.
    $plan = $progressor->apply($plan, $gf1->id, 3, 1);

    $gf2AfterGf1 = $plan->match($gf2->id);

    expect($progressor->deadMatchIds($plan))->not->toContain($gf2->id)
        ->and($gf2AfterGf1->isDecided())->toBeFalse()
        ->and($gf2AfterGf1->winnerEntryId())->toBeNull()
        ->and($gf2AfterGf1->isPlayable())->toBeFalse()
        ->and($gf2AfterGf1->slot1->isEntry())->toBeFalse()
        ->and($gf2AfterGf1->slot2->isEntry())->toBeFalse();
})->with([4, 6, 8, 32]);

it('reports only matches that can never be played as dead', function () {
    $progressor = app(BracketProgressor::class);

    // A power-of-two field has no byes at all, so nothing is ever dead.
    $full = app(BracketGenerator::class)->doubleElimination(entries(8));
    expect($progressor->deadMatchIds($full))->toBeEmpty();

    // n=6 has two byes, but they provably never meet in the same
    // losers-bracket match — every match is still reachable.
    $six = app(BracketGenerator::class)->doubleElimination(entries(6));
    expect($progressor->deadMatchIds($six))->toBeEmpty();

    // n=5 puts two byes into the same losers-bracket round-1 match, which is
    // therefore unplayable by anyone, forever.
    $five = app(BracketGenerator::class)->doubleElimination(entries(5));
    $dead = $progressor->deadMatchIds($five);

    expect($dead)->toHaveCount(1);

    $deadMatch = $five->match($dead[0]);

    expect($deadMatch->bracket)->toBe(Bracket::Losers)
        ->and($deadMatch->round)->toBe(1)
        ->and($deadMatch->slot1->isEmpty())->toBeTrue()
        ->and($deadMatch->slot2->isEmpty())->toBeTrue();

    // Deadness is a structural property, not a phase: it still holds once the
    // bracket has been played out, and the dead match never gains a winner.
    do {
        $playable = collect($five->matches())->first(fn ($m) => $m->isPlayable() && ! $m->isDecided());
        if ($playable === null) {
            break;
        }
        $five = $progressor->apply($five, $playable->id, 1, 0);
    } while (true);

    expect($progressor->deadMatchIds($five))->toBe($dead)
        ->and($five->match($dead[0])->isDecided())->toBeFalse();
});
