<?php

use App\Models\User;
use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use App\Modules\Notifications\Listeners\NotifyUsersRegistrationOpen;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('registers the in-app invitation listener independent of the Discord module', function () {
    $listeners = collect(EventFacade::getListeners(EventStatusChanged::class));

    expect($listeners)->not->toBeEmpty();

    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();

    // Runs the listener class itself (no Discord client involved).
    (new NotifyUsersRegistrationOpen)->handle(new EventStatusChanged($event, EventStatus::Announced, EventStatus::Registration));

    expect($user->unreadNotifications()->count())->toBe(1)
        ->and($user->unreadNotifications()->first()->data['title'])->toBe('Anmeldung geöffnet');
});

it('invites users without an active registration and skips registered users and opted-out users', function () {
    $event = Event::factory()->announced()->create();
    $free = User::factory()->create();
    $registered = User::factory()->create();
    $cancelled = User::factory()->create();
    $optedOut = User::factory()->create(['notification_prefs' => ['registration' => false]]);
    EventRegistration::factory()->for($event)->for($registered)->create();
    EventRegistration::factory()->for($event)->for($cancelled)->create(['status' => 'cancelled']);

    app(TransitionEventStatus::class)->handle($event, EventStatus::Registration);

    expect($free->unreadNotifications()->count())->toBe(1)
        ->and($cancelled->unreadNotifications()->count())->toBe(1)
        ->and($registered->unreadNotifications()->count())->toBe(0)
        ->and($optedOut->unreadNotifications()->count())->toBe(0);
});

it('does not notify for other transitions', function () {
    $event = Event::factory()->draft()->create();
    $user = User::factory()->create();

    app(TransitionEventStatus::class)->handle($event, EventStatus::Announced);

    expect($user->unreadNotifications()->count())->toBe(0);
});
