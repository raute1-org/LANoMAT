<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
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
