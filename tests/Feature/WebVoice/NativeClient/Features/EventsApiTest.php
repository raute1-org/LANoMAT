<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    $this->user = User::factory()->create();
});

/** @return array<string, array{0: string, 1: string}> */
function nativeFeatureRoutes(): array
{
    return [
        'events' => ['GET', '/api/voice-client/v1/events'],
        'polls index' => ['GET', '/api/voice-client/v1/events/x/polls'],
        'poll show' => ['GET', '/api/voice-client/v1/polls/1'],
        'poll vote' => ['POST', '/api/voice-client/v1/polls/1/vote'],
        'catering' => ['GET', '/api/voice-client/v1/events/x/catering'],
        'catering mine' => ['GET', '/api/voice-client/v1/events/x/catering/mine'],
        'order item place' => ['POST', '/api/voice-client/v1/food-orders/1/items'],
        'order item cancel' => ['DELETE', '/api/voice-client/v1/food-order-items/1'],
        'files' => ['GET', '/api/voice-client/v1/events/x/files'],
        'files quota' => ['GET', '/api/voice-client/v1/events/x/files/quota'],
        'files ticket' => ['POST', '/api/voice-client/v1/events/x/files/uploads/ticket'],
        'file download' => ['GET', '/api/voice-client/v1/files/1/download'],
        'file delete' => ['DELETE', '/api/voice-client/v1/files/1'],
    ];
}

it('answers 401 on every event feature route for missing, garbage and revoked tokens', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
    $this->json($method, $uri, [], ['Authorization' => 'Bearer garbage'])->assertUnauthorized();

    $headers = nativeBearer($this->user);
    VoiceClientToken::query()->update(['revoked_at' => now()]);
    $this->json($method, $uri, [], $headers)->assertUnauthorized();
})->with(array_values(nativeFeatureRoutes()));

it('does not accept a bearer token on the browser routes', function () {
    $event = Event::factory()->registration()->create();
    $headers = nativeBearer($this->user);

    $statuses = [];
    foreach (['polls', 'catering', 'files'] as $page) {
        $statuses[$page] = $this->get("/events/{$event->slug}/{$page}", $headers)->getStatusCode();
    }

    expect($statuses)->toBe(['polls' => 302, 'catering' => 302, 'files' => 302]);
    $this->postJson("/events/{$event->slug}/files/uploads", ['name' => 'a.zip', 'size' => 5], $headers)->assertUnauthorized();
});

it('does not accept a browser session on the client API', function () {
    $this->actingAs($this->user)->getJson('/api/voice-client/v1/events')->assertUnauthorized();
});

it('lists every non-draft event with slug, status, dates and the own registration state', function () {
    $draft = Event::factory()->draft()->create();
    $reg = Event::factory()->registration()->create(['starts_at' => now()->addDays(10)]);
    $done = Event::factory()->finished()->create(['starts_at' => now()->subDays(40)]);
    EventRegistration::factory()->paid()->create(['event_id' => $reg->id, 'user_id' => $this->user->id]);
    EventRegistration::factory()->cancelled()->create(['event_id' => $done->id, 'user_id' => $this->user->id]);
    EventRegistration::factory()->create(['event_id' => $reg->id]); // somebody else's

    $response = $this->getJson('/api/voice-client/v1/events', nativeBearer($this->user))->assertOk();

    expect(collect($response->json('events'))->pluck('slug')->all())->toBe([$reg->slug, $done->slug])
        ->and(collect($response->json('events'))->pluck('slug')->contains($draft->slug))->toBeFalse();

    $row = $response->json('events.0');
    expect($row['status'])->toBe('registration')
        ->and($row['name'])->toBe($reg->name)
        ->and($row['starts_at'])->toBe($reg->starts_at->toIso8601String())
        ->and($row['registration'])->toMatchArray(['status' => 'confirmed', 'paid' => true, 'checked_in' => false, 'ticket_type' => 'standard']);

    // A cancelled registration is no registration.
    expect($response->json('events.1.registration'))->toBeNull();
});

it('marks a live event as current even without a registration', function () {
    $upcoming = Event::factory()->announced()->create(['starts_at' => now()->addDays(3)]);
    $live = Event::factory()->live()->create(['starts_at' => now()->subDay()]);
    EventRegistration::factory()->create(['event_id' => $upcoming->id, 'user_id' => $this->user->id]);

    $response = $this->getJson('/api/voice-client/v1/events', nativeBearer($this->user))->assertOk();

    expect($response->json('current_event_slug'))->toBe($live->slug);
    expect(collect($response->json('events'))->where('current', true)->pluck('slug')->all())->toBe([$live->slug]);
});

it('prefers the live event the caller is registered for', function () {
    $other = Event::factory()->live()->create(['starts_at' => now()->subHour()]);
    $mine = Event::factory()->live()->create(['starts_at' => now()->subDays(2)]);
    EventRegistration::factory()->create(['event_id' => $mine->id, 'user_id' => $this->user->id]);

    $slug = $this->getJson('/api/voice-client/v1/events', nativeBearer($this->user))->json('current_event_slug');

    expect($slug)->toBe($mine->slug)->and($slug)->not->toBe($other->slug);
});

it('falls back to the nearest upcoming event the caller is registered for', function () {
    $far = Event::factory()->registration()->create(['starts_at' => now()->addDays(30)]);
    $near = Event::factory()->announced()->create(['starts_at' => now()->addDays(5)]);
    $notMine = Event::factory()->registration()->create(['starts_at' => now()->addDay()]);
    $past = Event::factory()->finished()->create(['starts_at' => now()->subDays(2)]);
    foreach ([$far, $near, $past] as $event) {
        EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $this->user->id]);
    }

    $response = $this->getJson('/api/voice-client/v1/events', nativeBearer($this->user))->assertOk();

    expect($response->json('current_event_slug'))->toBe($near->slug)
        ->and(collect($response->json('events'))->where('current', true)->count())->toBe(1)
        ->and($notMine->slug)->not->toBe($near->slug);
});

it('has no current event when the caller is registered for nothing upcoming or live', function () {
    Event::factory()->registration()->create(['starts_at' => now()->addDay()]);
    Event::factory()->finished()->create();

    $this->getJson('/api/voice-client/v1/events', nativeBearer($this->user))
        ->assertOk()
        ->assertJsonPath('current_event_slug', null);
});
