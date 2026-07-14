<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Notifications\Notifications\RegistrationConfirmed;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('sends a database notification on successful registration', function () {
    Notification::fake();
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/register", ['ticket_type' => 'standard']);

    Notification::assertSentTo($user, RegistrationConfirmed::class);
});

it('stores the notification in the database and exposes it as unread', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();
    $user->notify(new RegistrationConfirmed($event->name));

    expect($user->unreadNotifications()->count())->toBe(1);
});

it('stores the german labels in the notification payload', function () {
    $user = User::factory()->create();

    $user->notify(new RegistrationConfirmed('Testlan 2026'));

    $data = $user->unreadNotifications()->firstOrFail()->data;

    expect($data['title'])->toBe('Anmeldung bestätigt')
        ->and($data['body'])->toBe('Deine Anmeldung für Testlan 2026 ist bestätigt.');
});

it('shares unread notifications as an inertia prop', function () {
    $user = User::factory()->create();
    $user->notify(new RegistrationConfirmed('Testlan 2026'));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('unreadNotifications', 1)
            ->where('unreadNotifications.0.title', 'Anmeldung bestätigt')
            ->where('unreadNotifications.0.body', 'Deine Anmeldung für Testlan 2026 ist bestätigt.'));
});

it('respects a disabled category preference', function () {
    $user = User::factory()->create(['notification_prefs' => ['registration' => false]]);

    expect(app(NotificationPreferences::class)
        ->wants($user, 'registration'))->toBeFalse();
});

it('does not store a database notification when the registration category is disabled', function () {
    $user = User::factory()->create(['notification_prefs' => ['registration' => false]]);

    $user->notify(new RegistrationConfirmed('Testlan 2026'));

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('marks the authenticated user\'s own notification as read', function () {
    $user = User::factory()->create();
    $user->notify(new RegistrationConfirmed('Testlan 2026'));
    $notification = $user->unreadNotifications()->firstOrFail();

    $this->actingAs($user)
        ->post("/notifications/{$notification->id}/read")
        ->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('404s when marking another user\'s notification as read', function () {
    $owner = User::factory()->create();
    $owner->notify(new RegistrationConfirmed('Testlan 2026'));
    $notification = $owner->unreadNotifications()->firstOrFail();

    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->post("/notifications/{$notification->id}/read")
        ->assertNotFound();

    expect($owner->unreadNotifications()->count())->toBe(1);
});

it('requires authentication to mark a notification as read', function () {
    $user = User::factory()->create();
    $user->notify(new RegistrationConfirmed('Testlan 2026'));
    $notification = $user->unreadNotifications()->firstOrFail();

    $this->post("/notifications/{$notification->id}/read")->assertRedirect('/login');
});
