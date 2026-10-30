<?php

use App\Models\User;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Filament\RelationManagers\RegistrationsRelationManager;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets a participant cancel their own not-yet-checked-in registration before the event ends', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $registration = EventRegistration::factory()->for($event)->for($user)->create();

    expect($user->can('cancel', $registration))->toBeTrue();
});

it('blocks self-cancel after check-in and for finished/archived events, but not for orga', function () {
    $user = User::factory()->create();
    $orga = User::factory()->orga()->create();

    $checkedIn = EventRegistration::factory()->for(Event::factory()->live())->for($user)->checkedIn()->create();
    expect($user->can('cancel', $checkedIn))->toBeFalse()->and($orga->can('cancel', $checkedIn))->toBeTrue();

    foreach (['finished', 'archived'] as $state) {
        $registration = EventRegistration::factory()->for(Event::factory()->{$state}())->for($user)->create();

        expect($user->can('cancel', $registration))->toBeFalse()->and($orga->can('cancel', $registration))->toBeTrue();
    }
});

it('keeps a checked-in registration when the participant tries to cancel it over HTTP', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $registration = EventRegistration::factory()->for($event)->for($user)->checkedIn()->create();

    $this->actingAs($user)->delete("/events/{$event->slug}/register")->assertRedirect();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Confirmed);
});

it('exposes canCancel and the block reason to the registration page', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->checkedIn()->create();

    $this->actingAs($user)->get("/events/{$event->slug}/register")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.canCancel', false)
            ->where('registration.cancelBlockedReason', 'checked_in'));

    $open = Event::factory()->live()->create();
    EventRegistration::factory()->for($open)->for($user)->create();

    $this->actingAs($user)->get("/events/{$open->slug}/register")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.canCancel', true)
            ->where('registration.cancelBlockedReason', null));
});

it('clears check-in and releases the seat when a registration is cancelled by orga', function () {
    $event = Event::factory()->live()->create();
    $registration = EventRegistration::factory()->for($event)->checkedIn()->create();
    $seat = Seat::factory()->create(['event_id' => $event->id]);
    SeatAssignment::create(['seat_id' => $seat->id, 'registration_id' => $registration->id]);

    app(CancelRegistration::class)->handle($registration);

    expect($registration->fresh()->checked_in_at)->toBeNull()
        ->and(SeatAssignment::query()->where('registration_id', $registration->id)->exists())->toBeFalse();
});

it('offers orga a cancel row action in the event registrations table', function () {
    $event = Event::factory()->live()->create();
    $registration = EventRegistration::factory()->for($event)->checkedIn()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RegistrationsRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])->callTableAction('cancel_registration', $registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->fresh()->checked_in_at)->toBeNull();
});
