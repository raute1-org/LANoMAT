<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Identity\Actions\CacheAvatar;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AvatarHelpers.php';
require_once __DIR__.'/../Presence/PresenceHelpers.php';
require_once __DIR__.'/../WebVoice/NativeClient/Account/Helpers.php';

uses(RefreshDatabase::class);

/*
| Every place that used to emit `avatar_url`: the value is now LANoMAT's own URL (bearer path for the
| native client, browser path for Inertia) or null, and the Discord CDN address never appears.
*/

const CDN = 'https://cdn.discordapp.com/avatars/42/secret-source.png';

function cdnUser(string $name): User
{
    $user = User::factory()->create(['name' => $name, 'avatar_url' => CDN]);
    app(CacheAvatar::class)->handle($user);

    return $user->refresh();
}

beforeEach(function () {
    fakeAvatars()->willReturn(avatarImage());
    $this->ada = cdnUser('Ada');
    $this->bob = cdnUser('Bob');
    $this->bobNative = "/api/voice-client/v1/avatars/{$this->bob->id}/{$this->bob->avatar_hash}";
    $this->bobWeb = "/avatars/{$this->bob->id}/{$this->bob->avatar_hash}";
    Friendship::factory()->create(['requester_id' => $this->bob->id, 'addressee_id' => $this->ada->id, 'status' => FriendshipStatus::Accepted]);
    Friendship::factory()->create(['requester_id' => cdnUser('Cy')->id, 'addressee_id' => $this->ada->id, 'status' => FriendshipStatus::Pending]);
});

it('emits the bearer path in the native friends, requests, profile and chat DTOs, never the CDN', function () {
    $headers = accountBearer($this->ada);
    $lobby = WebVoiceChannel::factory()->create(['name' => 'Lobby']);

    $friends = $this->getJson('/api/voice-client/v1/friends', $headers)->assertOk();
    $requests = $this->getJson('/api/voice-client/v1/friends/requests', $headers)->assertOk();
    $profile = $this->getJson('/api/voice-client/v1/profile', $headers)->assertOk();
    $posted = $this->postJson("/api/voice-client/v1/rooms/{$lobby->id}/messages", ['body' => 'hi'], $headers)->assertCreated();
    $history = $this->getJson("/api/voice-client/v1/rooms/{$lobby->id}/messages", $headers)->assertOk();

    expect($friends->json('friends.0.avatar_url'))->toBe($this->bobNative)
        ->and($profile->json('profile.avatar_url'))->toBe("/api/voice-client/v1/avatars/{$this->ada->id}/{$this->ada->avatar_hash}")
        ->and($posted->json('message.author.avatar_url'))->toBe("/api/voice-client/v1/avatars/{$this->ada->id}/{$this->ada->avatar_hash}")
        ->and($history->json('messages.0.author.avatar_url'))->toBe($posted->json('message.author.avatar_url'));

    foreach ([$friends, $requests, $profile, $posted, $history] as $response) {
        expect($response->getContent())->not->toContain('discordapp')->not->toContain('secret-source');
    }
});

it('emits the bearer path in the native people list', function () {
    $world = presenceWorld(['Max']);
    $me = $world['users']['Max'];
    $me->forceFill(['avatar_url' => CDN])->save();
    app(CacheAvatar::class)->handle($me);
    $ada = User::factory()->create(['name' => 'Zed', 'avatar_url' => CDN]);
    playRegister($ada, $world['event']);
    app(CacheAvatar::class)->handle($ada);
    presenceRefresh();

    $response = $this->getJson(presencePeopleUrl($world['event']), playBearer($me))->assertOk();

    expect($response->json('me.avatarUrl'))->toBe("/api/voice-client/v1/avatars/{$me->id}/{$me->refresh()->avatar_hash}")
        ->and(collect($response->json('data'))->firstWhere('id', $ada->id)['avatarUrl'])->toBe("/api/voice-client/v1/avatars/{$ada->id}/{$ada->refresh()->avatar_hash}")
        ->and($response->getContent())->not->toContain('discordapp');
});

it('gives users without a local copy null, not the CDN URL', function () {
    $plain = User::factory()->create(['name' => 'Plain', 'avatar_url' => CDN]);
    Friendship::factory()->create(['requester_id' => $plain->id, 'addressee_id' => $this->ada->id, 'status' => FriendshipStatus::Accepted]);

    $friends = collect($this->getJson('/api/voice-client/v1/friends', accountBearer($this->ada))->json('friends'));

    expect($friends->firstWhere('id', $plain->id)['avatar_url'])->toBeNull()
        ->and($friends->firstWhere('id', $this->bob->id)['avatar_url'])->toBe($this->bobNative);
});

it('feeds the browser pages the browser path as an Inertia prop', function () {
    $this->actingAs($this->ada)->get('/friends')
        ->assertInertia(fn ($page) => $page->component('Friends/Index')
            ->where('friends.0.avatarUrl', $this->bobWeb));

    $this->actingAs($this->ada)->get("/users/{$this->bob->id}")
        ->assertInertia(fn ($page) => $page->component('Profile/Show')->where('profile.avatarUrl', $this->bobWeb));

    // The shared auth user carries the local avatar and no CDN or storage path.
    $page = $this->actingAs($this->ada)->get('/dashboard')->assertOk();
    expect($page->getContent())->not->toContain('discordapp')->not->toContain('secret-source')
        ->and($page->getContent())->toContain('avatar_src');
});

it('shows the public profile avatar to logged-in viewers only', function () {
    $this->get("/users/{$this->bob->id}")
        ->assertInertia(fn ($page) => $page->component('Profile/Show')->where('profile.avatarUrl', null));
});
