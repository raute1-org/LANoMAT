<?php

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;

it('refuses to persist a menu price above the maximum', function () {
    $order = new FoodOrder;

    expect(fn () => $order->menu = [
        new MenuOption(key: 'too-much', name: 'Zu teuer', priceCents: MenuOption::MAX_PRICE_CENTS + 1),
    ])->toThrow(InvalidArgumentException::class);
});

it('refuses to persist a negative menu price', function () {
    $order = new FoodOrder;

    expect(fn () => $order->menu = [
        ['key' => 'negative', 'name' => 'Negativ', 'price_cents' => -1],
    ])->toThrow(InvalidArgumentException::class);
});

it('persists a menu price at exactly the maximum', function () {
    $order = new FoodOrder;
    $order->menu = [new MenuOption(key: 'max', name: 'Maximal', priceCents: MenuOption::MAX_PRICE_CENTS)];

    expect($order->getAttributes()['menu'])->toContain('"price_cents":100000');
});
