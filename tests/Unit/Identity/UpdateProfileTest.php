<?php

use App\Models\User;
use App\Modules\Identity\Actions\UpdateProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates whitelisted profile fields', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $updated = (new UpdateProfile)->handle($user, [
        'name' => 'New Name',
        'bio' => 'Hello LAN',
        'steam_url' => 'https://steamcommunity.com/id/example',
        'profile_color' => '#112233',
        'stream_url' => 'https://twitch.tv/newname',
    ]);

    expect($updated->name)->toBe('New Name')
        ->and($updated->bio)->toBe('Hello LAN')
        ->and($updated->steam_url)->toBe('https://steamcommunity.com/id/example')
        ->and($updated->profile_color)->toBe('#112233')
        ->and($updated->stream_url)->toBe('https://twitch.tv/newname');
});

it('resets email verification when the email changes', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    expect($user->email_verified_at)->not->toBeNull();

    (new UpdateProfile)->handle($user, ['email' => 'new@example.com']);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('keeps email verification when the email is unchanged', function () {
    $user = User::factory()->create(['email' => 'same@example.com']);

    (new UpdateProfile)->handle($user, ['email' => 'same@example.com']);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});
