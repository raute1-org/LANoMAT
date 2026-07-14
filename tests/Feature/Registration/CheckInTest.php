<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;

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
