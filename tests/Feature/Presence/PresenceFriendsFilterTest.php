<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('marks a viewer\'s friends on the presence board and never broadcasts it', function () {
    $viewer = User::factory()->create();
    $friend = User::factory()->create();
    $stranger = User::factory()->create();

    Friendship::factory()->create([
        'requester_id' => $viewer->id,
        'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    $event = Event::factory()->live()->create();
    EventRegistration::factory()->for($event)->for($viewer, 'user')->checkedIn()->create();
    EventRegistration::factory()->for($event)->for($friend, 'user')->checkedIn()->create();
    EventRegistration::factory()->for($event)->for($stranger, 'user')->checkedIn()->create();

    $this->actingAs($viewer)->get("/events/{$event->slug}/presence")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(
                'presence.participants',
                fn ($participants) => collect($participants)->firstWhere('userId', $friend->id)['isFriend'] === true
                    && collect($participants)->firstWhere('userId', $stranger->id)['isFriend'] === false
            )
        );

    // PresenceUpdated still broadcasts an empty payload — friend status is
    // per-viewer private data and must never reach the public channel.
    expect((new PresenceUpdated($event->id))->broadcastWith())->toBe([]);
});

it('marks isFriend false for every participant when the viewer is a guest', function () {
    $friend = User::factory()->create();

    $event = Event::factory()->live()->create();
    EventRegistration::factory()->for($event)->for($friend, 'user')->checkedIn()->create();

    expect(auth()->check())->toBeFalse();

    $this->get("/events/{$event->slug}/presence")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(
                'presence.participants',
                fn ($participants) => collect($participants)->every(fn (array $p) => $p['isFriend'] === false)
            )
        );
});
