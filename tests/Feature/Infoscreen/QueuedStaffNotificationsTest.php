<?php

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Notifications\FoodOrderReady;
use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Notifications\OrgaPinged;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Notifications\CheckinOpened;
use App\Modules\Tournaments\Notifications\MatchReadyBell;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * F-S5-1: staff/participant notifications with a Discord mirror are queued, so a
 * Discord outage cannot turn the HTTP action into a 500 or stop the remaining
 * recipients (one job per recipient and channel).
 */

it('queues every notification that mirrors to Discord', function (string $class) {
    expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue();
})->with([OrgaPinged::class, CheckinOpened::class, FoodOrderReady::class, MatchReadyBell::class]);

it('does not run delivery inside the request when orga is pinged', function () {
    Queue::fake();

    $event = Event::factory()->live()->create();
    $caller = User::factory()->create();
    EventRegistration::factory()->for($event)->for($caller)->create();
    User::factory()->helper()->create();
    User::factory()->orga()->create();

    $this->actingAs($caller)->post(route('events.ping-orga', $event), [])->assertRedirect();

    // Two recipients x two channels (database + Discord), each its own job.
    Queue::assertPushed(SendQueuedNotifications::class, 4);
});

it('does not run delivery inside the request for check-in-open and food-ready', function () {
    Queue::fake();

    $event = Event::factory()->live()->create();
    $helper = User::factory()->helper()->create();
    EventRegistration::factory()->for($event)->count(3)->create(['status' => RegistrationStatus::Confirmed]);
    $order = FoodOrder::factory()->for($event)->open()->create();
    $buyer = User::factory()->create();
    EventRegistration::factory()->for($event)->for($buyer)->create();
    FoodOrderItem::factory()->for($order)->for($buyer)->create();

    $this->actingAs($helper)->post(route('screen.control.trigger.checkin-open', $event))->assertRedirect();
    $this->actingAs($helper)->post(route('screen.control.trigger.food-ready', [$event, $order]))->assertRedirect();

    // Check-in opens for all four confirmed registrations (three plus the buyer), two channels each,
    // plus the food-ready message for the one confirmed buyer, two channels.
    Queue::assertPushed(SendQueuedNotifications::class, 4 * 2 + 1 * 2);
});

it('still writes the bell for every recipient when the Discord channel is dead', function () {
    config(['queue.default' => 'sync']);
    app()->bind(DiscordChannel::class, fn () => new class
    {
        public function send(): void
        {
            throw new RuntimeException('discord down');
        }
    });

    $recipients = User::factory()->count(3)->helper()->create();

    foreach ($recipients as $user) {
        try {
            $user->notify(new OrgaPinged(User::factory()->create(), null, null));
        } catch (RuntimeException) {
            // sync driver rethrows the Discord job; the database job ran first and independently
        }
    }

    expect(DB::table('notifications')->count())->toBe(3);
});
