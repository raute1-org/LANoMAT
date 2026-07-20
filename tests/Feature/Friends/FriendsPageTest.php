<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows friends, incoming requests, and suggestions without leaking private fields', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->create([
        'requester_id' => $friend->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    $this->actingAs($me)->get('/friends')
        ->assertInertia(fn ($page) => $page->component('Friends/Index')
            ->has('friends', 1)
            ->where('friends.0.id', $friend->id)
            ->missing('friends.0.email')
        );
});

it('lists incoming and outgoing pending requests separately', function () {
    $me = User::factory()->create();
    $incomingFrom = User::factory()->create();
    $outgoingTo = User::factory()->create();

    $incoming = Friendship::factory()->create([
        'requester_id' => $incomingFrom->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Pending,
    ]);
    Friendship::factory()->create([
        'requester_id' => $me->id,
        'addressee_id' => $outgoingTo->id,
        'status' => FriendshipStatus::Pending,
    ]);

    $this->actingAs($me)->get('/friends')
        ->assertInertia(fn ($page) => $page->component('Friends/Index')
            ->has('incoming', 1)
            ->where('incoming.0.friendshipId', $incoming->id)
            ->where('incoming.0.from.id', $incomingFrom->id)
            ->has('outgoing', 1)
            ->where('outgoing.0.to.id', $outgoingTo->id)
            ->missing('incoming.0.from.email')
        );
});

it('lists blocked users', function () {
    $me = User::factory()->create();
    $blocked = User::factory()->create();
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $blocked->id]);

    $this->actingAs($me)->get('/friends')
        ->assertInertia(fn ($page) => $page->component('Friends/Index')
            ->has('blocked', 1)
            ->where('blocked.0.id', $blocked->id)
        );
});

it('sends a friend request', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($me)->post('/friends/request', ['user_id' => $other->id])
        ->assertRedirect();

    expect(Friendship::query()->betweenUsers($me->id, $other->id)->exists())->toBeTrue();
});

it('flashes a German error instead of a 500 when the request is invalid', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->post('/friends/request', ['user_id' => $me->id])
        ->assertRedirect()
        ->assertInertiaFlash('toast');
});

it('accepts an incoming request', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $other->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Pending,
    ]);

    $this->actingAs($me)->patch("/friends/{$friendship->id}/respond", ['accept' => true])
        ->assertRedirect();

    expect($friendship->fresh()->status)->toBe(FriendshipStatus::Accepted);
});

it('declines an incoming request', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $other->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Pending,
    ]);

    $this->actingAs($me)->patch("/friends/{$friendship->id}/respond", ['accept' => false])
        ->assertRedirect();

    expect(Friendship::query()->find($friendship->id))->toBeNull();
});

it('forbids responding to a request addressed to someone else', function () {
    $me = User::factory()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $a->id,
        'addressee_id' => $b->id,
        'status' => FriendshipStatus::Pending,
    ]);

    $this->actingAs($me)->patch("/friends/{$friendship->id}/respond", ['accept' => true])
        ->assertForbidden();
});

it('cancels an outgoing request', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $me->id,
        'addressee_id' => $other->id,
        'status' => FriendshipStatus::Pending,
    ]);

    $this->actingAs($me)->delete("/friends/{$friendship->id}/cancel")
        ->assertRedirect();

    expect(Friendship::query()->find($friendship->id))->toBeNull();
});

it('removes an accepted friend', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->create([
        'requester_id' => $friend->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    $this->actingAs($me)->delete("/friends/{$friend->id}")
        ->assertRedirect();

    expect(Friendship::query()->betweenUsers($me->id, $friend->id)->exists())->toBeFalse();
});

it('blocks and unblocks a user', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($me)->post("/friends/{$other->id}/block")
        ->assertRedirect();
    expect($me->hasBlocked($other))->toBeTrue();

    $this->actingAs($me)->delete("/friends/{$other->id}/block")
        ->assertRedirect();
    expect($me->fresh()->hasBlocked($other))->toBeFalse();
});

it('requires authentication to view the friends page', function () {
    $this->get('/friends')->assertRedirect('/login');
});
