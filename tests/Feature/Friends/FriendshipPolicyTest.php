<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Actions\CancelFriendRequest;
use App\Modules\Friends\Actions\RespondToFriendRequest;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('denies a non-addressee from responding to a pending request', function () {
    $requester = User::factory()->create();
    $addressee = User::factory()->create();
    $stranger = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $requester->id,
        'addressee_id' => $addressee->id,
        'status' => FriendshipStatus::Pending,
    ]);

    expect(fn () => app(RespondToFriendRequest::class)->handle($stranger, $friendship, accept: true))
        ->toThrow(AuthorizationException::class);
});

it('denies a non-requester from cancelling a pending request', function () {
    $requester = User::factory()->create();
    $addressee = User::factory()->create();
    $stranger = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $requester->id,
        'addressee_id' => $addressee->id,
        'status' => FriendshipStatus::Pending,
    ]);

    expect(fn () => app(CancelFriendRequest::class)->handle($stranger, $friendship))
        ->toThrow(AuthorizationException::class);
});

it('denies a non-participant from removing a friendship', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $stranger = User::factory()->create();
    $friendship = Friendship::factory()->create([
        'requester_id' => $a->id,
        'addressee_id' => $b->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    // RemoveFriend derives the Friendship row from the two given users via
    // betweenUsers(), so a true non-participant can never reach it through
    // that action (there is no shared friendship row to find). Exercise the
    // policy itself directly to prove the `remove` denial fires.
    expect(fn () => Gate::forUser($stranger)->authorize('remove', $friendship))
        ->toThrow(AuthorizationException::class);
});
