<?php

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Notifications\FoodOrderReady;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\TriggerFoodReady;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function confirmedParticipant(Event $event): User
{
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    return $user;
}

function placeAsParticipant(User $user, FoodOrder $order, array $payload = []): TestResponse
{
    return test()->actingAs($user)
        ->from("/events/{$order->event->slug}/catering")
        ->post("/food-orders/{$order->id}/items", array_merge(['option_key' => 'pizza_margherita'], $payload));
}

// F-R2B-12: a NUL byte in the note reached PostgreSQL jsonb and produced a 500.

it('strips NUL bytes from the note instead of failing with a 500', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = confirmedParticipant($event);

    placeAsParticipant($user, $order, ['note' => "ohne\u{0}Zwiebeln"])->assertRedirect();

    $item = FoodOrderItem::where('food_order_id', $order->id)->where('user_id', $user->id)->sole();
    expect($item->selection['note'])->toBe('ohneZwiebeln');
});

it('rejects a note longer than 200 characters with a validation error', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = confirmedParticipant($event);

    placeAsParticipant($user, $order, ['note' => str_repeat('a', 201)])->assertSessionHasErrors('note');

    expect(FoodOrderItem::where('food_order_id', $order->id)->exists())->toBeFalse();
});

// Q-2 (product decision): ordering requires a confirmed event registration.

it('refuses an order from a user without any registration', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = User::factory()->create();

    placeAsParticipant($user, $order)->assertRedirect();

    expect(FoodOrderItem::where('food_order_id', $order->id)->exists())->toBeFalse();
});

it('refuses an order from a user with a pending registration', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->pending()->for($event)->for($user)->create();

    placeAsParticipant($user, $order)->assertRedirect();

    expect(FoodOrderItem::where('food_order_id', $order->id)->exists())->toBeFalse();
});

it('refuses an order from a user with a cancelled registration', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->cancelled()->for($event)->for($user)->create();

    placeAsParticipant($user, $order)->assertRedirect();

    expect(FoodOrderItem::where('food_order_id', $order->id)->exists())->toBeFalse();
});

it('shows the German registration hint as the refusal toast', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = User::factory()->create();

    placeAsParticipant($user, $order)->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Bestätige zuerst deine Anmeldung',
    ]);
});

it('does not expose the order form to a participant without a confirmed registration', function () {
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/events/{$event->slug}/catering")
        ->assertInertia(fn ($page) => $page
            ->where('registration.required', true)
            ->where('registration.url', route('events.register', $event->slug))
        );
});

// F-R2B-13: per-user limit of food items on open orders, enforced in the transaction.

it('refuses the eleventh item of one user on open orders with the default limit of 10', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = confirmedParticipant($event);
    FoodOrderItem::factory()->count(10)->for($order, 'foodOrder')->for($user)->create();

    placeAsParticipant($user, $order)->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Du hast das Limit von 10 Essens-Positionen erreicht.',
    ]);

    expect(FoodOrderItem::where('user_id', $user->id)->count())->toBe(10);
});

it('honours a configured item limit', function () {
    config(['lanomat.limits.food_items_per_user' => 2]);

    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $user = confirmedParticipant($event);

    placeAsParticipant($user, $order)->assertRedirect();
    placeAsParticipant($user, $order)->assertRedirect();
    placeAsParticipant($user, $order)->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Du hast das Limit von 2 Essens-Positionen erreicht.',
    ]);

    expect(FoodOrderItem::where('user_id', $user->id)->count())->toBe(2);
});

it('counts items across all open orders of the event, but not closed orders', function () {
    config(['lanomat.limits.food_items_per_user' => 2]);

    $event = Event::factory()->registration()->create();
    $openA = FoodOrder::factory()->for($event)->open()->create();
    $openB = FoodOrder::factory()->for($event)->open()->create();
    $closed = FoodOrder::factory()->for($event)->closed()->create();
    $user = confirmedParticipant($event);
    FoodOrderItem::factory()->for($closed, 'foodOrder')->for($user)->count(5)->create();
    FoodOrderItem::factory()->for($openA, 'foodOrder')->for($user)->create();

    // One item already on open order A, so only one more fits across both open orders.
    placeAsParticipant($user, $openB)->assertRedirect();
    placeAsParticipant($user, $openA)->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Du hast das Limit von 2 Essens-Positionen erreicht.',
    ]);
});

it('does not count another users items against the limit', function () {
    config(['lanomat.limits.food_items_per_user' => 1]);

    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $other = confirmedParticipant($event);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($other)->create();
    $user = confirmedParticipant($event);

    placeAsParticipant($user, $order)->assertRedirect();

    expect(FoodOrderItem::where('user_id', $user->id)->count())->toBe(1);
});

// Q-2 notifications: only buyers with a confirmed registration get the "Essen ist da" message.

it('sends the food-ready bell and Discord message only to buyers with a confirmed registration', function () {
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $helper = User::factory()->helper()->create();

    $confirmed = confirmedParticipant($event);
    $pending = User::factory()->create();
    EventRegistration::factory()->pending()->for($event)->for($pending)->create();
    $cancelled = User::factory()->create();
    EventRegistration::factory()->cancelled()->for($event)->for($cancelled)->create();

    foreach ([$confirmed, $pending, $cancelled] as $buyer) {
        FoodOrderItem::factory()->for($order, 'foodOrder')->for($buyer)->create();
    }

    // Never reach the real Discord API from a test (the Discord DM channel is queued).
    fakeDiscord();

    app(TriggerFoodReady::class)->handle($order, $helper);

    $notified = DB::table('notifications')
        ->where('type', FoodOrderReady::class)
        ->pluck('notifiable_id')
        ->all();

    expect($notified)->toBe([$confirmed->id]);
});
