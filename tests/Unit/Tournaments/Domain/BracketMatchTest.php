<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketMatch;
use App\Modules\Tournaments\Domain\Slot;

function freshMatch(): BracketMatch
{
    return new BracketMatch(
        id: 1,
        round: 1,
        bracket: Bracket::Winners,
        position: 1,
        slot1: Slot::entry(10),
        slot2: Slot::entry(20),
        nextMatch: 2,
        nextSlot: 1,
        loserNextMatch: 3,
        loserNextSlot: 2,
    );
}

it('has no result and is incomplete when freshly constructed', function () {
    $match = freshMatch();

    expect($match->score1)->toBeNull()
        ->and($match->score2)->toBeNull()
        ->and($match->winnerSlot)->toBeNull()
        ->and($match->isDecided())->toBeFalse();
});

it('withResult records score and winner state without touching routing', function () {
    $match = freshMatch();

    $completed = $match->withResult(score1: 3, score2: 1, winnerSlot: 1);

    expect($completed->score1)->toBe(3)
        ->and($completed->score2)->toBe(1)
        ->and($completed->winnerSlot)->toBe(1)
        ->and($completed->isDecided())->toBeTrue()
        // Routing fields are generation-time data and stay untouched.
        ->and($completed->nextMatch)->toBe($match->nextMatch)
        ->and($completed->nextSlot)->toBe($match->nextSlot)
        ->and($completed->loserNextMatch)->toBe($match->loserNextMatch)
        ->and($completed->loserNextSlot)->toBe($match->loserNextSlot)
        // Original is untouched.
        ->and($match->isDecided())->toBeFalse()
        ->and($completed)->not->toBe($match);
});

it('throws when winnerSlot is not 1, 2 or null', function () {
    $match = freshMatch();

    $match->withResult(score1: 3, score2: 1, winnerSlot: 3);
})->throws(InvalidArgumentException::class);

it('withRouting rewires next/loserNext fields without touching result state', function () {
    $match = freshMatch()->withResult(score1: 2, score2: 0, winnerSlot: 1);

    $rewired = $match->withRouting(nextMatch: 9, nextSlot: 2);

    expect($rewired->nextMatch)->toBe(9)
        ->and($rewired->nextSlot)->toBe(2)
        ->and($rewired->loserNextMatch)->toBe($match->loserNextMatch)
        ->and($rewired->loserNextSlot)->toBe($match->loserNextSlot)
        // Result state untouched.
        ->and($rewired->score1)->toBe(2)
        ->and($rewired->score2)->toBe(0)
        ->and($rewired->winnerSlot)->toBe(1)
        ->and($rewired->isDecided())->toBeTrue();
});

it('withSlot preserves any already-recorded result', function () {
    $match = freshMatch()->withResult(score1: 5, score2: 2, winnerSlot: 1);

    $updated = $match->withSlot(2, Slot::entry(99));

    expect($updated->slot2->entryId())->toBe(99)
        ->and($updated->score1)->toBe(5)
        ->and($updated->score2)->toBe(2)
        ->and($updated->winnerSlot)->toBe(1)
        ->and($updated->isDecided())->toBeTrue();
});
