<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the catering page with german labels for a public event', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/catering")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catering/Show')
            ->where('labels.title', 'Essensbestellung')
        );
});

it('returns 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();
    FoodOrder::factory()->for($event)->open()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/catering")->assertNotFound();
});

it('hides a draft food order from the public catering page', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->create(['title' => 'Geheime Draft-Bestellung']);
    $openOrder = FoodOrder::factory()->for($event)->open()->create(['title' => 'Pizza-Bestellung']);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/catering")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catering/Show')
            ->has('orders', 1)
            ->where('orders.0.id', $openOrder->id)
            ->where('orders.0.title', 'Pizza-Bestellung')
        );
});

it('places an item for the authenticated user at the menu price within the window', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create([
        'menu' => [
            new MenuOption(key: 'pizza_margherita', name: 'Pizza Margherita', priceCents: 850),
        ],
    ]);
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->post("/food-orders/{$order->id}/items", [
            'option_key' => 'pizza_margherita',
            // A price is deliberately never accepted by the endpoint; even if
            // sent, it must be ignored in favour of the menu's authoritative
            // price. Assert below that the persisted price is 850, not this.
            'price_cents' => 1,
        ])
        ->assertRedirect();

    $item = FoodOrderItem::where('food_order_id', $order->id)->where('user_id', $user->id)->first();

    expect($item)->not->toBeNull();
    expect($item->price_cents)->toBe(850);
    expect($item->selection['option_key'])->toBe('pizza_margherita');
});

it('redirects back with a german error when the order is closed', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->closed()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from("/events/{$event->slug}/catering")
        ->post("/food-orders/{$order->id}/items", [
            'option_key' => 'pizza_margherita',
        ]);

    $response->assertRedirect("/events/{$event->slug}/catering");
    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => __('catering.errors.not_open'),
    ]);
    expect(FoodOrderItem::where('food_order_id', $order->id)->exists())->toBeFalse();
});

it('cancels the users own item within the window', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = User::factory()->create();
    $item = FoodOrderItem::factory()->for($order, 'foodOrder')->for($user)->create();

    $this->actingAs($user)
        ->delete("/food-order-items/{$item->id}")
        ->assertRedirect();

    expect(FoodOrderItem::find($item->id))->toBeNull();
});

it('forbids cancelling another users item', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $owner = User::factory()->create();
    $item = FoodOrderItem::factory()->for($order, 'foodOrder')->for($owner)->create();

    $this->actingAs(User::factory()->create())
        ->delete("/food-order-items/{$item->id}")
        ->assertForbidden();

    expect(FoodOrderItem::find($item->id))->not->toBeNull();
});

function payRound(Event $event, array $attrs = []): FoodOrder
{
    return FoodOrder::factory()->for($event)->open()->create(array_merge([
        'menu' => [new MenuOption(key: 'pizza', name: 'Pizza', priceCents: 950)],
    ], $attrs));
}

function payItem(FoodOrder $order, User $user, array $attrs = []): FoodOrderItem
{
    return FoodOrderItem::factory()->for($order, 'foodOrder')->for($user)->create(array_merge(
        ['selection' => ['option_key' => 'pizza'], 'price_cents' => 950], $attrs));
}

function myPayment(User $user, Event $event, FoodOrder $order): mixed
{
    $res = test()->actingAs($user)->get("/events/{$event->slug}/catering")->assertOk();

    return collect($res->inertiaProps('orders'))->firstWhere('id', $order->id)['myPayment'];
}

it('has no payment block without own items', function () {
    $event = Event::factory()->registration()->create();
    $order = payRound($event);
    payItem($order, User::factory()->create());

    expect(myPayment(User::factory()->create(), $event, $order))->toBeNull();
});

it('marks an included viewer as included with nothing due', function () {
    $event = Event::factory()->registration()->create();
    $order = payRound($event, ['included_ticket_types' => ['standard']]);
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create(['ticket_type' => 'standard']);
    payItem($order, $user, ['included' => true]);

    expect(myPayment($user, $event, $order))->toMatchArray(['method' => 'included', 'dueCents' => 0, 'url' => null]);
});

it('shows cash due as own unpaid sum only', function () {
    $event = Event::factory()->registration()->create();
    $order = payRound($event, ['payment_method' => 'cash']);
    $user = User::factory()->create();
    payItem($order, $user);
    payItem($order, $user, ['paid_at' => now()]);
    payItem($order, User::factory()->create());

    expect(myPayment($user, $event, $order))->toMatchArray(['method' => 'cash', 'dueCents' => 950, 'url' => null]);
});

it('builds a paypal url from the round link, falls back to the event link, else null', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $own = payRound($event, ['payment_method' => 'paypal', 'payment_link' => 'https://paypal.me/example-orga']);
    payItem($own, $user);
    expect(myPayment($user, $event, $own))->toMatchArray(['method' => 'paypal', 'url' => 'https://paypal.me/example-orga/9.5EUR']);

    $fallback = payRound($event, ['payment_method' => 'paypal', 'payment_link' => null]);
    payItem($fallback, $user);
    expect(myPayment($user, $event, $fallback)['url'])->toBeNull();

    $settings = EventPaymentSettings::forEvent($event);
    $settings->payment_link = 'https://www.paypal.com/pool/example';
    $settings->save();
    expect(myPayment($user, $event, $fallback)['url'])->toBe('https://www.paypal.com/pool/example');
});

it('ignores a stored round link when the round is cash', function () {
    $event = Event::factory()->registration()->create();
    $order = payRound($event, ['payment_method' => 'cash', 'payment_link' => 'https://paypal.me/example-orga']);
    $user = User::factory()->create();
    payItem($order, $user);

    expect(myPayment($user, $event, $order)['url'])->toBeNull();
});

it('renders a round with 154 dishes and passes every dish plus the new page labels', function () {
    $event = Event::factory()->registration()->create();
    $menu = [];
    foreach (range(1, 154) as $i) {
        $menu[] = new MenuOption(key: "dish_{$i}", name: 'Kategorie '.intdiv($i, 20).": Gericht {$i}", priceCents: 500 + $i);
    }
    FoodOrder::factory()->for($event)->open()->create(['menu' => $menu]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/catering")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catering/Show')
            ->has('orders.0.menu', 154)
            ->where('orders.0.menu.0.name', 'Kategorie 0: Gericht 1')
            ->where('labels.receipt_empty', 'Noch nichts bestellt. Tippe bei einem Gericht auf +.')
            ->has('labels.search_placeholder')
            ->has('labels.add_aria')
            ->has('labels.remove_aria')
            ->has('labels.cart_bar_open')
        );
});
