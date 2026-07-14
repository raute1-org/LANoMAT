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
