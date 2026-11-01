<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMute;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    config([
        'webvoice.url' => 'wss://voice.example.test',
        'webvoice.api_key' => 'k',
        'webvoice.api_secret' => 'a-long-enough-secret-for-testing-0123456789',
        'webvoice.audio.jitter_buffer_target_ms' => 40,
    ]);
    $this->fake = new FakeWebVoiceClient;
    $this->app->instance(WebVoiceClient::class, $this->fake);

    $this->lobby = WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->teamA = WebVoiceChannel::factory()->match()->create(['name' => 'Team A']);
    $this->teamB = WebVoiceChannel::factory()->match()->create(['name' => 'Team B']);
    $this->archived = WebVoiceChannel::factory()->archived()->create(['name' => 'Alt']);

    $this->ada = User::factory()->create(['name' => 'Ada']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->teamA->members()->attach($this->ada);
    $this->teamB->members()->attach($this->bob);
});

/** @return array<string, string> */
function bearer(User $user): array
{
    $plain = 'lvc_'.Str::random(48);
    $token = new VoiceClientToken;
    $token->forceFill([
        'user_id' => $user->id,
        'device_name' => 'Test PC',
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addDays(90),
    ])->save();

    test()->lastToken = $token;

    return ['Authorization' => 'Bearer '.$plain];
}

it('rejects missing, malformed, unknown, revoked and expired bearer tokens with 401', function () {
    $this->getJson('/api/voice-client/v1/me')->assertUnauthorized();
    $this->getJson('/api/voice-client/v1/me', ['Authorization' => 'Basic abc'])->assertUnauthorized();
    $this->getJson('/api/voice-client/v1/me', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

    $headers = bearer($this->ada);
    $this->getJson('/api/voice-client/v1/me', $headers)->assertOk();

    $this->lastToken->forceFill(['revoked_at' => now()])->save();
    $this->getJson('/api/voice-client/v1/me', $headers)->assertUnauthorized();

    $headers = bearer($this->ada);
    $this->lastToken->forceFill(['expires_at' => now()->subSecond()])->save();
    $this->getJson('/api/voice-client/v1/me', $headers)->assertUnauthorized();
});

it('stops working when the user is deleted', function () {
    $headers = bearer($this->bob);
    $this->bob->delete();

    expect(VoiceClientToken::query()->count())->toBe(0);
    $this->getJson('/api/voice-client/v1/me', $headers)->assertUnauthorized();
});

it('updates last_used_at and slides the expiry', function () {
    $headers = bearer($this->ada);
    expect($this->lastToken->last_used_at)->toBeNull();

    Carbon::setTestNow(now()->addDays(10));
    $this->getJson('/api/voice-client/v1/me', $headers)->assertOk();

    $this->lastToken->refresh();
    expect($this->lastToken->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($this->lastToken->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

it('does not let a bearer token authenticate any web route', function () {
    $headers = bearer($this->ada);

    $this->get('/talk', $headers)->assertRedirect('/login');
    $this->get('/dashboard', $headers)->assertRedirect('/login');
    $this->postJson("/talk/channels/{$this->lobby->id}/token", [], $headers)->assertUnauthorized();
    $this->getJson('/talk/connect', $headers)->assertUnauthorized();
});

it('does not let a session authenticate the client API', function () {
    $this->actingAs($this->ada)->getJson('/api/voice-client/v1/me')->assertUnauthorized();
});

it('serves the client API without session cookies', function () {
    $response = $this->getJson('/api/voice-client/v1/me', bearer($this->ada))->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

it('returns the caller from me', function () {
    $this->getJson('/api/voice-client/v1/me', bearer($this->ada))->assertOk()
        ->assertExactJson(['id' => $this->ada->id, 'name' => 'Ada', 'role' => 'participant', 'identity' => 'u'.$this->ada->id]);
});

it('lists exactly the rooms the browser lists', function () {
    $names = fn (User $u) => collect($this->getJson('/api/voice-client/v1/rooms', bearer($u))->assertOk()->json('rooms'))->pluck('name')->all();

    expect($names($this->ada))->toBe(['Lobby', 'Team A'])
        ->and($names($this->bob))->toBe(['Lobby', 'Team B'])
        ->and($names($this->admin))->toBe(['Lobby', 'Team A', 'Team B']);

    // Parity with the browser page for the same users.
    foreach ([$this->ada, $this->bob, $this->admin] as $user) {
        $browser = collect($this->actingAs($user)->get('/talk')->viewData('page')['props']['channels'])->pluck('id')->all();
        $api = collect($this->getJson('/api/voice-client/v1/rooms', bearer($user))->json('rooms'))->pluck('id')->all();
        expect($api)->toBe($browser);
        auth()->logout();
    }
});

it('issues a join token with the browser rules', function () {
    $response = $this->postJson("/api/voice-client/v1/rooms/{$this->teamA->id}/token", ['identity' => 'u999'], bearer($this->ada))
        ->assertOk()
        ->assertJsonPath('url', 'wss://voice.example.test')
        ->assertJsonPath('identity', 'u'.$this->ada->id)
        ->assertJsonPath('muted', false);

    expect($response->json('token'))->toBeString()->not->toBe('');
});

it('carries a moderator mute into the token exactly like the browser', function () {
    WebVoiceMute::query()->forceCreate(['web_voice_channel_id' => $this->teamA->id, 'user_id' => $this->ada->id, 'muted_by' => $this->admin->id]);

    $this->postJson("/api/voice-client/v1/rooms/{$this->teamA->id}/token", [], bearer($this->ada))
        ->assertOk()->assertJsonPath('muted', true);
    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->teamA->id}/token")->assertJsonPath('muted', true);

    // Both paths asked the SFU for a token without the publish right (canPublish = false).
    expect(collect($this->fake->issued)->pluck('canPublish')->all())->toBe([false, false]);
});

it('answers 404 for a room the caller may not see and for a missing room, never 403', function () {
    $headers = bearer($this->ada);

    $this->postJson("/api/voice-client/v1/rooms/{$this->teamB->id}/token", [], $headers)->assertNotFound();
    $this->postJson('/api/voice-client/v1/rooms/999999/token', [], $headers)->assertNotFound();
    $this->postJson("/api/voice-client/v1/rooms/{$this->archived->id}/token", [], $headers)->assertNotFound();
});

it('lets an admin join any live room but not an archived one', function () {
    $headers = bearer($this->admin);

    $this->postJson("/api/voice-client/v1/rooms/{$this->teamB->id}/token", [], $headers)->assertOk();
    $this->postJson("/api/voice-client/v1/rooms/{$this->archived->id}/token", [], $headers)->assertStatus(410);
});

it('reports 503 when the voice server is not configured', function () {
    config(['webvoice.url' => '']);

    $this->postJson("/api/voice-client/v1/rooms/{$this->lobby->id}/token", [], bearer($this->ada))->assertStatus(503);
    $this->getJson('/api/voice-client/v1/config', bearer($this->ada))->assertOk()->assertJsonPath('voice_ready', false)->assertJsonPath('livekit_url', null);
});

it('serves client config without secrets', function () {
    $response = $this->getJson('/api/voice-client/v1/config', bearer($this->ada))->assertOk()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('livekit_url', 'wss://voice.example.test')
        ->assertJsonPath('audio.jitter_buffer_target_ms', 40)
        ->assertJsonPath('audio.bitrate', 24000);

    expect($response->getContent())->not->toContain('a-long-enough-secret')->not->toContain('api_key')->not->toContain('api_secret');
});

it('has no chat endpoints in v1', function () {
    $headers = bearer($this->ada);

    $this->getJson("/api/voice-client/v1/rooms/{$this->lobby->id}/messages", $headers)->assertNotFound();
    $this->postJson("/api/voice-client/v1/rooms/{$this->lobby->id}/messages", ['body' => 'x'], $headers)->assertNotFound();
});

it('rate limits the room token endpoint per user', function () {
    $headers = bearer($this->ada);

    for ($i = 0; $i < 30; $i++) {
        $this->postJson("/api/voice-client/v1/rooms/{$this->lobby->id}/token", [], $headers)->assertOk();
    }

    $this->postJson("/api/voice-client/v1/rooms/{$this->lobby->id}/token", [], $headers)->assertStatus(429);
});
