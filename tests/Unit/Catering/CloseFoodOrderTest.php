<?php

use App\Models\User;
use App\Modules\Catering\Actions\CloseFoodOrder;
use App\Modules\Catering\Actions\OpenFoodOrder;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\CostSplit;

it('opens a draft order', function () {
    $order = FoodOrder::factory()->create(['status' => FoodOrderStatus::Draft]);

    $opened = (new OpenFoodOrder)->handle($order);

    expect($opened->status)->toBe(FoodOrderStatus::Open);
    $this->assertDatabaseHas('food_orders', ['id' => $order->id, 'status' => FoodOrderStatus::Open->value]);
});

it('rejects opening an order that is not a draft', function () {
    $order = FoodOrder::factory()->closed()->create();

    try {
        (new OpenFoodOrder)->handle($order);
        $this->fail('Expected DomainException.');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('closed')->toContain('open');
    }
});

it('computes the cost split by user and by menu option, tracking paid vs unpaid', function () {
    $order = FoodOrder::factory()->open()->create();

    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    FoodOrderItem::factory()->for($order, 'foodOrder')->for($alice)->paid()->create([
        'selection' => ['option_key' => 'pizza_margherita'],
        'price_cents' => 850,
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($alice)->create([
        'selection' => ['option_key' => 'salad'],
        'price_cents' => 450,
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($bob)->create([
        'selection' => ['option_key' => 'pizza_margherita'],
        'price_cents' => 850,
    ]);

    $split = CostSplit::for($order->fresh());

    expect($split['grandTotalCents'])->toBe(2150)
        ->and($split['perUser'][$alice->id]['name'])->toBe('Alice')
        ->and($split['perUser'][$alice->id]['totalCents'])->toBe(1300)
        ->and($split['perUser'][$alice->id]['paidCents'])->toBe(850)
        ->and($split['perUser'][$bob->id]['name'])->toBe('Bob')
        ->and($split['perUser'][$bob->id]['totalCents'])->toBe(850)
        ->and($split['perUser'][$bob->id]['paidCents'])->toBe(0)
        ->and($split['byOption']['pizza_margherita']['name'])->toBe('Pizza Margherita')
        ->and($split['byOption']['pizza_margherita']['count'])->toBe(2)
        ->and($split['byOption']['pizza_margherita']['totalCents'])->toBe(1700)
        ->and($split['byOption']['salad']['count'])->toBe(1)
        ->and($split['byOption']['salad']['totalCents'])->toBe(450);
});

it('closes an open order and blocks further placement afterwards', function () {
    $order = FoodOrder::factory()->open()->create();
    $user = User::factory()->create();

    $closed = (new CloseFoodOrder)->handle($order);

    expect($closed->status)->toBe(FoodOrderStatus::Closed);
    $this->assertDatabaseHas('food_orders', ['id' => $order->id, 'status' => FoodOrderStatus::Closed->value]);

    try {
        (new PlaceFoodOrderItem)->handle($closed, $user, 'pizza_margherita');
        $this->fail('Expected CateringException.');
    } catch (CateringException $e) {
        expect($e->translationKey)->toBe('catering.errors.not_open');
    }
});

it('rejects closing an order that is not open', function () {
    $order = FoodOrder::factory()->create(['status' => FoodOrderStatus::Draft]);

    try {
        (new CloseFoodOrder)->handle($order);
        $this->fail('Expected DomainException.');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('draft')->toContain('closed');
    }
});
