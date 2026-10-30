<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('renders an empty dashboard without event, teams or links', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('event', null)
            ->where('registration', null)
            ->where('seat', null)
            ->where('teams', [])
            ->where('steamLinked', false)
            ->where('labels.title', 'Dashboard')
        );
});

it('shows the current event with the own registration but never the QR payload', function () {
    $event = Event::factory()->registration()->create(['name' => 'Testlan']);
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->paid()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.name', 'Testlan')
            ->where('event.status', 'registration')
            ->where('registration.status', 'confirmed')
            ->where('registration.paid', true)
            ->missing('registration.qrSvg')
            ->missing('registration.qr_token')
        );
});

it('does not expose draft events or other users registrations', function () {
    Event::factory()->draft()->create(['name' => 'Hidden']);
    $event = Event::factory()->registration()->create();
    EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.slug', $event->slug)
            ->where('registration', null)
        );
});

it('shows the own seat for the current event only', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'B7']);
    SeatAssignment::factory()->create(['seat_id' => $seat->id, 'registration_id' => $reg->id]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('seat.label', 'B7')
        );
});

it('lists only the own teams with role', function () {
    $user = User::factory()->create();
    app(CreateTeam::class)->handle($user, 'Alpha', 'ALP');
    Team::factory()->create(['name' => 'Foreign']);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('teams', 1)
            ->where('teams.0.name', 'Alpha')
            ->where('teams.0.role', 'owner')
        );
});

it('reports whether steam is linked', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('steamLinked', true));
});
