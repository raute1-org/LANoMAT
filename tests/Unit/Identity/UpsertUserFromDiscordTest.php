<?php

use App\Models\User;
use App\Modules\Identity\Actions\UpsertUserFromDiscord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fills user-owned fields (name, email) on first creation', function () {
    $user = (new UpsertUserFromDiscord)->handle('discord-1', 'FirstName', 'https://cdn.discordapp.com/a.png', 'a@example.com');

    expect($user->wasRecentlyCreated)->toBeTrue()
        ->and($user->name)->toBe('FirstName')
        ->and($user->email)->toBe('a@example.com')
        ->and($user->avatar_url)->toBe('https://cdn.discordapp.com/a.png')
        ->and($user->discord_id)->toBe('discord-1');
});

it('does not overwrite the user-owned name or email on relogin', function () {
    $existing = User::factory()->create([
        'discord_id' => 'discord-2',
        'name' => 'EditedByUser',
        'email' => 'kept@example.com',
    ]);

    $user = (new UpsertUserFromDiscord)->handle('discord-2', 'NameFromDiscord', 'https://cdn.discordapp.com/b.png', 'email-from-discord@example.com');

    expect($user->id)->toBe($existing->id)
        ->and($user->name)->toBe('EditedByUser')
        ->and($user->email)->toBe('kept@example.com');
});

it('refreshes the discord-owned avatar_url on relogin', function () {
    $existing = User::factory()->create([
        'discord_id' => 'discord-3',
        'avatar_url' => 'https://cdn.discordapp.com/old.png',
    ]);

    $user = (new UpsertUserFromDiscord)->handle('discord-3', 'Whatever', 'https://cdn.discordapp.com/new.png', null);

    expect($user->id)->toBe($existing->id)
        ->and($user->avatar_url)->toBe('https://cdn.discordapp.com/new.png');
});

it('leaves email null instead of crashing when it collides with another account on creation', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = (new UpsertUserFromDiscord)->handle('discord-4', 'NewUser', null, 'taken@example.com');

    expect($user->wasRecentlyCreated)->toBeTrue()
        ->and($user->email)->toBeNull();
});
