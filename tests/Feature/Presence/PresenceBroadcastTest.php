<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event as EventModel;
use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Presence\Listeners\BroadcastPresenceOnTournamentActivity;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\MatchWentLive;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts on event.{id} as presence.updated with no private payload', function () {
    $event = new PresenceUpdated(123);

    expect($event->broadcastOn()->name)->toBe('event.123')
        ->and($event->broadcastAs())->toBe('presence.updated')
        ->and($event->broadcastWith())->toBe([]);
});

it('dispatches PresenceUpdated for the registration\'s event when checking in', function () {
    Event::fake([PresenceUpdated::class]);

    $event = EventModel::factory()->live()->create();
    $registration = EventRegistration::factory()->for($event)->create();

    (new CheckInRegistration)->handle($event, $registration->qr_token);

    Event::assertDispatched(PresenceUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches PresenceUpdated for the owning event when MatchReady fires', function () {
    Event::fake([PresenceUpdated::class]);

    $event = EventModel::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    (new BroadcastPresenceOnTournamentActivity)->handle(new MatchReady($match));

    Event::assertDispatched(PresenceUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches PresenceUpdated for the owning event when MatchWentLive fires', function () {
    Event::fake([PresenceUpdated::class]);

    $event = EventModel::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    (new BroadcastPresenceOnTournamentActivity)->handle(new MatchWentLive($match));

    Event::assertDispatched(PresenceUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches PresenceUpdated for the owning event when MatchCompleted fires', function () {
    Event::fake([PresenceUpdated::class]);

    $event = EventModel::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    (new BroadcastPresenceOnTournamentActivity)->handle(new MatchCompleted($match));

    Event::assertDispatched(PresenceUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});

it('dispatches PresenceUpdated for the owning event when TournamentStarted fires', function () {
    Event::fake([PresenceUpdated::class]);

    $event = EventModel::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();

    (new BroadcastPresenceOnTournamentActivity)->handle(new TournamentStarted($tournament));

    Event::assertDispatched(PresenceUpdated::class, fn ($dispatched) => $dispatched->eventId === $event->id);
});
