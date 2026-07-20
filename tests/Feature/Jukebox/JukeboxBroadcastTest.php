<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Events\JukeboxUpdated;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('broadcasts on event.{id} as jukebox.updated with no private payload', function () {
    $event = new JukeboxUpdated(123);

    expect($event->broadcastOn()->name)->toBe('event.123')
        ->and($event->broadcastAs())->toBe('jukebox.updated')
        ->and($event->broadcastWith())->toBe([]);
});

/**
 * @return array{0: Event, 1: User}
 */
function checkedInJukeboxBroadcastUser(): array
{
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->checkedIn()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    return [$event, $user];
}

it('dispatches JukeboxUpdated for the event after adding a track', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    [$event, $user] = checkedInJukeboxBroadcastUser();

    $this->actingAs($user)->post("/events/{$event->slug}/jukebox", [
        'uri' => 'ma://track/1',
        'title' => 'New Track',
    ]);

    EventFacade::assertDispatched(JukeboxUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches JukeboxUpdated for the event after voting', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    [$event, $user] = checkedInJukeboxBroadcastUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    $this->actingAs($user)->post("/jukebox/{$item->id}/vote");

    EventFacade::assertDispatched(JukeboxUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches JukeboxUpdated for the event after a skip vote', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    [$event, $user] = checkedInJukeboxBroadcastUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    $this->actingAs($user)->post("/jukebox/{$item->id}/skip-vote");

    EventFacade::assertDispatched(JukeboxUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches JukeboxUpdated for the event after a moderator skip', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $event = Event::factory()->live()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);
    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    $this->actingAs($helper)->post("/events/{$event->slug}/jukebox/skip");

    EventFacade::assertDispatched(JukeboxUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches JukeboxUpdated for the event after a moderator removes an item', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $event = Event::factory()->live()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    $this->actingAs($helper)->delete("/jukebox/{$item->id}");

    EventFacade::assertDispatched(JukeboxUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('does not dispatch JukeboxUpdated when a mutation is refused by policy', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/jukebox", [
        'uri' => 'ma://track/1',
        'title' => 'New Track',
    ]);

    EventFacade::assertNotDispatched(JukeboxUpdated::class);
});
