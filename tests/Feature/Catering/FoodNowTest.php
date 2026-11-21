<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\FoodNow;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return list<MenuOption> */
function foodNowMenu(): array
{
    return [
        new MenuOption(key: 'pizza_margherita', name: 'Margherita', priceCents: 850),
        new MenuOption(key: 'cola', name: 'Cola', priceCents: 200),
    ];
}

it('returns both sides empty when the event has no food rounds', function () {
    $event = Event::factory()->registration()->create();

    expect(FoodNow::for($event, User::factory()->create()))->toBe([
        'open' => null,
        'next' => null,
    ]);
});

it('describes the open round with its order count and the viewers own items', function () {
    $event = Event::factory()->registration()->create();
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    $order = FoodOrder::factory()->for($event)->open()->create([
        'title' => 'Pizza-Runde 1',
        'menu' => foodNowMenu(),
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($viewer)->create(['selection' => ['option_key' => 'pizza_margherita'], 'price_cents' => 850]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($viewer)->create(['selection' => ['option_key' => 'pizza_margherita'], 'price_cents' => 850]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($viewer)->create(['selection' => ['option_key' => 'cola'], 'price_cents' => 200]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($other)->create(['selection' => ['option_key' => 'cola'], 'price_cents' => 200]);

    $now = FoodNow::for($event, $viewer);

    expect($now['open'])->toMatchArray([
        'title' => 'Pizza-Runde 1',
        'orderCount' => 2,
        'mine' => '2× Margherita, 1× Cola',
        'href' => route('catering.show', $event),
    ]);
    expect($now['open']['closesAt'])->toBe($order->closes_at->toIso8601String());
    expect($now['next'])->toBeNull();
});

it('reports mine as null and the count as zero when the viewer has not ordered', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create(['menu' => foodNowMenu()]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->create(['selection' => ['option_key' => 'cola'], 'price_cents' => 200]);

    $now = FoodNow::for($event, User::factory()->create());

    expect($now['open']['orderCount'])->toBe(1);
    expect($now['open']['mine'])->toBeNull();
});

it('reports zero orders for an open round without items', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create(['menu' => foodNowMenu()]);

    $now = FoodNow::for($event, User::factory()->create());

    expect($now['open']['orderCount'])->toBe(0);
    expect($now['open']['mine'])->toBeNull();
});

it('picks the open round that closes soonest', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create([
        'title' => 'Spaet',
        'closes_at' => now()->addHours(5),
    ]);
    FoodOrder::factory()->for($event)->open()->create([
        'title' => 'Bald',
        'closes_at' => now()->addMinutes(20),
    ]);

    expect(FoodNow::for($event, User::factory()->create())['open']['title'])->toBe('Bald');
});

it('ignores expired, closed and draft rounds for the open slot', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create([
        'title' => 'Abgelaufen',
        'closes_at' => now()->subMinutes(5),
    ]);
    FoodOrder::factory()->for($event)->closed()->create(['title' => 'Geschlossen']);
    FoodOrder::factory()->for($event)->create(['title' => 'Entwurf', 'status' => FoodOrderStatus::Draft]);

    $now = FoodNow::for($event, User::factory()->create());

    expect($now['open'])->toBeNull();
    expect($now['next'])->toBeNull();
});

it('lists the next round that opens in the future', function () {
    $event = Event::factory()->registration()->create();
    $later = FoodOrder::factory()->for($event)->create([
        'title' => 'Spaeter',
        'status' => FoodOrderStatus::Open,
        'opens_at' => now()->addHours(3),
        'closes_at' => now()->addHours(4),
    ]);
    $sooner = FoodOrder::factory()->for($event)->create([
        'title' => 'Demnaechst',
        'status' => FoodOrderStatus::Open,
        'opens_at' => now()->addHour(),
        'closes_at' => now()->addHours(2),
    ]);

    $now = FoodNow::for($event, User::factory()->create());

    expect($now['open'])->toBeNull();
    expect($now['next'])->toBe([
        'title' => 'Demnaechst',
        'opensAt' => $sooner->opens_at->toIso8601String(),
    ]);
    expect($later->opens_at->toIso8601String())->not->toBe($now['next']['opensAt']);
});

it('keeps an open round and a future round separate', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create(['title' => 'Jetzt']);
    $future = FoodOrder::factory()->for($event)->create([
        'title' => 'Danach',
        'status' => FoodOrderStatus::Open,
        'opens_at' => now()->addHour(),
        'closes_at' => now()->addHours(2),
    ]);

    $now = FoodNow::for($event, User::factory()->create());

    expect($now['open']['title'])->toBe('Jetzt');
    expect($now['next'])->toMatchArray([
        'title' => 'Danach',
        'opensAt' => $future->opens_at->toIso8601String(),
    ]);
});

it('only considers rounds of the given event', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->open()->create(['title' => 'Fremd']);

    expect(FoodNow::for($event, User::factory()->create()))->toBe(['open' => null, 'next' => null]);
});
