<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/PresenceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
});

it('shares the location by default', function () {
    expect($this->user->fresh()->share_location)->toBeTrue();
});

it('is editable in the web settings, and an unchecked box (the hidden 0) switches it off', function () {
    $this->actingAs($this->user)->patch(route('profile.update'), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'share_location' => '0',
    ])->assertSessionHasNoErrors();

    expect($this->user->fresh()->share_location)->toBeFalse();

    $this->actingAs($this->user)->patch(route('profile.update'), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'share_location' => '1',
    ])->assertSessionHasNoErrors();

    expect($this->user->fresh()->share_location)->toBeTrue();
});

it('leaves the setting alone when a form does not send it', function () {
    $this->user->update(['share_location' => false]);

    $this->actingAs($this->user)->patch(route('profile.update'), ['name' => 'Ada B.', 'email' => 'ada@example.test'])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->share_location)->toBeFalse();
});

it('rejects a value that is not a boolean in the web settings', function () {
    $this->actingAs($this->user)->patch(route('profile.update'), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'share_location' => 'maybe',
    ])->assertSessionHasErrors('share_location');
});

it('passes the current value to the web settings page', function () {
    $this->user->update(['share_location' => false]);

    $this->actingAs($this->user)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('profile.shareLocation', false));
});

it('is part of the bearer profile and editable through it', function () {
    $headers = playBearer($this->user);

    $this->getJson('/api/voice-client/v1/profile', $headers)->assertOk()
        ->assertJsonPath('profile.share_location', true)
        ->assertJsonFragment(['editable' => ['name', 'email', 'bio', 'steam_url', 'stream_url', 'profile_color', 'share_location']]);

    $this->patchJson('/api/voice-client/v1/profile', ['share_location' => false], $headers)->assertOk()
        ->assertJsonPath('profile.share_location', false);
    expect($this->user->fresh()->share_location)->toBeFalse();

    $this->putJson('/api/voice-client/v1/profile', ['name' => 'Ada', 'email' => 'ada@example.test', 'share_location' => true], $headers)->assertOk()
        ->assertJsonPath('profile.share_location', true);
});

it('validates the setting through the bearer API and never changes another user', function () {
    $other = User::factory()->create();
    $headers = playBearer($this->user);

    $this->patchJson('/api/voice-client/v1/profile', ['share_location' => 'maybe'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('share_location');

    $this->patchJson('/api/voice-client/v1/profile', ['share_location' => false, 'id' => $other->id, 'user_id' => $other->id], $headers)->assertOk();

    expect($other->fresh()->share_location)->toBeTrue()->and($this->user->fresh()->share_location)->toBeFalse();
});
