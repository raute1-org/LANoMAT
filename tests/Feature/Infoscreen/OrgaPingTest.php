<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Notifications\OrgaPinged;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('notifies all orga/admin/helper users (not other participants) with the caller\'s seat and words when a participant pings orga', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();
    $caller = User::factory()->create(['name' => 'Alice']);
    $registration = EventRegistration::factory()->for($event)->for($caller)->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1']);
    SeatAssignment::factory()->for($seat)->create(['registration_id' => $registration->id]);

    $admin = User::factory()->admin()->create();
    $orga = User::factory()->orga()->create();
    $helper = User::factory()->helper()->create();
    $otherParticipant = User::factory()->create();

    $this->actingAs($caller)
        ->post(route('events.ping-orga', $event), ['words' => 'Netzwerk kaputt bitte'])
        ->assertRedirect();

    Notification::assertSentTo(
        $admin,
        fn (OrgaPinged $notification): bool => $notification->seatLabel === 'A1' && $notification->words === 'Netzwerk kaputt bitte'
    );
    Notification::assertSentTo($orga, OrgaPinged::class);
    Notification::assertSentTo($helper, OrgaPinged::class);
    Notification::assertNotSentTo($otherParticipant, OrgaPinged::class);
    Notification::assertNotSentTo($caller, OrgaPinged::class);
});

it('allows a ping without any words and without a seat assignment', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();
    $caller = User::factory()->create();
    EventRegistration::factory()->for($event)->for($caller)->create();

    $helper = User::factory()->helper()->create();

    $this->actingAs($caller)
        ->post(route('events.ping-orga', $event), [])
        ->assertRedirect();

    Notification::assertSentTo(
        $helper,
        fn (OrgaPinged $notification): bool => $notification->seatLabel === null && $notification->words === null
    );
});

it('rejects more than three words with a German validation error', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();
    $caller = User::factory()->create();
    EventRegistration::factory()->for($event)->for($caller)->create();

    app()->setLocale('de');

    $this->actingAs($caller)
        ->post(route('events.ping-orga', $event), ['words' => 'eins zwei drei vier'])
        ->assertSessionHasErrors('words');

    Notification::assertNothingSent();
});

it('rejects words over 40 characters', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();
    $caller = User::factory()->create();
    EventRegistration::factory()->for($event)->for($caller)->create();

    $this->actingAs($caller)
        ->post(route('events.ping-orga', $event), ['words' => str_repeat('a', 41)])
        ->assertSessionHasErrors('words');

    Notification::assertNothingSent();
});

it('redirects an unauthenticated ping to login', function () {
    $event = Event::factory()->live()->create();

    $this->post(route('events.ping-orga', $event), ['words' => 'hallo'])
        ->assertRedirect(route('login'));
});

it('throttles repeated pings from the same user with a 429', function () {
    Notification::fake();

    $event = Event::factory()->live()->create();
    $caller = User::factory()->create();
    EventRegistration::factory()->for($event)->for($caller)->create();
    User::factory()->helper()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($caller)->post(route('events.ping-orga', $event), []);
    }

    $this->actingAs($caller)
        ->post(route('events.ping-orga', $event), [])
        ->assertStatus(429);
});
