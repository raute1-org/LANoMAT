<?php

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;

it('creates a food order via the factory with a typed menu and enum status', function () {
    $order = FoodOrder::factory()->create();

    expect($order->event)->toBeInstanceOf(Event::class)
        ->and($order->status)->toBeInstanceOf(FoodOrderStatus::class)
        ->and($order->menu)->toBeArray();

    foreach ($order->menu as $option) {
        expect($option)->toBeInstanceOf(MenuOption::class);
    }

    expect(count($order->menu))->toBeGreaterThanOrEqual(2)
        ->and(count($order->menu))->toBeLessThanOrEqual(3);
});

it('exposes the items relation', function () {
    $order = FoodOrder::factory()->create();

    expect($order->items()->count())->toBe(0);
});

it('isOpenNow is true when status is Open and now() is within the window', function () {
    $order = FoodOrder::factory()->open()->create([
        'opens_at' => now()->subHour(),
        'closes_at' => now()->addHour(),
    ]);

    test()->travelTo(now(), function () use ($order) {
        expect($order->isOpenNow())->toBeTrue();
    });
});

it('isOpenNow is false before the opening time', function () {
    $order = FoodOrder::factory()->open()->create([
        'opens_at' => now()->addHour(),
        'closes_at' => now()->addHours(2),
    ]);

    expect($order->isOpenNow())->toBeFalse();
});

it('isOpenNow is false after the closing time', function () {
    $order = FoodOrder::factory()->open()->create([
        'opens_at' => now()->subHours(2),
        'closes_at' => now()->subHour(),
    ]);

    expect($order->isOpenNow())->toBeFalse();
});

it('isOpenNow is false when status is Draft even within the window', function () {
    $order = FoodOrder::factory()->create([
        'status' => FoodOrderStatus::Draft,
        'opens_at' => now()->subHour(),
        'closes_at' => now()->addHour(),
    ]);

    expect($order->isOpenNow())->toBeFalse();
});

it('closed factory state sets the Closed status', function () {
    $order = FoodOrder::factory()->closed()->create();

    expect($order->status)->toBe(FoodOrderStatus::Closed)
        ->and($order->isOpenNow())->toBeFalse();
});

it('has German labels for each status', function () {
    expect(FoodOrderStatus::Draft->label())->toBe('Entwurf')
        ->and(FoodOrderStatus::Open->label())->toBe('Offen')
        ->and(FoodOrderStatus::Closed->label())->toBe('Geschlossen');
});
