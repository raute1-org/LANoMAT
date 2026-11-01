<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function pairedDevice(User $user, string $name = 'Ada Desktop'): VoiceClientToken
{
    $token = new VoiceClientToken;
    $token->forceFill([
        'user_id' => $user->id,
        'device_name' => $name,
        'token_hash' => hash('sha256', $name.$user->id.random_int(1, PHP_INT_MAX)),
        'expires_at' => now()->addDays(90),
    ])->save();

    return $token;
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    $this->ada = User::factory()->create();
    $this->bob = User::factory()->create();
});

it('lists only my own active devices', function () {
    pairedDevice($this->ada, 'Ada Desktop');
    pairedDevice($this->bob, 'Bob Laptop');
    pairedDevice($this->ada, 'Old')->forceFill(['revoked_at' => now()])->save();
    pairedDevice($this->ada, 'Expired')->forceFill(['expires_at' => now()->subDay()])->save();

    $this->actingAs($this->ada)->get('/talk/connect')->assertInertia(
        fn ($p) => $p->has('devices', 1)->where('devices.0.name', 'Ada Desktop')
            ->missing('devices.0.token_hash'),
    );
});

it('lets me revoke my own device, which kills its bearer token', function () {
    $device = pairedDevice($this->ada);

    $this->actingAs($this->ada)->delete("/talk/devices/{$device->id}")->assertRedirect('/talk/connect');

    expect($device->fresh()->revoked_at)->not->toBeNull();
});

it('forbids revoking another users device', function () {
    $device = pairedDevice($this->bob);

    $this->actingAs($this->ada)->delete("/talk/devices/{$device->id}")->assertForbidden();

    expect($device->fresh()->revoked_at)->toBeNull();
});

it('lets an admin revoke any device through the app-wide admin pass', function () {
    $device = pairedDevice($this->bob);

    $this->actingAs(User::factory()->create(['role' => Role::Admin]))->delete("/talk/devices/{$device->id}")->assertRedirect();

    expect($device->fresh()->revoked_at)->not->toBeNull();
});

it('sends guests to the login when they try to revoke', function () {
    $device = pairedDevice($this->bob);

    $this->delete("/talk/devices/{$device->id}")->assertRedirect('/login');
    expect($device->fresh()->revoked_at)->toBeNull();
});

it('keeps revoking idempotent', function () {
    $device = pairedDevice($this->ada);
    $this->actingAs($this->ada)->delete("/talk/devices/{$device->id}");
    $first = $device->fresh()->revoked_at;

    Carbon::setTestNow(now()->addHour());
    $this->delete("/talk/devices/{$device->id}")->assertRedirect();

    expect($device->fresh()->revoked_at?->equalTo($first))->toBeTrue();
});

it('links the pairing page from the talk page', function () {
    $this->actingAs($this->ada)->get('/talk')->assertOk();
    $this->get('/talk/connect')->assertOk();
});
