<?php

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;

it('round-trips MenuOption[] through set and get, preserving int price_cents', function () {
    $order = FoodOrder::factory()->create([
        'menu' => [
            new MenuOption(key: 'pizza', name: 'Pizza Margherita', priceCents: 850),
            new MenuOption(key: 'salad', name: 'Salat', priceCents: 450),
        ],
    ]);

    $fresh = $order->fresh();

    expect($fresh->menu)->toBeArray()->and($fresh->menu)->toHaveCount(2);

    foreach ($fresh->menu as $option) {
        expect($option)->toBeInstanceOf(MenuOption::class)
            ->and($option->priceCents)->toBeInt();
    }

    expect($fresh->menu[0]->key)->toBe('pizza')
        ->and($fresh->menu[0]->name)->toBe('Pizza Margherita')
        ->and($fresh->menu[0]->priceCents)->toBe(850)
        ->and($fresh->menu[1]->key)->toBe('salad')
        ->and($fresh->menu[1]->priceCents)->toBe(450);
});

it('handles a null/empty menu', function () {
    $order = FoodOrder::factory()->create(['menu' => []]);

    expect($order->fresh()->menu)->toBe([]);
});

it('rejects a malformed menu entry missing required keys', function () {
    expect(fn () => FoodOrder::factory()->create([
        'menu' => [
            ['key' => 'pizza', 'name' => 'Pizza'],
        ],
    ]))->toThrow(InvalidArgumentException::class);
});

it('keeps price_cents as an int even if stored as a numeric string', function () {
    $option = MenuOption::fromArray(['key' => 'drink', 'name' => 'Cola', 'price_cents' => '250']);

    expect($option->priceCents)->toBeInt()->and($option->priceCents)->toBe(250);
});

it('round-trips MenuOption via toArray/fromArray', function () {
    $option = new MenuOption(key: 'pizza', name: 'Pizza', priceCents: 850);

    $array = $option->toArray();

    expect($array)->toBe(['key' => 'pizza', 'name' => 'Pizza', 'price_cents' => 850]);

    $rebuilt = MenuOption::fromArray($array);

    expect($rebuilt->key)->toBe('pizza')
        ->and($rebuilt->name)->toBe('Pizza')
        ->and($rebuilt->priceCents)->toBe(850);
});
