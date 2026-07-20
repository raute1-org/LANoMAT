<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxSkipVote;
use App\Modules\Jukebox\Models\JukeboxVote;
use App\Modules\Jukebox\Support\TrackDto;
use App\Modules\Jukebox\Testing\FakeMusicClient;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * @return array{0: Event, 1: User}
 */
function checkedInJukeboxUser(): array
{
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->checkedIn()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    return [$event, $user];
}

it('renders the jukebox board for a public event without requiring auth', function () {
    $event = Event::factory()->live()->create();
    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing, 'title' => 'Now Playing Track']);
    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued, 'title' => 'Queued Track']);

    $this->get("/events/{$event->slug}/jukebox")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Jukebox/Index')
            ->where('nowPlaying.title', 'Now Playing Track')
            ->has('queue', 1)
            ->where('queue.0.title', 'Queued Track')
            ->where('canParticipate', false)
            ->where('canModerate', false)
        );
});

it('returns 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}/jukebox")->assertNotFound();
});

it('reports canParticipate true only for a checked-in viewer', function () {
    [$event, $user] = checkedInJukeboxUser();

    $this->actingAs($user)
        ->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canParticipate', true)
        );
});

it('reports canModerate true for a helper viewer', function () {
    $event = Event::factory()->live()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);

    $this->actingAs($helper)
        ->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canModerate', true)
        );
});

it('never leaks private data in the queue payload beyond public track fields and display name', function () {
    [$event, $user] = checkedInJukeboxUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'added_by' => $user->id, 'status' => QueueItemStatus::Queued]);

    $response = $this->get("/events/{$event->slug}/jukebox");

    $queueEntry = $response->viewData('page')['props']['queue'][0];

    expect(array_keys($queueEntry))->toEqualCanonicalizing([
        'id', 'title', 'artist', 'imageUrl', 'voteCount', 'hasVoted', 'addedByName',
    ]);
    expect($queueEntry['addedByName'])->toBe($user->name);
});

it('reports hasVoted true only for the viewer\'s own vote', function () {
    [$event, $user] = checkedInJukeboxUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);
    JukeboxVote::factory()->create(['jukebox_item_id' => $item->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.0.hasVoted', true)
            ->where('queue.0.voteCount', 1)
        );

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.0.hasVoted', false)
            ->where('queue.0.voteCount', 1)
        );
});

it('reports skipThreshold and skipVotes for the currently playing item', function () {
    [$event, $user] = checkedInJukeboxUser();
    EventRegistration::factory()->checkedIn()->count(5)->create(['event_id' => $event->id]);
    $current = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);
    JukeboxSkipVote::factory()->create(['jukebox_item_id' => $current->id, 'user_id' => $user->id]);

    $this->get("/events/{$event->slug}/jukebox")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('skipVotes', 1)
            ->has('skipThreshold')
        );
});

it('searches Music Assistant and returns track results as json', function () {
    [$event, $user] = checkedInJukeboxUser();
    $fake = app(FakeMusicClient::class);
    $fake->willReturnSearch([new TrackDto(uri: 'ma://track/1', title: 'Found Track', artist: 'Someone')]);
    app()->instance(MusicClient::class, $fake);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/jukebox/search?q=found")
        ->assertOk()
        ->assertJson([
            ['uri' => 'ma://track/1', 'title' => 'Found Track', 'artist' => 'Someone'],
        ]);
});

it('degrades gracefully with an empty result when Music Assistant is unavailable during search', function () {
    [$event, $user] = checkedInJukeboxUser();
    $fake = app(FakeMusicClient::class);
    $fake->willBeUnavailable();
    app()->instance(MusicClient::class, $fake);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/jukebox/search?q=found")
        ->assertOk()
        ->assertJson(['results' => [], 'error' => trans(MusicUnavailable::unreachable()->translationKey)]);
});

it('adds a track to the queue for a checked-in user and redirects back', function () {
    [$event, $user] = checkedInJukeboxUser();

    $this->actingAs($user)
        ->from("/events/{$event->slug}/jukebox")
        ->post("/events/{$event->slug}/jukebox", [
            'uri' => 'ma://track/1',
            'title' => 'New Track',
            'artist' => 'Artist',
            'duration_seconds' => 180,
            'image_url' => null,
        ])
        ->assertRedirect("/events/{$event->slug}/jukebox");

    expect(JukeboxItem::where('event_id', $event->id)->where('uri', 'ma://track/1')->exists())->toBeTrue();
});

it('refuses to add a track for a non-checked-in user with a german flash, never a 500', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/jukebox", [
            'uri' => 'ma://track/1',
            'title' => 'New Track',
        ])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', trans('jukebox.errors.not_checked_in'));

    expect(JukeboxItem::where('event_id', $event->id)->exists())->toBeFalse();
});

it('toggles a vote for a checked-in user', function () {
    [$event, $user] = checkedInJukeboxUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    $this->actingAs($user)
        ->post("/jukebox/{$item->id}/vote")
        ->assertRedirect();

    expect(JukeboxVote::where('jukebox_item_id', $item->id)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('toggles a skip vote for a checked-in user', function () {
    [$event, $user] = checkedInJukeboxUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    $this->actingAs($user)
        ->post("/jukebox/{$item->id}/skip-vote")
        ->assertRedirect();

    expect(JukeboxSkipVote::where('jukebox_item_id', $item->id)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('lets a helper skip the current track via the skip endpoint', function () {
    $event = Event::factory()->live()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    $this->actingAs($helper)
        ->post("/events/{$event->slug}/jukebox/skip")
        ->assertRedirect();

    expect($item->refresh()->status)->toBe(QueueItemStatus::Skipped);
});

it('refuses a non-helper from skipping via the skip endpoint, never a 500', function () {
    [$event, $user] = checkedInJukeboxUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    $this->actingAs($user)
        ->post("/events/{$event->slug}/jukebox/skip")
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', trans('jukebox.errors.not_moderator'));

    expect($item->refresh()->status)->toBe(QueueItemStatus::Playing);
});

it('lets a helper remove any item via the remove endpoint', function () {
    $event = Event::factory()->live()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    $this->actingAs($helper)
        ->delete("/jukebox/{$item->id}")
        ->assertRedirect();

    expect($item->refresh()->status)->toBe(QueueItemStatus::Skipped);
});

it('refuses a non-helper from removing an item, never a 500', function () {
    [$event, $user] = checkedInJukeboxUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    $this->actingAs($user)
        ->delete("/jukebox/{$item->id}")
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', trans('jukebox.errors.not_moderator'));

    expect($item->refresh()->status)->toBe(QueueItemStatus::Queued);
});

it('never trusts a client-supplied user id when adding to the queue', function () {
    [$event, $user] = checkedInJukeboxUser();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/jukebox", [
            'uri' => 'ma://track/1',
            'title' => 'New Track',
            'added_by' => $otherUser->id,
            'user_id' => $otherUser->id,
        ]);

    $item = JukeboxItem::where('event_id', $event->id)->first();
    expect($item->added_by)->toBe($user->id);
});
