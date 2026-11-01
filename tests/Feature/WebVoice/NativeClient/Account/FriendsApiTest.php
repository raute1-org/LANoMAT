<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Friends\Notifications\FriendRequestReceived;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

const NATIVE_FRIENDS_V1 = '/api/voice-client/v1';

beforeEach(function () {
    $this->ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'discord_id' => '111']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->cy = User::factory()->create(['name' => 'Cy']);
    $this->adaH = accountBearer($this->ada);
    $this->bobH = accountBearer($this->bob);
});

function nativeFriendship(User $requester, User $addressee, FriendshipStatus $status = FriendshipStatus::Accepted): Friendship
{
    return Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $addressee->id, 'status' => $status]);
}

describe('authentication', function () {
    it('answers 401 without, with garbage and with revoked tokens on every friends route', function () {
        $routes = [
            ['GET', '/friends'], ['GET', '/friends/requests'], ['GET', '/friends/suggestions'], ['GET', '/friends/blocked'],
            ['POST', '/friends/requests'], ['POST', '/friends/requests/1/accept'], ['POST', '/friends/requests/1/decline'],
            ['DELETE', '/friends/requests/1'], ['DELETE', '/friends/1'], ['POST', '/users/1/block'], ['DELETE', '/users/1/block'],
        ];
        $revoked = accountBearer($this->ada);
        VoiceClientToken::query()->where('token_hash', hash('sha256', substr($revoked['Authorization'], 7)))->firstOrFail()->forceFill(['revoked_at' => now()])->save();

        foreach ($routes as [$method, $path]) {
            $this->json($method, NATIVE_FRIENDS_V1.$path)->assertUnauthorized();
            $this->json($method, NATIVE_FRIENDS_V1.$path, [], ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
            $this->json($method, NATIVE_FRIENDS_V1.$path, [], $revoked)->assertUnauthorized();
        }
    });

    it('does not accept a bearer token on the web friends routes', function () {
        $this->postJson('/friends/request', ['user_id' => $this->bob->id], $this->adaH)->assertUnauthorized();
        $this->getJson('/friends', $this->adaH)->assertUnauthorized();
    });
});

describe('reads', function () {
    it('lists friends with display fields only', function () {
        nativeFriendship($this->bob, $this->ada);
        nativeFriendship($this->cy, $this->bob); // not mine

        $response = $this->getJson(NATIVE_FRIENDS_V1.'/friends', $this->adaH)->assertOk();

        expect($response->json('friends'))->toBe([['id' => $this->bob->id, 'name' => 'Bob', 'avatar_url' => $this->bob->avatar_url]]);
        expect($response->getContent())->not->toContain($this->bob->email)->not->toContain('discord_id');
    });

    it('lists incoming and outgoing requests with the friendship id', function () {
        $in = nativeFriendship($this->bob, $this->ada, FriendshipStatus::Pending);
        $out = nativeFriendship($this->ada, $this->cy, FriendshipStatus::Pending);

        $response = $this->getJson(NATIVE_FRIENDS_V1.'/friends/requests', $this->adaH)->assertOk();

        expect($response->json('incoming.0'))->toMatchArray(['id' => $in->id, 'user' => ['id' => $this->bob->id, 'name' => 'Bob', 'avatar_url' => $this->bob->avatar_url]])
            ->and($response->json('outgoing.0.id'))->toBe($out->id)
            ->and($response->json('outgoing.0.user.id'))->toBe($this->cy->id);
    });

    it('lists blocked users', function () {
        UserBlock::factory()->create(['blocker_id' => $this->ada->id, 'blocked_id' => $this->bob->id]);
        UserBlock::factory()->create(['blocker_id' => $this->cy->id, 'blocked_id' => $this->ada->id]);

        $this->getJson(NATIVE_FRIENDS_V1.'/friends/blocked', $this->adaH)->assertOk()
            ->assertJsonCount(1, 'blocked')->assertJsonPath('blocked.0.id', $this->bob->id);
    });

    it('serves the suggestions of FriendSuggestions with the web reason labels', function () {
        $team = Team::factory()->create();
        foreach ([$this->ada, $this->bob] as $member) {
            TeamMember::query()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member']);
        }

        $response = $this->getJson(NATIVE_FRIENDS_V1.'/friends/suggestions', $this->adaH)->assertOk();

        expect($response->json('suggestions.0'))->toMatchArray([
            'id' => $this->bob->id, 'name' => 'Bob', 'shared' => 1, 'reasons' => ['shared_team'], 'reason_labels' => ['Gemeinsames Team'],
        ]);
    });

    it('does not suggest users who blocked the caller', function () {
        $team = Team::factory()->create();
        foreach ([$this->ada, $this->bob] as $member) {
            TeamMember::query()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member']);
        }
        UserBlock::factory()->create(['blocker_id' => $this->bob->id, 'blocked_id' => $this->ada->id]);

        $this->getJson(NATIVE_FRIENDS_V1.'/friends/suggestions', $this->adaH)->assertOk()->assertJsonCount(0, 'suggestions');
    });
});

describe('requests', function () {
    it('creates a pending request and notifies the addressee', function () {
        Notification::fake();

        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->bob->id], $this->adaH)
            ->assertCreated()->assertExactJson(['status' => 'pending']);

        expect(Friendship::query()->where('requester_id', $this->ada->id)->where('addressee_id', $this->bob->id)->where('status', FriendshipStatus::Pending)->count())->toBe(1);
        Notification::assertSentTo($this->bob, FriendRequestReceived::class);
    });

    it('auto-accepts a mutual request', function () {
        nativeFriendship($this->bob, $this->ada, FriendshipStatus::Pending);

        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->bob->id], $this->adaH)
            ->assertCreated()->assertJsonPath('status', 'accepted');

        expect(Friendship::query()->count())->toBe(1)->and($this->ada->acceptedFriends()->pluck('id')->all())->toBe([$this->bob->id]);
    });

    it('validates the body and refuses self, duplicates and friends with a domain code', function () {
        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', [], $this->adaH)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => 999999], $this->adaH)->assertUnprocessable();
        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->ada->id], $this->adaH)
            ->assertUnprocessable()->assertJsonPath('code', 'cannot_friend_self');

        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->bob->id], $this->adaH)->assertCreated();
        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->bob->id], $this->adaH)
            ->assertUnprocessable()->assertJsonPath('code', 'request_pending');

        nativeFriendship($this->ada, $this->cy);
        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->cy->id], $this->adaH)
            ->assertUnprocessable()->assertJsonPath('code', 'already_friends');
    });

    it('answers identically whether or not the addressee blocked the caller (no blocked-by oracle)', function () {
        Notification::fake();
        UserBlock::factory()->create(['blocker_id' => $this->bob->id, 'blocked_id' => $this->ada->id]);

        $blockedBy = $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->bob->id], $this->adaH);
        $normal = $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->cy->id], $this->adaH);

        expect($blockedBy->status())->toBe($normal->status())->and($blockedBy->getContent())->toBe($normal->getContent());
        // Nothing was created or notified for the blocker.
        expect(Friendship::query()->where('addressee_id', $this->bob->id)->count())->toBe(0);
        Notification::assertNotSentTo($this->bob, FriendRequestReceived::class);
    });

    it('tells the blocker about its own block', function () {
        UserBlock::factory()->create(['blocker_id' => $this->ada->id, 'blocked_id' => $this->bob->id]);

        $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $this->bob->id], $this->adaH)
            ->assertUnprocessable()->assertJsonPath('code', 'blocked');
    });

    it('accepts and declines only as the addressee', function () {
        $request = nativeFriendship($this->bob, $this->ada, FriendshipStatus::Pending);
        $stranger = accountBearer($this->cy);

        // Requester cannot answer its own request (403), a stranger does not even see it (404).
        $this->postJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}/accept", [], $this->bobH)->assertForbidden();
        $this->postJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}/accept", [], $stranger)->assertNotFound();
        $this->postJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}/decline", [], $stranger)->assertNotFound();
        expect($request->refresh()->status)->toBe(FriendshipStatus::Pending);

        $this->postJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}/accept", [], $this->adaH)->assertOk()->assertJsonPath('status', 'accepted');
        expect($request->refresh()->status)->toBe(FriendshipStatus::Accepted);
    });

    it('declines by deleting the request', function () {
        $request = nativeFriendship($this->bob, $this->ada, FriendshipStatus::Pending);

        $this->postJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}/decline", [], $this->adaH)->assertOk()->assertJsonPath('status', 'declined');

        expect(Friendship::query()->find($request->id))->toBeNull();
    });

    it('cancels only an own outgoing request', function () {
        $request = nativeFriendship($this->ada, $this->bob, FriendshipStatus::Pending);
        $stranger = accountBearer($this->cy);

        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}", [], $stranger)->assertNotFound();
        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}", [], $this->bobH)->assertForbidden();
        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/requests/{$request->id}", [], $this->adaH)->assertNoContent();

        expect(Friendship::query()->find($request->id))->toBeNull();
    });

    it('cannot answer an already accepted request again', function () {
        $friendship = nativeFriendship($this->bob, $this->ada);

        $this->postJson(NATIVE_FRIENDS_V1."/friends/requests/{$friendship->id}/decline", [], $this->adaH)->assertForbidden();
        expect(Friendship::query()->find($friendship->id))->not->toBeNull();
    });

    it('rate limits sending requests', function () {
        $others = User::factory()->count(21)->create();
        $status = null;

        foreach ($others as $other) {
            $status = $this->postJson(NATIVE_FRIENDS_V1.'/friends/requests', ['user_id' => $other->id], $this->adaH)->status();
        }

        expect($status)->toBe(429);
    });
});

describe('remove and block', function () {
    it('removes a friend, idempotently and without revealing anything about strangers', function () {
        nativeFriendship($this->bob, $this->ada);

        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/{$this->bob->id}", [], $this->adaH)->assertNoContent();
        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/{$this->bob->id}", [], $this->adaH)->assertNoContent();
        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/{$this->cy->id}", [], $this->adaH)->assertNoContent();

        expect(Friendship::query()->count())->toBe(0);
    });

    it('never removes a friendship between two other users', function () {
        $other = nativeFriendship($this->bob, $this->cy);

        $this->deleteJson(NATIVE_FRIENDS_V1."/friends/{$this->cy->id}", [], $this->adaH)->assertNoContent();

        expect(Friendship::query()->find($other->id))->not->toBeNull();
    });

    it('blocks with teardown of friendship and pending requests, and unblocks', function () {
        nativeFriendship($this->bob, $this->ada);
        nativeFriendship($this->ada, $this->cy, FriendshipStatus::Pending);

        $this->postJson(NATIVE_FRIENDS_V1."/users/{$this->bob->id}/block", [], $this->adaH)->assertOk()->assertJsonPath('blocked', true);
        $this->postJson(NATIVE_FRIENDS_V1."/users/{$this->cy->id}/block", [], $this->adaH)->assertOk();

        expect(Friendship::query()->count())->toBe(0)->and($this->ada->hasBlocked($this->bob))->toBeTrue();

        $this->deleteJson(NATIVE_FRIENDS_V1."/users/{$this->bob->id}/block", [], $this->adaH)->assertNoContent();
        expect($this->ada->hasBlocked($this->bob))->toBeFalse()->and($this->ada->hasBlocked($this->cy))->toBeTrue();
    });

    it('blocks identically whether or not the target already blocked the caller', function () {
        UserBlock::factory()->create(['blocker_id' => $this->bob->id, 'blocked_id' => $this->ada->id]);

        $a = $this->postJson(NATIVE_FRIENDS_V1."/users/{$this->bob->id}/block", [], $this->adaH);
        $b = $this->postJson(NATIVE_FRIENDS_V1."/users/{$this->cy->id}/block", [], $this->adaH);

        expect($a->status())->toBe($b->status())->and($a->getContent())->toBe($b->getContent());
    });

    it('refuses to block oneself with a domain code', function () {
        $this->postJson(NATIVE_FRIENDS_V1."/users/{$this->ada->id}/block", [], $this->adaH)
            ->assertUnprocessable()->assertJsonPath('code', 'cannot_block_self');
    });

    it('404s for a user that does not exist', function () {
        $this->postJson(NATIVE_FRIENDS_V1.'/users/999999/block', [], $this->adaH)->assertNotFound();
    });

    it('ignores user ids in the body: the actor is always the token owner', function () {
        $this->postJson(NATIVE_FRIENDS_V1."/users/{$this->cy->id}/block", ['blocker_id' => $this->bob->id, 'user_id' => $this->bob->id], $this->adaH)->assertOk();

        expect(UserBlock::query()->where('blocker_id', $this->bob->id)->count())->toBe(0)->and($this->ada->hasBlocked($this->cy))->toBeTrue();
    });
});
