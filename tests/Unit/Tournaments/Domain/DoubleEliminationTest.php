<?php

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketGenerator;

it('produces exactly one grand final and one reset match', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    $finals = collect($plan->matches())->filter(fn ($m) => $m->bracket === Bracket::Finals);

    // Exactly two finals matches: GF1 (chains into the reset) and GF2 (the reset, terminal).
    expect($finals)->toHaveCount(2);

    $withoutNext = $finals->filter(fn ($m) => $m->nextMatch === null);
    $withNext = $finals->filter(fn ($m) => $m->nextMatch !== null);

    expect($withoutNext)->toHaveCount(1) // GF2 (reset): the sole terminal match of the whole plan.
        ->and($withNext)->toHaveCount(1); // GF1: chains into GF2.

    $gf1 = $withNext->first();
    $gf2 = $withoutNext->first();

    expect($gf1->nextMatch)->toBe($gf2->id);

    // The plan-wide terminal match is unique and it is GF2.
    expect($plan->finalMatch()->id)->toBe($gf2->id);
})->with([4, 8, 16, 6]);

it('routes every non-final winners-bracket match loser into the losers bracket', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    // A bye match has no real loser (nobody actually lost), so it never
    // routes a loser into the LB — that only applies to WB round 1 for
    // non-power-of-two sizes (n=6 here: 2 byes among the padding entries).
    $wbMatches = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners)
        ->reject(fn ($m) => $m->slot1->isBye() || $m->slot2->isBye());

    // Every real (non-bye) WB match, including the WB final, must route its
    // loser into the LB (the WB final's loser goes to the last major LB
    // round; earlier WB losers seed LB intake rounds).
    $wbMatches->each(function ($m) use ($plan) {
        expect($m->loserNextMatch)->not->toBeNull();
        expect($plan->matches())->toHaveKey($m->loserNextMatch);
        expect($plan->match($m->loserNextMatch)->bracket)->toBe(Bracket::Losers);
        expect($m->loserNextSlot)->toBeIn([1, 2]);
    });
})->with([4, 8, 16, 6]);

it('leaves bye winners-bracket matches without an lb route (nobody really lost)', function () {
    $plan = app(BracketGenerator::class)->doubleElimination(entries(6)); // size 8 -> 2 byes

    $byeMatches = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners && ($m->slot1->isBye() || $m->slot2->isBye()));

    expect($byeMatches)->toHaveCount(2);

    $byeMatches->each(function ($m) {
        expect($m->loserNextMatch)->toBeNull()
            ->and($m->loserNextSlot)->toBeNull();
    });
});

it('has 2*(log2(size)-1) losers-bracket rounds', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    $size = 1;
    while ($size < $n) {
        $size *= 2;
    }
    $expectedLbRounds = 2 * (int) (log($size, 2) - 1);

    $lbRounds = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Losers)
        ->pluck('round')
        ->unique();

    expect($lbRounds)->toHaveCount($expectedLbRounds);
})->with([4, 8, 16, 6]);

it('chains every losers-bracket match forward, ending at the grand final', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Losers)
        ->each(function ($m) use ($plan) {
            expect($m->nextMatch)->not->toBeNull();
            expect($plan->matches())->toHaveKey($m->nextMatch);
            $target = $plan->match($m->nextMatch);
            expect($target->bracket)->toBeIn([Bracket::Losers, Bracket::Finals]);
        });

    // The losers bracket ultimately feeds into the grand final (GF1).
    $lbFinal = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Losers)
        ->sortByDesc('round')
        ->first();

    expect($plan->match($lbFinal->nextMatch)->bracket)->toBe(Bracket::Finals);
})->with([4, 8, 16, 6]);

it('has the winners-bracket champion feed grand final slot 1 and the losers-bracket champion feed slot 2', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    $wbFinal = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners)
        ->sortByDesc('round')
        ->first();

    $lbFinal = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Losers)
        ->sortByDesc('round')
        ->first();

    $gf1 = $plan->match($wbFinal->nextMatch);

    expect($gf1->id)->toBe($lbFinal->nextMatch)
        ->and($wbFinal->nextSlot)->toBe(1)
        ->and($lbFinal->nextSlot)->toBe(2);
})->with([4, 8, 16, 6]);

it('places byes only in the winners bracket for non-power-of-two sizes', function () {
    $plan = app(BracketGenerator::class)->doubleElimination(entries(6)); // size 8 -> 2 byes

    $wbByes = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners && $m->round === 1)
        ->flatMap(fn ($m) => [$m->slot1, $m->slot2])
        ->filter(fn ($s) => $s->isBye());

    expect($wbByes)->toHaveCount(2);

    // No bye ever appears inside the losers bracket or the finals.
    collect($plan->matches())
        ->filter(fn ($m) => $m->bracket !== Bracket::Winners || $m->round !== 1)
        ->each(function ($m) {
            expect($m->slot1->isBye())->toBeFalse();
            expect($m->slot2->isBye())->toBeFalse();
        });
});

it('preserves every real entrant exactly once across winners-bracket round 1', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    $occupants = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners && $m->round === 1)
        ->flatMap(fn ($m) => array_filter([$m->slot1->entryId(), $m->slot2->entryId()], fn ($e) => $e !== null));

    expect($occupants->unique()->count())->toBe($n);
})->with([4, 8, 16, 6]);

it('gives the grand final winners-bracket slot as pending from the winners-bracket final', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    $wbFinal = collect($plan->matches())
        ->filter(fn ($m) => $m->bracket === Bracket::Winners)
        ->sortByDesc('round')
        ->first();

    $gf1 = $plan->match($wbFinal->nextMatch);

    expect($gf1->slot1->isPending())->toBeTrue()
        ->and($gf1->slot1->pendingFromMatchId())->toBe($wbFinal->id)
        ->and($gf1->slot2->isPending())->toBeTrue();
})->with([4, 8, 16, 6]);

it('gives the reset match both slots pending from the grand final', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    $gf2 = $plan->finalMatch();
    $gf1 = collect($plan->matches())->first(fn ($m) => $m->nextMatch === $gf2->id);

    expect($gf2->slot1->isPending())->toBeTrue()
        ->and($gf2->slot1->pendingFromMatchId())->toBe($gf1->id)
        ->and($gf2->slot2->isPending())->toBeTrue()
        ->and($gf2->slot2->pendingFromMatchId())->toBe($gf1->id);
})->with([4, 8, 16, 6]);

it('has exactly 2n-1 potential matches (n-1 WB + n-2 LB + 2 finals) for a power-of-two size', function (int $n) {
    $plan = app(BracketGenerator::class)->doubleElimination(entries($n));

    // Standard double elimination match count for a clean power-of-two bracket:
    // (n-1) WB matches + (n-2) LB matches + 2 grand-final matches (GF1 + reset) = 2n-1.
    expect($plan->matches())->toHaveCount(2 * $n - 1);
})->with([4, 8, 16]);

it('rejects bracket sizes beyond the exhaustively verified LB intake ordering table', function () {
    // The LB intake ordering table is only verified for size 4, 8 and 16
    // (this engine's exhaustively tested sizes) — a bracket that resolves
    // to size 32 must fail loudly rather than silently apply an unverified
    // ordering.
    app(BracketGenerator::class)->doubleElimination(entries(17));
})->throws(InvalidArgumentException::class);
