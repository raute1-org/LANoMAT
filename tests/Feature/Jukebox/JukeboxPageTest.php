<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Page-level rendering assertions for Jukebox/Index.vue (Task 7) —
 * complements JukeboxEndpointsTest.php's controller/action coverage by
 * asserting on the exact prop shape and labels the Vue page consumes.
 */
it('renders the Jukebox/Index component with the full prop shape for a checked-in participant', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->checkedIn()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);
    $nowPlaying = JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Playing,
        'title' => 'Now Playing Track',
    ]);
    JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Queued,
        'title' => 'Queued Track',
    ]);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/jukebox")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Jukebox/Index')
            ->where('event.id', $event->id)
            ->where('event.slug', $event->slug)
            ->where('event.name', $event->name)
            ->where('nowPlaying.id', $nowPlaying->id)
            ->where('nowPlaying.title', 'Now Playing Track')
            ->has('queue', 1)
            ->where('queue.0.title', 'Queued Track')
            ->has('skipThreshold')
            ->has('skipVotes')
            ->where('canParticipate', true)
            ->where('canModerate', false)
            ->where('labels.title', 'Jukebox')
            ->where('labels.add', 'Hinzufügen')
        );
});

it('renders a read-only board with canParticipate false for a non-checked-in viewer', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$event->slug}/jukebox")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Jukebox/Index')
            ->where('canParticipate', false)
            ->where('canModerate', false)
        );
});

it('renders a read-only board for a user who is not checked in', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/jukebox")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Jukebox/Index')
            ->where('canParticipate', false)
            ->where('canModerate', false)
        );
});

it('reports canModerate true for a helper', function () {
    $event = Event::factory()->live()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);

    $this->actingAs($helper)
        ->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canModerate', true)
        );
});

it('returns 404 for a draft (not publicly visible) event', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/jukebox")->assertNotFound();
});

it('renders nowPlaying as null and an empty queue when nothing is queued', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nowPlaying', null)
            ->has('queue', 0)
        );
});

it('reports Music Assistant availability as a deferred prop and never 500s when it is down', function () {
    $event = Event::factory()->live()->create();
    $music = fakeMusic();
    $music->willBeUnavailable();
    Cache::forget('jukebox.music-available');

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/jukebox")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Jukebox/Index')
            ->missing('musicAvailable')
            ->loadDeferredProps(fn (AssertableInertia $reloaded) => $reloaded->where('musicAvailable', false)));
});

it('reports the backend as available when it answers', function () {
    $event = Event::factory()->live()->create();
    fakeMusic();
    Cache::forget('jukebox.music-available');

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reloaded) => $reloaded->where('musicAvailable', true)));
});
