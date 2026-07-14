<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function fakeDiscordUser(string $id = '123456789', string $name = 'TestUser'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->map([
        'id' => $id,
        'nickname' => $name,
        'name' => $name,
        'email' => 'test@example.com',
        'avatar' => 'https://cdn.discordapp.com/avatars/123/abc.png',
    ]);

    return $user;
}

it('redirects to discord', function () {
    $response = $this->get('/auth/discord/redirect');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('discord.com');
})->skip(fn () => empty(config('services.discord.client_id')), 'needs client id in env');

it('creates a participant user on first discord login', function () {
    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser());

    $this->get('/auth/discord/callback')->assertRedirect('/');

    $user = User::where('discord_id', '123456789')->firstOrFail();
    expect($user->role)->toBe(Role::Participant)
        ->and($user->name)->toBe('TestUser')
        ->and($user->avatar_url)->toContain('cdn.discordapp.com');
    $this->assertAuthenticatedAs($user);
});

it('reuses the existing user and keeps their role on relogin', function () {
    $existing = User::factory()->admin()->create(['discord_id' => '123456789']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser(name: 'RenamedUser'));

    $this->get('/auth/discord/callback');

    expect(User::count())->toBe(1)
        ->and($existing->refresh()->role)->toBe(Role::Admin)
        ->and($existing->name)->toBe('RenamedUser');
});

it('processes the callback even when a user is already authenticated', function () {
    $loggedInUser = User::factory()->create(['discord_id' => '111111111']);
    $this->actingAs($loggedInUser);

    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser(id: '222222222', name: 'OtherUser'));

    $response = $this->get('/auth/discord/callback');

    $response->assertRedirect('/');

    $newUser = User::where('discord_id', '222222222')->firstOrFail();
    $this->assertAuthenticatedAs($newUser);
    expect(User::count())->toBe(2);
});

it('redirects to login without creating a user when consent is denied', function () {
    $response = $this->get('/auth/discord/callback?error=access_denied&error_description=The+resource+owner+denied+the+request.');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('redirects to login when the oauth state is invalid or expired', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $response = $this->get('/auth/discord/callback');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});
