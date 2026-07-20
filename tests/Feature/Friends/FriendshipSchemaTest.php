<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces a single friendship row per ordered pair', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id]);

    expect(fn () => Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id]))
        ->toThrow(QueryException::class);
});

it('resolves accepted friends in both directions', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => FriendshipStatus::Accepted]);

    expect($a->acceptedFriends()->pluck('id'))->toContain($b->id)
        ->and($b->acceptedFriends()->pluck('id'))->toContain($a->id);
});
