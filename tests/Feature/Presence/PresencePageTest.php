<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the presence board with a checked-in seated participant and a free slot', function () {
    $event = Event::factory()->live()->create();

    $user = User::factory()->create(['name' => 'Ada']);
    $registration = EventRegistration::factory()->for($event)->for($user, 'user')->checkedIn()->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1']);
    SeatAssignment::factory()->for($seat)->for($registration, 'registration')->create();

    $tournament = Tournament::factory()->for($event)->enrollment()->create(['max_entries' => 8, 'name' => 'Open Cup']);
    TournamentEntry::factory()->count(3)->for($tournament, 'tournament')->create();

    $this->get("/events/{$event->slug}/presence")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Presence/Index')
            ->where('event.slug', $event->slug)
            ->where('presence.checkedInCount', 1)
            ->where('presence.participants.0.name', 'Ada')
            ->where('presence.participants.0.seatLabel', 'A1')
            ->where('presence.participants.0.isPlaying', false)
            ->where('presence.freeSlots.0.name', 'Open Cup')
            ->where('presence.freeSlots.0.openSpots', 5)
            ->has('labels')
        );
});

it('404s the presence page for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}/presence")
        ->assertNotFound();
});

it('is reachable without a logged-in user', function () {
    $event = Event::factory()->live()->create();

    expect(auth()->check())->toBeFalse();

    $this->get("/events/{$event->slug}/presence")->assertOk();
});
