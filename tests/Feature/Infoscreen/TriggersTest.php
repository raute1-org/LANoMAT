<?php

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Notifications\FoodOrderReady;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\TriggerCheckinOpen;
use App\Modules\Infoscreen\Actions\TriggerFoodReady;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Notifications\CheckinOpened;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('notifies exactly the order buyers with FoodOrderReady and pushes the beamer when a helper triggers "Essen ist da"', function () {
    Notification::fake();
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();

    $buyer1 = User::factory()->create();
    $buyer2 = User::factory()->create();
    $stranger = User::factory()->create();

    FoodOrderItem::factory()->for($order)->for($buyer1)->create();
    FoodOrderItem::factory()->for($order)->for($buyer2)->create();
    // Same buyer with a second item must not be notified twice.
    FoodOrderItem::factory()->for($order)->for($buyer1)->create();

    $helper = User::factory()->helper()->create();

    app(TriggerFoodReady::class)->handle($order, $helper);

    Notification::assertSentTo($buyer1, FoodOrderReady::class);
    Notification::assertSentTo($buyer2, FoodOrderReady::class);
    Notification::assertNotSentTo($stranger, FoodOrderReady::class);
    Notification::assertSentToTimes($buyer1, FoodOrderReady::class, 1);

    EventFacade::assertDispatched(SceneOverride::class, fn (SceneOverride $dispatched): bool => $dispatched->eventId === $event->id);
});

it('403s a participant triggering "Essen ist da"', function () {
    $event = Event::factory()->live()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $participant = User::factory()->create();

    expect(fn () => app(TriggerFoodReady::class)->handle($order, $participant))
        ->toThrow(AuthorizationException::class);
});

it('notifies confirmed registrants with CheckinOpened when a helper triggers "Check-in öffnet"', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();

    $confirmed1 = User::factory()->create();
    $confirmed2 = User::factory()->create();
    $pending = User::factory()->create();
    $cancelled = User::factory()->create();

    EventRegistration::factory()->for($event)->for($confirmed1)->create();
    EventRegistration::factory()->for($event)->for($confirmed2)->create();
    EventRegistration::factory()->for($event)->for($pending)->pending()->create();
    EventRegistration::factory()->for($event)->for($cancelled)->cancelled()->create();

    $helper = User::factory()->helper()->create();

    app(TriggerCheckinOpen::class)->handle($event, $helper);

    Notification::assertSentTo($confirmed1, CheckinOpened::class);
    Notification::assertSentTo($confirmed2, CheckinOpened::class);
    Notification::assertNotSentTo($pending, CheckinOpened::class);
    Notification::assertNotSentTo($cancelled, CheckinOpened::class);
});

it('403s a participant triggering "Check-in öffnet"', function () {
    $event = Event::factory()->live()->create();
    $participant = User::factory()->create();

    expect(fn () => app(TriggerCheckinOpen::class)->handle($event, $participant))
        ->toThrow(AuthorizationException::class);
});

it('404s the food-ready trigger route when the order in the URL belongs to a different event than the URL event', function () {
    Notification::fake();
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();
    $orderFromOtherEvent = FoodOrder::factory()->for($otherEvent)->open()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/triggers/food-ready/{$orderFromOtherEvent->id}")
        ->assertNotFound();

    Notification::assertNothingSent();
    EventFacade::assertNotDispatched(SceneOverride::class);
});

it('sends the food-ready trigger and pushes the beamer via the real route as a helper', function () {
    Notification::fake();
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();
    $buyer = User::factory()->create();
    FoodOrderItem::factory()->for($order)->for($buyer)->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/triggers/food-ready/{$order->id}")
        ->assertRedirect();

    Notification::assertSentTo($buyer, FoodOrderReady::class);
    EventFacade::assertDispatched(SceneOverride::class, fn (SceneOverride $dispatched): bool => $dispatched->eventId === $event->id);
});

it('403s the food-ready trigger route for a plain participant via the real route', function () {
    $event = Event::factory()->live()->create();
    $order = FoodOrder::factory()->for($event)->open()->create();

    $this->actingAs(User::factory()->create())
        ->post("/screen/{$event->slug}/control/triggers/food-ready/{$order->id}")
        ->assertForbidden();
});

it('sends the checkin-open trigger via the real route as a helper', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();
    $confirmed = User::factory()->create();
    EventRegistration::factory()->for($event)->for($confirmed)->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/triggers/checkin-open")
        ->assertRedirect();

    Notification::assertSentTo($confirmed, CheckinOpened::class);
});

it('403s the checkin-open trigger route for a plain participant via the real route', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())
        ->post("/screen/{$event->slug}/control/triggers/checkin-open")
        ->assertForbidden();
});
