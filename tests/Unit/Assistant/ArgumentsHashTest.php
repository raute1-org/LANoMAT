<?php

use App\Modules\Assistant\Support\ArgumentsHash;

it('is insensitive to key order, also nested', function () {
    expect(ArgumentsHash::of('t', ['a' => 1, 'b' => ['x' => 1, 'y' => 2]]))
        ->toBe(ArgumentsHash::of('t', ['b' => ['y' => 2, 'x' => 1], 'a' => 1]));
});

it('is sensitive to the tool name, values and list order', function () {
    $base = ArgumentsHash::of('t', ['a' => 1, 'l' => [1, 2]]);

    expect(ArgumentsHash::of('u', ['a' => 1, 'l' => [1, 2]]))->not->toBe($base)
        ->and(ArgumentsHash::of('t', ['a' => 2, 'l' => [1, 2]]))->not->toBe($base)
        ->and(ArgumentsHash::of('t', ['a' => 1, 'l' => [2, 1]]))->not->toBe($base);
});
