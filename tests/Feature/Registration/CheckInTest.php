<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('checks in a registration by qr token', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $result = app(CheckInRegistration::class)->handle($event, $reg->qr_token);

    expect($result->checked_in_at)->not->toBeNull()
        ->and($reg->fresh()->checked_in_at)->not->toBeNull();
});

it('rejects an unknown token', function () {
    $event = Event::factory()->live()->create();

    expect(fn () => app(CheckInRegistration::class)->handle($event, 'nope'))
        ->toThrow(CheckInException::class);
});

it('rejects a token belonging to another event', function () {
    $eventA = Event::factory()->live()->create();
    $eventB = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($eventB)->create();

    expect(fn () => app(CheckInRegistration::class)->handle($eventA, $reg->qr_token))
        ->toThrow(CheckInException::class);
});

it('rejects a double check-in', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->checkedIn()->create();

    expect(fn () => app(CheckInRegistration::class)->handle($event, $reg->qr_token))
        ->toThrow(CheckInException::class);
});

it('rejects check-in of a cancelled registration', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->cancelled()->create();

    expect(fn () => app(CheckInRegistration::class)->handle($event, $reg->qr_token))
        ->toThrow(CheckInException::class);
});

it('forbids non-orga from the check-in endpoint', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token])
        ->assertForbidden();
});

it('checks in via the orga endpoint', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->orga()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token])
        ->assertRedirect();

    expect($reg->fresh()->checked_in_at)->not->toBeNull();
});

it('allows a helper to check in via the orga endpoint', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token])
        ->assertRedirect();

    expect($reg->fresh()->checked_in_at)->not->toBeNull();
});

it('allows a helper to view the check-in page', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->get("/orga/events/{$event->slug}/checkin")
        ->assertOk();
});

it('forbids a helper from the admin panel', function () {
    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('flashes a success toast reaching the Inertia response on check-in', function () {
    $event = Event::factory()->live()->create();
    $participant = User::factory()->create(['name' => 'Sitter']);
    $reg = EventRegistration::factory()->for($event)->for($participant)->create();

    $response = $this->actingAs(User::factory()->orga()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token]);

    $response->assertRedirect();
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => trans('registration.checkin.done', ['name' => 'Sitter']),
    ]);
});

it('flashes an error toast reaching the Inertia response for an unknown token', function () {
    $event = Event::factory()->live()->create();

    $response = $this->actingAs(User::factory()->orga()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => 'nope']);

    $response->assertRedirect();
    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => trans('registration.checkin.errors.unknown_token'),
    ]);
});

it('shows a translated label on the check-in page', function () {
    $event = Event::factory()->live()->create();

    $response = $this->actingAs(User::factory()->orga()->create())
        ->get("/orga/events/{$event->slug}/checkin");

    $response->assertInertia(fn ($page) => $page
        ->component('Orga/CheckIn')
        ->where('labels.title', 'Check-in')
    );
});

it('flashes a scanner result with name and ticket label on success', function () {
    $event = Event::factory()->live()->create();
    $participant = User::factory()->create(['name' => 'Sitter']);
    $reg = EventRegistration::factory()->for($event)->for($participant)->create(['ticket_type' => 'standard']);

    $response = $this->actingAs(User::factory()->helper()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token]);

    $response->assertSessionHas('inertia.flash_data.checkin.status', 'success');
    $response->assertSessionHas('inertia.flash_data.checkin.name', 'Sitter');
    $response->assertSessionHas('inertia.flash_data.checkin.ticket', 'Standard');
});

it('flashes a failing scanner result without leaking a name for an unknown or repeated token', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->checkedIn()->create();
    $helper = User::factory()->helper()->create();

    $unknown = $this->actingAs($helper)->post("/orga/events/{$event->slug}/checkin", ['qr_token' => 'nope']);
    $unknown->assertSessionHas('inertia.flash_data.checkin.status', 'error');
    $unknown->assertSessionHas('inertia.flash_data.checkin.message', trans('registration.checkin.errors.unknown_token'));
    $unknown->assertSessionMissing('inertia.flash_data.checkin.name');

    $again = $this->actingAs($helper)->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token]);
    $again->assertSessionHas('inertia.flash_data.checkin.status', 'error');
    $again->assertSessionHas('inertia.flash_data.checkin.message', trans('registration.checkin.errors.already_checked_in'));
});

it('rejects an oversized scanned token', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => str_repeat('a', 65)])
        ->assertSessionHasErrors('qr_token');
});
