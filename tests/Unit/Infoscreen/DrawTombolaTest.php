<?php

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Exceptions\InfoscreenException;
use App\Modules\Infoscreen\Models\TombolaDraw;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('draws only from checked-in registrations', function () {
    $event = Event::factory()->live()->create();
    $prize = TombolaPrize::factory()->for($event)->create();

    $checkedIn = EventRegistration::factory()->for($event)->checkedIn()->create();
    EventRegistration::factory()->for($event)->create(); // not checked in

    $draw = app(DrawTombola::class)->handle($event, $prize);

    expect($draw)->toBeInstanceOf(TombolaDraw::class)
        ->and($draw->registration_id)->toBe($checkedIn->id)
        ->and($draw->tombola_prize_id)->toBe($prize->id)
        ->and($draw->event_id)->toBe($event->id)
        ->and($draw->drawn_at)->not->toBeNull();
});

it('never repeats a winner within an event across multiple draws', function () {
    $event = Event::factory()->live()->create();
    $prizeA = TombolaPrize::factory()->for($event)->create();
    $prizeB = TombolaPrize::factory()->for($event)->create();

    $first = EventRegistration::factory()->for($event)->checkedIn()->create();
    $second = EventRegistration::factory()->for($event)->checkedIn()->create();

    $drawA = app(DrawTombola::class)->handle($event, $prizeA);
    $drawB = app(DrawTombola::class)->handle($event, $prizeB);

    expect($drawA->registration_id)->not->toBe($drawB->registration_id)
        ->and([$drawA->registration_id, $drawB->registration_id])
        ->toEqualCanonicalizing([$first->id, $second->id]);
});

it('throws noEligibleEntrants when the pool is empty', function () {
    $event = Event::factory()->live()->create();
    $prize = TombolaPrize::factory()->for($event)->create();

    // Not checked in: ineligible.
    EventRegistration::factory()->for($event)->create();

    expect(fn () => app(DrawTombola::class)->handle($event, $prize))
        ->toThrow(InfoscreenException::class);
});

it('throws noEligibleEntrants when every checked-in registration has already been drawn', function () {
    $event = Event::factory()->live()->create();
    $prizeA = TombolaPrize::factory()->for($event)->create();
    $prizeB = TombolaPrize::factory()->for($event)->create();

    EventRegistration::factory()->for($event)->checkedIn()->create();

    app(DrawTombola::class)->handle($event, $prizeA);

    expect(fn () => app(DrawTombola::class)->handle($event, $prizeB))
        ->toThrow(InfoscreenException::class);
});

it('does not draw from another event\'s checked-in registrations', function () {
    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();
    $prize = TombolaPrize::factory()->for($event)->create();

    $ownRegistration = EventRegistration::factory()->for($event)->checkedIn()->create();
    EventRegistration::factory()->for($otherEvent)->checkedIn()->create();

    $draw = app(DrawTombola::class)->handle($event, $prize);

    expect($draw->registration_id)->toBe($ownRegistration->id);
});

it('has registration_id and drawn_at non-fillable, set only by the Action', function () {
    $draw = new TombolaDraw;

    expect($draw->getFillable())->toBe(['event_id', 'tombola_prize_id']);
});

it('dispatches a SceneOverride carrying the prize and winner', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $prize = TombolaPrize::factory()->for($event)->create(['title' => 'Grafikkarte']);
    $registration = EventRegistration::factory()->for($event)->checkedIn()->create();

    app(DrawTombola::class)->handle($event, $prize);

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event, $prize, $registration): bool {
        return $dispatched->eventId === $event->id
            && $dispatched->scene['type'] === 'tombola'
            && $dispatched->scene['data']['prize']['title'] === $prize->title
            && $dispatched->scene['data']['winner']['registrationId'] === $registration->id;
    });
});
