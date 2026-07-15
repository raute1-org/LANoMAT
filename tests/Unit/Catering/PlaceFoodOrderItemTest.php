<?php

use App\Models\User;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;

it('places a food order item within the open window, copying the price from the menu', function () {
    $order = FoodOrder::factory()->open()->create();
    $user = User::factory()->create();

    $item = (new PlaceFoodOrderItem)->handle($order, $user, 'pizza_salami', 'no onions');

    expect($item)->toBeInstanceOf(FoodOrderItem::class)
        ->and($item->food_order_id)->toBe($order->id)
        ->and($item->user_id)->toBe($user->id)
        ->and($item->price_cents)->toBe(950)
        ->and($item->selection)->toBe(['option_key' => 'pizza_salami', 'note' => 'no onions']);

    $this->assertDatabaseHas('food_order_items', [
        'id' => $item->id,
        'food_order_id' => $order->id,
        'user_id' => $user->id,
        'price_cents' => 950,
    ]);
});

it('rejects placement while the order is still a draft', function () {
    $order = FoodOrder::factory()->create();
    $user = User::factory()->create();

    try {
        (new PlaceFoodOrderItem)->handle($order, $user, 'pizza_margherita');
        $this->fail('Expected CateringException.');
    } catch (CateringException $e) {
        expect($e->translationKey)->toBe('catering.errors.not_open');
    }
});

it('rejects placement once the order is closed', function () {
    $order = FoodOrder::factory()->closed()->create();
    $user = User::factory()->create();

    try {
        (new PlaceFoodOrderItem)->handle($order, $user, 'pizza_margherita');
        $this->fail('Expected CateringException.');
    } catch (CateringException $e) {
        expect($e->translationKey)->toBe('catering.errors.not_open');
    }
});

it('rejects placement outside the open time window even if status is open', function () {
    $order = FoodOrder::factory()->create([
        'status' => FoodOrderStatus::Open,
        'opens_at' => now()->addHour(),
        'closes_at' => now()->addHours(2),
    ]);
    $user = User::factory()->create();

    try {
        (new PlaceFoodOrderItem)->handle($order, $user, 'pizza_margherita');
        $this->fail('Expected CateringException.');
    } catch (CateringException $e) {
        expect($e->translationKey)->toBe('catering.errors.not_open');
    }
});

it('never trusts a client-supplied price, always copying price_cents from the resolved menu option', function () {
    // Pin an explicit menu rather than relying on the factory's randomized
    // slice, so the 'salad' option is guaranteed present.
    $order = FoodOrder::factory()->open()->create([
        'menu' => [
            new MenuOption(key: 'salad', name: 'Salat', priceCents: 450),
        ],
    ]);
    $user = User::factory()->create();

    // The Action signature doesn't even accept a price argument, but this
    // guards against a future regression: verify the persisted price always
    // matches the menu's authoritative value regardless of any bogus value
    // that might land in $note or be attempted via mass assignment.
    $item = (new PlaceFoodOrderItem)->handle($order, $user, 'salad', note: '1'); // price_cents=1 as bogus note

    expect($item->price_cents)->toBe(450);
});

it('throws when the option key does not exist on the order menu', function () {
    $order = FoodOrder::factory()->open()->create();
    $user = User::factory()->create();

    try {
        (new PlaceFoodOrderItem)->handle($order, $user, 'does-not-exist');
        $this->fail('Expected CateringException.');
    } catch (CateringException $e) {
        expect($e->translationKey)->toBe('catering.errors.unknown_option');
    }
});
