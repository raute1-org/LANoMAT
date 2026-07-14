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

it('reuses the existing user, keeps their role and their user-owned name on relogin', function () {
    // Deliberate behaviour change (M0 whole-branch review, mandatory addition 1):
    // `name` is user-owned once set. Discord relogin must NOT clobber a name the
    // user (or a prior login) already established — only Discord-owned fields
    // (avatar_url) are refreshed on relogin. Previously this test asserted the
    // opposite (name overwritten from Discord on every login).
    $existing = User::factory()->admin()->create(['discord_id' => '123456789', 'name' => 'EditedName']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser(name: 'RenamedUser'));

    $this->get('/auth/discord/callback');

    expect(User::count())->toBe(1)
        ->and($existing->refresh()->role)->toBe(Role::Admin)
        ->and($existing->name)->toBe('EditedName');
});

it('updates the discord-owned avatar_url on relogin', function () {
    $existing = User::factory()->create([
        'discord_id' => '123456789',
        'avatar_url' => 'https://cdn.discordapp.com/avatars/123/old.png',
    ]);
    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser());

    $this->get('/auth/discord/callback');

    expect($existing->refresh()->avatar_url)->toContain('abc.png');
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

it('creates a new discord user with a null email instead of a 500 when the email collides', function () {
    User::factory()->create(['discord_id' => '999999999', 'email' => 'test@example.com']);

    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser(id: '123456789', name: 'SecondUser'));

    $response = $this->get('/auth/discord/callback');

    $response->assertRedirect('/');
    $newUser = User::where('discord_id', '123456789')->firstOrFail();
    expect($newUser->email)->toBeNull();
    $this->assertAuthenticatedAs($newUser);
});
