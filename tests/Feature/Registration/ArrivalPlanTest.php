<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Actions\UpdateArrivalPlan;
use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\EventDays;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function arrivalEvent(array $attrs = []): Event
{
    return Event::factory()->registration()->create([
        'starts_at' => '2026-11-20 16:00:00',
        'ends_at' => '2026-11-22 12:00:00',
        ...$attrs,
    ]);
}

it('knows the calendar days of the event', function () {
    expect(EventDays::dates(arrivalEvent()))->toBe(['2026-11-20', '2026-11-21', '2026-11-22'])
        ->and(EventDays::dates(arrivalEvent(['ends_at' => '2026-11-21 00:00:00'])))->toBe(['2026-11-20'])
        ->and(EventDays::dates(arrivalEvent(['starts_at' => '2026-11-20 22:00:00', 'ends_at' => '2026-11-21 03:00:00'])))
        ->toBe(['2026-11-20', '2026-11-21'])
        ->and(EventDays::dates(arrivalEvent(['ends_at' => '2026-11-20 20:00:00'])))->toBe(['2026-11-20'])
        ->and(EventDays::options(arrivalEvent())[0])->toBe(['value' => '2026-11-20', 'label' => 'Fr, 20.11.']);
});

it('has one ordered, labelled window set', function () {
    expect(array_map(fn ($w) => $w->label(), TimeWindow::ordered()))
        ->toBe(['bis 12 Uhr', '12–18 Uhr', '18–22 Uhr', 'nach 22 Uhr']);
});

it('registers without a plan (optional)', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])->assertSessionHasNoErrors();

    $r = EventRegistration::firstWhere('user_id', $user->id);
    expect($r->arrival_day)->toBeNull()->and($r->departure_window)->toBeNull();
});

it('registers with a plan', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/register", [
        'ticket_type' => 'standard',
        'arrival_day' => '2026-11-20', 'arrival_window' => 'evening',
        'departure_day' => '2026-11-22', 'departure_window' => 'morning',
    ])->assertSessionHasNoErrors();

    $r = EventRegistration::firstWhere('user_id', $user->id);
    expect($r->arrival_day->toDateString())->toBe('2026-11-20')
        ->and($r->arrival_window)->toBe(TimeWindow::Evening)
        ->and($r->departure_window)->toBe(TimeWindow::Morning);
});

it('validates plan fields on registration', function (array $data, string $errorField) {
    $event = arrivalEvent();

    $this->actingAs(User::factory()->create())
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard', ...$data])
        ->assertSessionHasErrors($errorField);
})->with([
    'day not an event day' => [['arrival_day' => '2026-11-25', 'arrival_window' => 'evening'], 'arrival_day'],
    'day before event' => [['arrival_day' => '2026-11-19', 'arrival_window' => 'evening'], 'arrival_day'],
    'window missing' => [['arrival_day' => '2026-11-20'], 'arrival_window'],
    'day missing' => [['departure_window' => 'night'], 'departure_day'],
    'bad window' => [['arrival_day' => '2026-11-20', 'arrival_window' => 'noon'], 'arrival_window'],
    'arrival after departure (days)' => [['arrival_day' => '2026-11-21', 'arrival_window' => 'morning', 'departure_day' => '2026-11-20', 'departure_window' => 'night'], 'departure_day'],
    'arrival after departure (windows)' => [['arrival_day' => '2026-11-21', 'arrival_window' => 'evening', 'departure_day' => '2026-11-21', 'departure_window' => 'afternoon'], 'departure_day'],
]);

it('allows arrival and departure in the same window and the last day of a multi-day event', function () {
    $event = arrivalEvent();

    $this->actingAs(User::factory()->create())
        ->post("/events/{$event->slug}/register", [
            'ticket_type' => 'standard',
            'arrival_day' => '2026-11-22', 'arrival_window' => 'morning',
            'departure_day' => '2026-11-22', 'departure_window' => 'morning',
        ])->assertSessionHasNoErrors();
});

it('updates the own plan and can clear it', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();
    $r = EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->patch("/events/{$event->slug}/register/arrival", [
        'arrival_day' => '2026-11-21', 'arrival_window' => 'afternoon',
    ])->assertSessionHasNoErrors();
    expect($r->fresh()->arrival_window)->toBe(TimeWindow::Afternoon);

    $this->actingAs($user)->patch("/events/{$event->slug}/register/arrival", [])->assertSessionHasNoErrors();
    expect($r->fresh()->arrival_day)->toBeNull();
});

it('rejects invalid updates', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->patch("/events/{$event->slug}/register/arrival", [
        'arrival_day' => '2027-01-01', 'arrival_window' => 'afternoon',
    ])->assertSessionHasErrors('arrival_day');
});

it('does not let anyone change another user\'s plan and ignores client user ids', function () {
    $event = arrivalEvent();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerReg = EventRegistration::factory()->for($event)->for($owner)->create();

    // $other has no registration -> 404; payload ids are ignored anyway.
    $this->actingAs($other)->patch("/events/{$event->slug}/register/arrival", [
        'arrival_day' => '2026-11-20', 'arrival_window' => 'evening', 'user_id' => $owner->id, 'registration_id' => $ownerReg->id,
    ])->assertNotFound();

    expect($ownerReg->fresh()->arrival_day)->toBeNull();
});

it('enforces the update policy: own, not cancelled, not after the event', function () {
    $user = User::factory()->create();
    $mine = EventRegistration::factory()->for(arrivalEvent())->for($user)->create();
    $cancelled = EventRegistration::factory()->cancelled()->for(arrivalEvent())->for($user)->create();
    $theirs = EventRegistration::factory()->for(arrivalEvent())->create();
    $finished = EventRegistration::factory()->for(Event::factory()->finished()->create())->for($user)->create();
    $archived = EventRegistration::factory()->for(Event::factory()->archived()->create())->for($user)->create();
    $live = EventRegistration::factory()->for(Event::factory()->live()->create())->for($user)->create();

    expect($user->can('updateArrivalPlan', $mine))->toBeTrue()
        ->and($user->can('updateArrivalPlan', $live))->toBeTrue()
        ->and($user->can('updateArrivalPlan', $cancelled))->toBeFalse()
        ->and($user->can('updateArrivalPlan', $theirs))->toBeFalse()
        ->and($user->can('updateArrivalPlan', $finished))->toBeFalse()
        ->and($user->can('updateArrivalPlan', $archived))->toBeFalse();
});

it('forbids updates after the event is finished', function () {
    $event = Event::factory()->finished()->create(['starts_at' => '2026-11-20 16:00:00', 'ends_at' => '2026-11-21 12:00:00']);
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->patch("/events/{$event->slug}/register/arrival", [
        'arrival_day' => '2026-11-20', 'arrival_window' => 'evening',
    ])->assertForbidden();
});

it('guards the action against invalid plans', function () {
    $event = arrivalEvent();
    $r = EventRegistration::factory()->for($event)->create();

    expect(fn () => app(UpdateArrivalPlan::class)->handle($r, new ArrivalPlan('2030-01-01', TimeWindow::Night)))
        ->toThrow(RegistrationException::class);
    expect(fn () => app(RegisterForEvent::class)->handle($event, User::factory()->create(), 'standard', new ArrivalPlan('2026-11-22', TimeWindow::Night, '2026-11-20', TimeWindow::Night)))
        ->toThrow(RegistrationException::class);
});

it('resets the plan when a cancelled registration is reactivated', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();
    EventRegistration::factory()->cancelled()->for($event)->for($user)->create([
        'arrival_day' => '2026-11-20', 'arrival_window' => 'evening',
    ]);

    $r = app(RegisterForEvent::class)->handle($event, $user, 'standard');

    expect($r->fresh()->arrival_day)->toBeNull();
});

it('exposes plan data on the register page props', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create([
        'arrival_day' => '2026-11-21', 'arrival_window' => 'afternoon',
    ]);

    $this->actingAs($user)->get("/events/{$event->slug}/register")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('registration.arrival.arrivalLabel', 'Sa, 21.11., 12–18 Uhr')
        ->where('registration.arrival.departureLabel', null)
        ->where('registration.arrival.editable', true)
        ->has('arrivalOptions.days', 3)
        ->has('arrivalOptions.windows', 4)
    );
});

it('exposes the plan summary on the dashboard registration', function () {
    $event = arrivalEvent();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('registration.arrival.arrivalLabel', null)
        ->where('registration.arrival.editable', true)
    );
});
