<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows any logged-in user to create a registration when the event is open', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    expect($user->can('create', [EventRegistration::class, $event]))->toBeTrue();
});

it('denies creating a registration when the event is not open', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();

    expect($user->can('create', [EventRegistration::class, $event]))->toBeFalse();
});

it('allows the owner to cancel their own registration', function () {
    $user = User::factory()->create();
    $registration = EventRegistration::factory()->for($user)->create();

    expect($user->can('cancel', $registration))->toBeTrue();
});

it('denies cancelling another users registration', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $registration = EventRegistration::factory()->for($owner)->create();

    expect($other->can('cancel', $registration))->toBeFalse();
});

it('allows orga to cancel any registration', function () {
    $orga = User::factory()->create(['role' => Role::Orga]);
    $registration = EventRegistration::factory()->create();

    expect($orga->can('cancel', $registration))->toBeTrue();
});

it('restricts viewAny and update to orga', function () {
    $user = User::factory()->create();
    $orga = User::factory()->create(['role' => Role::Orga]);
    $registration = EventRegistration::factory()->create();

    expect($user->can('viewAny', EventRegistration::class))->toBeFalse()
        ->and($orga->can('viewAny', EventRegistration::class))->toBeTrue()
        ->and($user->can('update', $registration))->toBeFalse()
        ->and($orga->can('update', $registration))->toBeTrue();
});
