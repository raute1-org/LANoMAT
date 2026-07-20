<?php

use App\Models\User;
use App\Modules\Friends\Actions\BlockUser;
use App\Modules\Friends\Actions\SendFriendRequest;
use App\Modules\Friends\Enums\FriendshipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('exposes no relationship controls for a guest viewer', function () {
    $other = User::factory()->create();

    $this->get("/users/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('relationship', null));
});

it('exposes state "self" when viewing own profile', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->get("/users/{$me->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.state', 'self'));
});

it('exposes the correct relationship state through request -> friends', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('relationship.state', 'none'));

    $friendship = app(SendFriendRequest::class)->handle($me, $other);

    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.state', 'request_sent'));

    $this->actingAs($other)->get("/users/{$me->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.state', 'request_received')
            ->where('relationship.friendshipId', $friendship->id));

    $friendship->update(['status' => FriendshipStatus::Accepted]);

    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('relationship.state', 'friends'));
});

it('exposes state "blocked" when the viewer has blocked the target, taking precedence over friendship', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    app(SendFriendRequest::class)->handle($me, $other);
    app(BlockUser::class)->handle($me, $other);

    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('relationship.state', 'blocked'));
});

it('exposes state "none" (no oracle) when the viewer is blocked by the target', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    app(BlockUser::class)->handle($other, $me);

    $this->actingAs($me)->get("/users/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('relationship.state', 'none'));
});
