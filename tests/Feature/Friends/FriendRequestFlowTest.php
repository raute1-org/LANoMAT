<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Actions\RespondToFriendRequest;
use App\Modules\Friends\Actions\SendFriendRequest;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Exceptions\FriendshipException;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Friends\Support\FriendService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a pending request then accepts it into a mutual friendship', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $req = app(SendFriendRequest::class)->handle($a, $b);
    expect($req->status)->toBe(FriendshipStatus::Pending);
    app(RespondToFriendRequest::class)->handle($b, $req, accept: true);
    expect(app(FriendService::class)->areFriends($a, $b))->toBeTrue();
});

it('auto-accepts when the reverse request already exists', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    app(SendFriendRequest::class)->handle($a, $b);           // a → b pending
    $res = app(SendFriendRequest::class)->handle($b, $a);    // b → a should auto-accept
    expect($res->status)->toBe(FriendshipStatus::Accepted)
        ->and(Friendship::count())->toBe(1);
});

it('refuses a request to self, to an existing friend, or across a block', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    expect(fn () => app(SendFriendRequest::class)->handle($a, $a))->toThrow(FriendshipException::class);
    UserBlock::factory()->create(['blocker_id' => $b->id, 'blocked_id' => $a->id]);
    expect(fn () => app(SendFriendRequest::class)->handle($a, $b))->toThrow(FriendshipException::class);
});
