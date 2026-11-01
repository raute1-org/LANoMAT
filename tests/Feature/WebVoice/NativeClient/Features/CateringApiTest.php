<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    $this->user = User::factory()->create();
    $this->headers = nativeBearer($this->user);
    $this->event = Event::factory()->live()->create();
    EventRegistration::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->user->id]);
    $this->order = FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'title' => 'Pizza']);
});

/** @param  array<string, mixed>  $body */
function nativePlace(object $test, FoodOrder $order, array $body = ['option_key' => 'pizza_margherita'], ?array $headers = null)
{
    return $test->postJson("/api/voice-client/v1/food-orders/{$order->id}/items", $body, $headers ?? $test->headers);
}

function nativeItem(FoodOrder $order, User $user, int $cents, bool $paid = false): FoodOrderItem
{
    $item = new FoodOrderItem(['food_order_id' => $order->id, 'price_cents' => $cents, 'selection' => ['option_key' => 'salad', 'note' => null]]);
    $item->forceFill(['user_id' => $user->id, 'paid_at' => $paid ? now() : null])->save();

    return $item;
}

it('shows menu, window state, limits and only the own items in cents', function () {
    nativePlace($this, $this->order, ['option_key' => 'pizza_margherita', 'note' => 'extra cheese'])->assertCreated();
    $stranger = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $this->event->id, 'user_id' => $stranger->id]);
    app(PlaceFoodOrderItem::class)->handle($this->order, $stranger, 'pizza_margherita');
    FoodOrder::factory()->create(['event_id' => $this->event->id]); // draft: not listed

    $response = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/catering", $this->headers)->assertOk();

    $order = $response->json('orders.0');
    expect($response->json('orders'))->toHaveCount(1)
        ->and($response->json('registration_required'))->toBeFalse()
        ->and($response->json('limits'))->toBe(['items_per_user' => 10, 'my_open_items' => 1])
        ->and($order)->toMatchArray(['id' => $this->order->id, 'title' => 'Pizza', 'status' => 'open', 'is_open' => true, 'my_total_cents' => 850])
        ->and($order['menu'][0])->toBe(['key' => 'pizza_margherita', 'name' => 'Pizza Margherita', 'price_cents' => 850])
        ->and($order['my_items'])->toHaveCount(1)
        ->and($order['my_items'][0])->toMatchArray(['option_key' => 'pizza_margherita', 'option_name' => 'Pizza Margherita', 'note' => 'extra cheese', 'price_cents' => 850, 'paid' => false]);
});

it('tells an unregistered user that registration is required and refuses ordering', function () {
    $headers = nativeBearer(User::factory()->create());

    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/catering", $headers)
        ->assertOk()->assertJsonPath('registration_required', true);

    nativePlace($this, $this->order, ['option_key' => 'pizza_margherita'], $headers)
        ->assertStatus(422)->assertJsonPath('code', 'registration_required');
    expect(FoodOrderItem::query()->count())->toBe(0);
});

it('does not count a pending or cancelled registration as registered', function (string $state) {
    $user = User::factory()->create();
    EventRegistration::factory()->{$state}()->create(['event_id' => $this->event->id, 'user_id' => $user->id]);

    nativePlace($this, $this->order, ['option_key' => 'pizza_margherita'], nativeBearer($user))
        ->assertStatus(422)->assertJsonPath('code', 'registration_required');
})->with(['pending', 'cancelled']);

it('places an order with the price from the menu, never from the client', function () {
    $response = nativePlace($this, $this->order, ['option_key' => 'pizza_salami', 'price_cents' => 1])->assertCreated();

    expect($response->json())->toMatchArray(['option_key' => 'pizza_salami', 'price_cents' => 950, 'paid' => false]);
    $item = FoodOrderItem::query()->firstOrFail();
    expect($item->price_cents)->toBe(950)->and($item->user_id)->toBe($this->user->id)->and($item->paid_at)->toBeNull();
});

it('refuses an unknown option, a too long note and a missing key', function () {
    nativePlace($this, $this->order, ['option_key' => 'caviar'])->assertStatus(422)->assertJsonPath('code', 'unknown_option');
    nativePlace($this, $this->order, ['option_key' => 'salad', 'note' => str_repeat('x', 201)])->assertStatus(422)->assertJsonValidationErrors('note');
    nativePlace($this, $this->order, [])->assertStatus(422)->assertJsonValidationErrors('option_key');
    expect(FoodOrderItem::query()->count())->toBe(0);
});

it('refuses ordering outside the window and on closed or draft orders', function (string $state) {
    $order = match ($state) {
        'closed' => FoodOrder::factory()->closed()->create(['event_id' => $this->event->id]),
        'draft' => FoodOrder::factory()->create(['event_id' => $this->event->id]),
        'not yet open' => FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'opens_at' => now()->addMinute()]),
        'expired' => FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'closes_at' => now()->subSecond()]),
    };

    nativePlace($this, $order, ['option_key' => 'salad'])->assertStatus(422)->assertJsonPath('code', 'not_open');
    expect(FoodOrderItem::query()->count())->toBe(0);
})->with(['closed', 'draft', 'not yet open', 'expired']);

it('enforces the per-user item limit across the open orders of the event', function () {
    config(['lanomat.limits.food_items_per_user' => 2]);
    $second = FoodOrder::factory()->open()->create(['event_id' => $this->event->id]);

    nativePlace($this, $this->order)->assertCreated();
    nativePlace($this, $second)->assertCreated();
    nativePlace($this, $this->order)->assertStatus(422)->assertJsonPath('code', 'item_limit');

    expect(FoodOrderItem::query()->where('user_id', $this->user->id)->count())->toBe(2);
    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/catering", $this->headers)
        ->assertJsonPath('limits', ['items_per_user' => 2, 'my_open_items' => 2]);
});

it('lists my items across orders with order status, can_cancel and integer cent totals', function () {
    $closed = FoodOrder::factory()->closed()->create(['event_id' => $this->event->id, 'title' => 'Frühstück']);
    nativeItem($closed, $this->user, 450, paid: true);
    nativePlace($this, $this->order, ['option_key' => 'pizza_margherita'])->assertCreated();
    nativePlace($this, $this->order, ['option_key' => 'pizza_salami'])->assertCreated();
    nativeItem($this->order, User::factory()->create(), 450);

    $response = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/catering/mine", $this->headers)->assertOk();

    expect($response->json('total_cents'))->toBe(450 + 850 + 950)
        ->and($response->json('paid_cents'))->toBe(450)
        ->and($response->json('items'))->toHaveCount(3);
    $byOrder = collect($response->json('items'))->groupBy('order_title');
    expect($byOrder['Frühstück'][0])->toMatchArray(['order_status' => 'closed', 'order_is_open' => false, 'can_cancel' => false, 'paid' => true, 'price_cents' => 450])
        ->and($byOrder['Pizza'][0])->toMatchArray(['order_status' => 'open', 'order_is_open' => true, 'can_cancel' => true]);
});

it('cancels the own item while the order is open and answers 204', function () {
    $id = nativePlace($this, $this->order)->json('id');

    $this->deleteJson("/api/voice-client/v1/food-order-items/{$id}", [], $this->headers)->assertNoContent();

    expect(FoodOrderItem::query()->count())->toBe(0);
});

it('refuses to cancel once the order window is closed', function () {
    $id = nativePlace($this, $this->order)->json('id');
    $this->order->forceFill(['closes_at' => now()->subSecond()])->save();

    $this->deleteJson("/api/voice-client/v1/food-order-items/{$id}", [], $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'not_open');
    expect(FoodOrderItem::query()->count())->toBe(1);
});

it('refuses to cancel someone else\'s item but lets orga do it, like the browser', function () {
    $id = nativePlace($this, $this->order)->json('id');

    $this->deleteJson("/api/voice-client/v1/food-order-items/{$id}", [], nativeBearer(User::factory()->create()))->assertForbidden();
    expect(FoodOrderItem::query()->count())->toBe(1);

    $this->deleteJson("/api/voice-client/v1/food-order-items/{$id}", [], nativeBearer(User::factory()->orga()->create()))->assertNoContent();
});

it('hides catering of a draft event', function () {
    $draft = Event::factory()->draft()->create();
    $order = FoodOrder::factory()->open()->create(['event_id' => $draft->id]);
    $item = nativeItem($order, $this->user, 450);

    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/catering", $this->headers)->assertNotFound();
    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/catering/mine", $this->headers)->assertNotFound();
    nativePlace($this, $order, ['option_key' => 'salad'])->assertNotFound();
    $this->deleteJson("/api/voice-client/v1/food-order-items/{$item->id}", [], $this->headers)->assertNotFound();
    expect(FoodOrderItem::query()->count())->toBe(1);
});
