<?php

use App\Modules\Tournaments\Domain\Slot;

it('represents an entry slot', function () {
    $s = Slot::entry(42);
    expect($s->isEntry())->toBeTrue()
        ->and($s->entryId())->toBe(42)
        ->and($s->isBye())->toBeFalse()
        ->and($s->isPending())->toBeFalse()
        ->and($s->pendingFromMatchId())->toBeNull();
});

it('represents a bye slot', function () {
    $s = Slot::bye();
    expect($s->isBye())->toBeTrue()
        ->and($s->isEntry())->toBeFalse()
        ->and($s->isPending())->toBeFalse()
        ->and($s->entryId())->toBeNull();
});

it('represents a pending slot', function () {
    $s = Slot::pendingFrom(7);
    expect($s->isPending())->toBeTrue()
        ->and($s->pendingFromMatchId())->toBe(7)
        ->and($s->isEntry())->toBeFalse()
        ->and($s->isBye())->toBeFalse();
});

it('represents an empty slot', function () {
    $s = Slot::empty();
    expect($s->isEmpty())->toBeTrue()
        ->and($s->isEntry())->toBeFalse()
        ->and($s->isBye())->toBeFalse()
        ->and($s->isPending())->toBeFalse();
});

it('is not empty once it is an entry, bye or pending slot', function () {
    expect(Slot::entry(1)->isEmpty())->toBeFalse()
        ->and(Slot::bye()->isEmpty())->toBeFalse()
        ->and(Slot::pendingFrom(1)->isEmpty())->toBeFalse();
});

it('each factory yields exactly one facet, mutually exclusive with the others', function () {
    $facetsOf = fn (Slot $s): int => ($s->isEntry() ? 1 : 0) + ($s->isBye() ? 1 : 0) + ($s->isPending() ? 1 : 0);

    expect($facetsOf(Slot::entry(1)))->toBe(1)
        ->and($facetsOf(Slot::bye()))->toBe(1)
        ->and($facetsOf(Slot::pendingFrom(1)))->toBe(1)
        ->and($facetsOf(Slot::empty()))->toBe(0);
});

function constructSlotViaReflection(?int $entryId, bool $bye, ?int $pendingFromMatchId): Slot
{
    $class = new ReflectionClass(Slot::class);
    $constructor = $class->getConstructor();
    $constructor->setAccessible(true);

    $slot = $class->newInstanceWithoutConstructor();
    $constructor->invoke($slot, $entryId, $bye, $pendingFromMatchId);

    return $slot;
}

it('throws when entryId and bye are both set', function () {
    constructSlotViaReflection(1, true, null);
})->throws(InvalidArgumentException::class);

it('throws when entryId and pendingFromMatchId are both set', function () {
    constructSlotViaReflection(1, false, 7);
})->throws(InvalidArgumentException::class);

it('throws when bye and pendingFromMatchId are both set', function () {
    constructSlotViaReflection(null, true, 7);
})->throws(InvalidArgumentException::class);
