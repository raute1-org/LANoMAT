<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the catering page with german labels for a public event', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create();

    $this->get("/events/{$event->slug}/catering")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catering/Show')
            ->where('labels.title', 'Essensbestellung')
        );
});

it('returns 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();
    FoodOrder::factory()->for($event)->open()->create();

    $this->get("/events/{$event->slug}/catering")->assertNotFound();
});

it('places an item for the authenticated user at the menu price within the window', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create([
        'menu' => [
            new MenuOption(key: 'pizza_margherita', name: 'Pizza Margherita', priceCents: 850),
        ],
    ]);
    $user = User::factory()->create();

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
