<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('matches a friendship between two users regardless of direction', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id]);

    expect(Friendship::query()->betweenUsers($a->id, $b->id)->first()?->id)->toBe($friendship->id)
        ->and(Friendship::query()->betweenUsers($b->id, $a->id)->first()?->id)->toBe($friendship->id);
});

it('returns the correct other user from otherUser', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id]);

    expect($friendship->otherUser($a)->id)->toBe($b->id)
        ->and($friendship->otherUser($b)->id)->toBe($a->id);
});

it('reports incoming and outgoing pending requests', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => FriendshipStatus::Pending]);

    expect($a->outgoingRequests()->pluck('addressee_id'))->toContain($b->id)
        ->and($b->incomingRequests()->pluck('requester_id'))->toContain($a->id)
        ->and($a->incomingRequests())->toHaveCount(0)
        ->and($b->outgoingRequests())->toHaveCount(0);
});

it('reports hasBlocked and isBlockedBy correctly', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    UserBlock::factory()->create(['blocker_id' => $a->id, 'blocked_id' => $b->id]);

    expect($a->hasBlocked($b))->toBeTrue()
        ->and($b->hasBlocked($a))->toBeFalse()
        ->and($b->isBlockedBy($a))->toBeTrue()
        ->and($a->isBlockedBy($b))->toBeFalse()
        ->and($a->blockedUsers()->pluck('id'))->toContain($b->id);
});
