<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientDeviceCode;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
});

function startDevice(?string $name = 'Ada Desktop'): array
{
    return test()->postJson('/api/voice-client/device', ['device_name' => $name])->assertOk()->json();
}

function pollToken(string $deviceCode)
{
    return test()->postJson('/api/voice-client/token', ['device_code' => $deviceCode]);
}

it('issues a device code, a short user code and a verification url without a session', function () {
    $body = startDevice();

    expect($body)->toHaveKeys(['device_code', 'user_code', 'verification_url', 'expires_in', 'interval'])
        ->and($body['expires_in'])->toBe(600)
        ->and($body['interval'])->toBe(5)
        ->and($body['verification_url'])->toEndWith('/talk/connect')
        ->and($body['user_code'])->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/');
});

it('stores only digests of the device code and the user code', function () {
    $body = startDevice();
    $row = VoiceClientDeviceCode::query()->firstOrFail();
    $raw = json_encode(DB::table('voice_client_device_codes')->first(), JSON_THROW_ON_ERROR);

    expect($row->device_code_hash)->toBe(hash('sha256', $body['device_code']))
        ->and($row->user_code_hash)->toBe(hash('sha256', str_replace('-', '', $body['user_code'])))
        ->and($raw)->not->toContain($body['device_code'])
        ->and($raw)->not->toContain(str_replace('-', '', $body['user_code']));
});

it('generates user codes without ambiguous characters', function () {
    for ($i = 0; $i < 200; $i++) {
        expect(DeviceCodes::userCode())->toMatch('/^[^01OIL]{8}$/');
    }
});

it('normalizes typed user codes and rejects malformed ones', function () {
    expect(DeviceCodes::normalize('abcd-efgh'))->toBe('ABCDEFGH')
        ->and(DeviceCodes::normalize(' ab cd ef gh '))->toBe('ABCDEFGH')
        ->and(DeviceCodes::normalize('ABCD-EFG'))->toBeNull()
        ->and(DeviceCodes::normalize('0O1I-LLLL'))->toBeNull();
});

it('sanitizes the device name', function () {
    startDevice("Evil\n\tName".str_repeat('x', 100));

    $name = VoiceClientDeviceCode::query()->firstOrFail()->device_name;
    expect(mb_strlen($name))->toBeLessThanOrEqual(60)->and($name)->not->toMatch('/[\x00-\x1F]/');

    VoiceClientDeviceCode::query()->delete();
    startDevice(null);
    expect(VoiceClientDeviceCode::query()->firstOrFail()->device_name)->toBe('Unbenanntes Gerät');
});

it('answers authorization_pending while nobody has decided', function () {
    $body = startDevice();

    pollToken($body['device_code'])->assertStatus(400)->assertJson(['error' => 'authorization_pending']);
});

it('answers slow_down with a longer interval when the client polls too fast', function () {
    $body = startDevice();

    pollToken($body['device_code'])->assertJson(['error' => 'authorization_pending']);
    pollToken($body['device_code'])->assertStatus(400)->assertJson(['error' => 'slow_down', 'interval' => 10]);

    // Still inside the (now longer) interval: slows down again.
    Carbon::setTestNow(now()->addSeconds(6));
    pollToken($body['device_code'])->assertJson(['error' => 'slow_down', 'interval' => 15]);

    Carbon::setTestNow(now()->addSeconds(16));
    pollToken($body['device_code'])->assertJson(['error' => 'authorization_pending']);
});

it('walks the whole flow: approve, then one token, then the code is spent', function () {
    $user = User::factory()->create();
    $body = startDevice('Ada Desktop');

    pollToken($body['device_code'])->assertJson(['error' => 'authorization_pending']);

    $this->actingAs($user)->post('/talk/connect', ['code' => $body['user_code'], 'decision' => 'approve'])
        ->assertRedirect('/talk/connect');
    auth()->logout();

    Carbon::setTestNow(now()->addSeconds(6));
    $response = pollToken($body['device_code'])->assertOk()->assertJsonPath('token_type', 'Bearer');
    $plain = $response->json('access_token');

    expect($plain)->toStartWith('lvc_')
        ->and($response->json('expires_in'))->toBe(90 * 86400);

    $token = VoiceClientToken::query()->firstOrFail();
    expect($token->user_id)->toBe($user->id)
        ->and($token->device_name)->toBe('Ada Desktop')
        ->and($token->token_hash)->toBe(hash('sha256', $plain));

    // The request is consumed: a second exchange and a second approval both fail.
    Carbon::setTestNow(now()->addSeconds(6));
    pollToken($body['device_code'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    expect(VoiceClientToken::query()->count())->toBe(1);
});

it('never stores or logs the plain bearer token', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    $user = User::factory()->create();
    $body = startDevice();
    $this->actingAs($user)->post('/talk/connect', ['code' => $body['user_code'], 'decision' => 'approve']);
    auth()->logout();
    Carbon::setTestNow(now()->addSeconds(6));
    $plain = pollToken($body['device_code'])->assertOk()->json('access_token');

    $stored = json_encode(DB::table('voice_client_tokens')->get(), JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain($plain)
        ->and(json_encode(VoiceClientToken::query()->firstOrFail()->toArray()))->not->toContain($plain)
        ->and(implode("\n", $logged))->not->toContain($plain)->not->toContain($body['device_code']);
});

it('answers access_denied after the user denies, and removes the request', function () {
    $user = User::factory()->create();
    $body = startDevice();

    $this->actingAs($user)->post('/talk/connect', ['code' => $body['user_code'], 'decision' => 'deny'])->assertRedirect('/talk/connect');
    auth()->logout();

    Carbon::setTestNow(now()->addSeconds(6));
    pollToken($body['device_code'])->assertStatus(400)->assertJson(['error' => 'access_denied']);
    expect(VoiceClientToken::query()->count())->toBe(0)->and(VoiceClientDeviceCode::query()->count())->toBe(0);
});

it('answers expired_token after the ten minutes and cannot be approved any more', function () {
    $user = User::factory()->create();
    $body = startDevice();

    Carbon::setTestNow(now()->addSeconds(601));

    $this->actingAs($user)->post('/talk/connect', ['code' => $body['user_code'], 'decision' => 'approve'])->assertRedirect();
    auth()->logout();
    pollToken($body['device_code'])->assertStatus(400)->assertJson(['error' => 'expired_token']);
    expect(VoiceClientToken::query()->count())->toBe(0);
});

it('answers invalid_grant for an unknown device code', function () {
    pollToken(str_repeat('a', 48))->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
});

it('validates the inputs', function () {
    $this->postJson('/api/voice-client/token', [])->assertStatus(422);
    $this->postJson('/api/voice-client/token', ['device_code' => ['x']])->assertStatus(422);
    $this->postJson('/api/voice-client/device', ['device_name' => str_repeat('a', 500)])->assertStatus(422);
});

it('rate limits the unauthenticated device endpoint per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/voice-client/device')->assertOk();
    }

    $this->postJson('/api/voice-client/device')->assertStatus(429);
});

it('rate limits the token endpoint per device code and per IP', function () {
    $body = startDevice();
    $statuses = [];

    for ($i = 0; $i < 32; $i++) {
        $statuses[] = pollToken($body['device_code'])->status();
    }

    expect($statuses[29])->toBe(400)->and($statuses[30])->toBe(429);

    // A different code from another address is not affected by that code's bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/voice-client/token', ['device_code' => str_repeat('b', 48)])
        ->assertStatus(400);
});

it('rate limits approvals per user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($i = 0; $i < 10; $i++) {
        $this->post('/talk/connect', ['code' => 'ABCD-EFGH', 'decision' => 'approve'])->assertRedirect();
    }

    $this->post('/talk/connect', ['code' => 'ABCD-EFGH', 'decision' => 'approve'])->assertStatus(429);
});

it('requires a login for the approval page and shows each of its states', function () {
    $this->get('/talk/connect')->assertRedirect('/login');
    $this->post('/talk/connect', ['code' => 'ABCD-EFGH', 'decision' => 'approve'])->assertRedirect('/login');

    $user = User::factory()->create();
    $body = startDevice('Ada Desktop');

    $this->actingAs($user)->get('/talk/connect')
        ->assertInertia(fn ($p) => $p->component('WebVoice/Connect')->where('state', 'empty')->where('devices', []));

    $this->get('/talk/connect?code='.$body['user_code'])
        ->assertInertia(fn ($p) => $p->where('state', 'pending')->where('deviceName', 'Ada Desktop')->where('code', $body['user_code']));

    $this->get('/talk/connect?code=ZZZZ-ZZZZ')->assertInertia(fn ($p) => $p->where('state', 'invalid'));
});

it('treats a decided code as used', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $body = startDevice();

    $this->actingAs($user)->post('/talk/connect', ['code' => $body['user_code'], 'decision' => 'approve']);
    $this->actingAs($other)->post('/talk/connect', ['code' => $body['user_code'], 'decision' => 'approve']);

    expect(VoiceClientDeviceCode::query()->firstOrFail()->user_id)->toBe($user->id);
    $this->actingAs($other)->get('/talk/connect?code='.$body['user_code'])->assertInertia(fn ($p) => $p->where('state', 'invalid'));
});

it('validates the approval form', function () {
    $this->actingAs(User::factory()->create())
        ->post('/talk/connect', ['code' => '', 'decision' => 'maybe'])
        ->assertSessionHasErrors(['code', 'decision']);
});

it('keeps the approval form inside the CSRF-protected web group while the device endpoints stay cookie-less', function () {
    $mw = fn (string $name) => Route::getRoutes()->getByName($name)->gatherMiddleware();

    expect($mw('webvoice.connect.decide'))->toContain('web')
        ->and($mw('voice-client.device'))->not->toContain('web')
        ->and($mw('voice-client.token'))->not->toContain('web');
});

it('prunes spent pairing requests and dead tokens', function () {
    $user = User::factory()->create();
    startDevice();
    VoiceClientDeviceCode::query()->update(['expires_at' => now()->subDays(2)]);
    $dead = new VoiceClientToken;
    $dead->forceFill(['user_id' => $user->id, 'device_name' => 'x', 'token_hash' => hash('sha256', 'a'), 'expires_at' => now()->subDays(40)])->save();
    $live = new VoiceClientToken;
    $live->forceFill(['user_id' => $user->id, 'device_name' => 'y', 'token_hash' => hash('sha256', 'b'), 'expires_at' => now()->addDay()])->save();

    $this->artisan('webvoice:prune')->assertSuccessful();

    expect(VoiceClientDeviceCode::query()->count())->toBe(0)
        ->and(VoiceClientToken::query()->pluck('device_name')->all())->toBe(['y']);
});
