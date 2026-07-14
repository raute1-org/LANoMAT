<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketMatch;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Domain\Slot;

function twoMatchChain(): BracketPlan
{
    // Match 1 feeds its winner into Match 2, slot 1.
    $match1 = new BracketMatch(
        id: 1,
        round: 1,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(10),
        slot2: Slot::entry(20),
        nextMatch: 2,
        nextSlot: 1,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $match2 = new BracketMatch(
        id: 2,
        round: 2,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::pendingFrom(1),
        slot2: Slot::entry(30),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    return new BracketPlan([1 => $match1, 2 => $match2]);
}

it('looks up a match by id', function () {
    $plan = twoMatchChain();

    expect($plan->match(2)->id)->toBe(2)
        ->and($plan->match(2)->slot2->entryId())->toBe(30);
});

it('exposes all matches', function () {
    $plan = twoMatchChain();

    expect($plan->matches())->toHaveCount(2)
        ->and($plan->matches())->toHaveKeys([1, 2]);
});

it('determines the final match as the one without a next match in the highest bracket', function () {
    $plan = twoMatchChain();

    expect($plan->finalMatch()->id)->toBe(2);
});

it('replaces a match immutably via withMatch', function () {
    $plan = twoMatchChain();

    $updatedMatch1 = $plan->match(1)->withResult(score1: 3, score2: 1, winnerSlot: 1);
    $newPlan = $plan->withMatch($updatedMatch1);

    // Original plan is untouched.
    expect($plan->match(1)->isDecided())->toBeFalse()
        ->and($plan->match(1)->nextMatch)->toBe(2)
        ->and($newPlan)->not->toBe($plan)
        ->and($newPlan->match(1))->toBe($updatedMatch1)
        ->and($newPlan->match(1)->isDecided())->toBeTrue()
        ->and($newPlan->match(1)->nextMatch)->toBe(2)
        ->and($newPlan->match(2)->id)->toBe(2);
});

it('creates copies with withSlot without mutating the original', function () {
    $match = new BracketMatch(
        id: 5,
        round: 1,
        bracket: Bracket::Losers,
        position: 3,
        slot1: Slot::empty(),
        slot2: Slot::empty(),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $withSlot1 = $match->withSlot(1, Slot::entry(99));

    expect($match->slot1->isEmpty())->toBeTrue()
        ->and($withSlot1->slot1->entryId())->toBe(99)
        ->and($withSlot1->slot2->isEmpty())->toBeTrue()
        ->and($withSlot1)->not->toBe($match);
});

it('throws when looking up an unknown match id', function () {
    $plan = twoMatchChain();

    $plan->match(999);
})->throws(InvalidArgumentException::class);

it('throws when no match lacks a next match', function () {
    // Both matches point somewhere -> zero "final" matches.
    $match1 = new BracketMatch(
        id: 1,
        round: 1,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(10),
        slot2: Slot::entry(20),
        nextMatch: 2,
        nextSlot: 1,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $match2 = new BracketMatch(
        id: 2,
        round: 2,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::pendingFrom(1),
        slot2: Slot::entry(30),
        nextMatch: 1,
        nextSlot: 1,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    new BracketPlan([1 => $match1, 2 => $match2]);
})->throws(InvalidArgumentException::class);

it('throws when more than one match lacks a next match in a progressing plan', function () {
    // Match 3 progresses into match 1 (so this is unambiguously a
    // progressing/elimination-style plan, not a round-robin shape) — yet
    // both match 1 and match 2 are left without a next match, which must
    // still be rejected as "more than one final".
    $match1 = new BracketMatch(
        id: 1,
        round: 2,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::pendingFrom(3),
        slot2: Slot::entry(20),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $match2 = new BracketMatch(
        id: 2,
        round: 1,
        bracket: Bracket::Winners,
        position: 2,
        slot1: Slot::entry(30),
        slot2: Slot::entry(40),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $match3 = new BracketMatch(
        id: 3,
        round: 1,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(10),
        slot2: Slot::entry(50),
        nextMatch: 1,
        nextSlot: 1,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    new BracketPlan([1 => $match1, 2 => $match2, 3 => $match3]);
})->throws(InvalidArgumentException::class);

it('accepts a valid plan with exactly one final match', function () {
    $plan = twoMatchChain();

    expect($plan->finalMatch()->id)->toBe(2);
});

it('accepts a round-robin-shaped plan where every match lacks a next match', function () {
    // Round-robin: no progression at all, so *every* match has nextMatch ===
    // null by design. The exactly-one-terminal-match invariant does not
    // apply to such a non-progressing plan.
    $match1 = new BracketMatch(
        id: 1,
        round: 1,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(10),
        slot2: Slot::entry(20),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $match2 = new BracketMatch(
        id: 2,
        round: 2,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(10),
        slot2: Slot::entry(30),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $match3 = new BracketMatch(
        id: 3,
        round: 3,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(20),
        slot2: Slot::entry(30),
        nextMatch: null,
        nextSlot: null,
        loserNextMatch: null,
        loserNextSlot: null,
    );

    $plan = new BracketPlan([1 => $match1, 2 => $match2, 3 => $match3]);

    expect($plan->matches())->toHaveCount(3);
});
