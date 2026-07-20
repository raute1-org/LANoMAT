<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Actions\BlockUser;
use App\Modules\Friends\Actions\SendFriendRequest;
use App\Modules\Friends\Actions\UnblockUser;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Exceptions\FriendshipException;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Friends\Support\FriendService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocking removes any existing friendship and prevents new requests', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => FriendshipStatus::Accepted]);

    app(BlockUser::class)->handle($a, $b);

    expect(app(FriendService::class)->areFriends($a, $b))->toBeFalse()
        ->and(app(FriendService::class)->blockedEitherWay($a, $b))->toBeTrue();
    expect(fn () => app(SendFriendRequest::class)->handle($b, $a))->toThrow(FriendshipException::class);
});

it('removes a pending friendship in either direction when blocking', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $b->id, 'addressee_id' => $a->id, 'status' => FriendshipStatus::Pending]);

    app(BlockUser::class)->handle($a, $b);

    expect(Friendship::query()->betweenUsers($a->id, $b->id)->exists())->toBeFalse();
});

it('refuses to block yourself', function () {
    $a = User::factory()->create();

    expect(fn () => app(BlockUser::class)->handle($a, $a))->toThrow(FriendshipException::class);
});

it('is idempotent when blocking an already-blocked user', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    app(BlockUser::class)->handle($a, $b);
    app(BlockUser::class)->handle($a, $b);

    expect(UserBlock::query()->where('blocker_id', $a->id)->where('blocked_id', $b->id)->count())->toBe(1);
});

it('returns the existing block row when called again', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $first = app(BlockUser::class)->handle($a, $b);
    $second = app(BlockUser::class)->handle($a, $b);

    expect($second->id)->toBe($first->id);
});

it('unblocks by deleting only the blocker-owned block row', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    app(BlockUser::class)->handle($a, $b);

    app(UnblockUser::class)->handle($a, $b);

    expect(UserBlock::query()->where('blocker_id', $a->id)->where('blocked_id', $b->id)->exists())->toBeFalse();
});

it('unblock is a no-op when no block exists', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    app(UnblockUser::class)->handle($a, $b);

    expect(UserBlock::query()->count())->toBe(0);
});

it('unblock does not remove a block held in the reverse direction', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    app(BlockUser::class)->handle($b, $a); // b blocked a

    app(UnblockUser::class)->handle($a, $b); // a "unblocking" b has no reverse row to remove

    expect(UserBlock::query()->where('blocker_id', $b->id)->where('blocked_id', $a->id)->exists())->toBeTrue();
});
