<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AvatarHelpers.php';
require_once __DIR__.'/../WebVoice/NativeClient/Account/Helpers.php';

uses(RefreshDatabase::class);

it('redirects guests to the login', function () {
    $owner = userWithAvatar();

    $this->get("/avatars/{$owner->id}/{$owner->avatar_hash}")->assertRedirect(route('login'));
});

it('serves the stored bytes to any logged-in user with immutable cache headers', function () {
    $owner = userWithAvatar();
    $viewer = User::factory()->create();

    $response = $this->actingAs($viewer)->get("/avatars/{$owner->id}/{$owner->avatar_hash}")->assertOk();

    expect($response->getContent())->toBe(Storage::disk('local')->get($owner->avatar_path))
        ->and($response->headers->get('Content-Type'))->toBe(function_exists('imagewebp') ? 'image/webp' : 'image/jpeg')
        ->and($response->headers->get('ETag'))->toBe('"'.$owner->avatar_hash.'"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->toContain('max-age=31536000')
        ->toContain('immutable')
        ->not->toContain('public');
});

it('answers a matching If-None-Match with 304', function () {
    $owner = userWithAvatar();

    $this->actingAs(User::factory()->create())
        ->get("/avatars/{$owner->id}/{$owner->avatar_hash}", ['If-None-Match' => '"'.$owner->avatar_hash.'"'])
        ->assertStatus(304);
});

it('is 404 for a wrong hash, a stale hash, an unknown user, a user without avatar and a missing file', function () {
    $owner = userWithAvatar();
    $viewer = User::factory()->create();
    $plain = User::factory()->create();
    $this->actingAs($viewer);

    $this->get("/avatars/{$owner->id}/0123456789abcdef")->assertNotFound();
    $this->get("/avatars/{$owner->id}/not-a-hash")->assertNotFound();
    $this->get("/avatars/999999/{$owner->avatar_hash}")->assertNotFound();
    $this->get("/avatars/{$plain->id}/{$owner->avatar_hash}")->assertNotFound();

    Storage::disk('local')->delete($owner->avatar_path);
    $this->get("/avatars/{$owner->id}/{$owner->avatar_hash}")->assertNotFound();
});

it('has no public file: the avatar disk path is not reachable through the storage url', function () {
    $owner = userWithAvatar();

    // The framework route of the local disk only answers signed URLs.
    $this->get('/storage/'.$owner->avatar_path)->assertForbidden();
});

describe('bearer route for the native client', function () {
    it('answers 401 without a token, with garbage and does not accept the session', function () {
        $owner = userWithAvatar();
        $url = "/api/voice-client/v1/avatars/{$owner->id}/{$owner->avatar_hash}";

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertUnauthorized();
    });

    it('serves the same bytes with the same caching headers', function () {
        $owner = userWithAvatar();
        $viewer = User::factory()->create();
        $url = "/api/voice-client/v1/avatars/{$owner->id}/{$owner->avatar_hash}";

        $native = $this->get($url, accountBearer($viewer))->assertOk();
        $web = $this->actingAs($viewer)->get("/avatars/{$owner->id}/{$owner->avatar_hash}")->assertOk();

        expect($native->getContent())->toBe($web->getContent())
            ->and($native->headers->get('Cache-Control'))->toBe($web->headers->get('Cache-Control'))
            ->and($native->headers->get('ETag'))->toBe($web->headers->get('ETag'))
            ->and($native->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('is 404 for a wrong hash and for a revoked token 401', function () {
        $owner = userWithAvatar();
        $viewer = User::factory()->create();
        $headers = accountBearer($viewer);

        $this->get("/api/voice-client/v1/avatars/{$owner->id}/0123456789abcdef", $headers)->assertNotFound();

        VoiceClientToken::query()->where('user_id', $viewer->id)->update(['revoked_at' => now()]);
        $this->get("/api/voice-client/v1/avatars/{$owner->id}/{$owner->avatar_hash}", $headers)->assertUnauthorized();
    });
});

describe('the accessor', function () {
    it('is the local browser URL, or null without a local copy, never the CDN URL', function () {
        $owner = userWithAvatar();
        $plain = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/9/z.png']);

        expect($owner->avatar_src)->toBe("/avatars/{$owner->id}/{$owner->avatar_hash}")
            ->and($owner->nativeAvatarSrc())->toBe("/api/voice-client/v1/avatars/{$owner->id}/{$owner->avatar_hash}")
            ->and($plain->avatar_src)->toBeNull()
            ->and($plain->nativeAvatarSrc())->toBeNull();
    });

    it('never serialises the CDN URL or the storage path', function () {
        $owner = userWithAvatar();
        $json = json_encode($owner->toArray(), JSON_THROW_ON_ERROR);

        expect($json)->not->toContain('discordapp')
            ->and($json)->not->toContain('avatar_path')
            ->and($json)->toContain('avatar_src');
    });
});
