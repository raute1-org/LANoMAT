<?php

use App\Support\Money\Euro;

it('parses euro input to cents', function (?string $input, ?int $expected) {
    expect(Euro::toCents($input))->toBe($expected);
})->with([
    ['35', 3500],
    ['35,00', 3500],
    ['35.5', 3550],
    ['0,05', 5],
    ['1.234,50', 123450],
    ['35 €', 3500],
    [null, null],
    ['', null],
    ['abc', null],
    ['-5', null],
    ['1,234', null],
]);

it('formats cents as euro', function () {
    expect(Euro::format(3500))->toBe('35,00 €')
        ->and(Euro::format(5))->toBe('0,05 €')
        ->and(Euro::format(123450))->toBe('1.234,50 €');
});
