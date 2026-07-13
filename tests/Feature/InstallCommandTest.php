<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an admin user from a discord id', function () {
    $this->artisan('lanomat:install', ['--admin-discord-id' => '42', '--admin-name' => 'Boss'])
        ->assertSuccessful();

    expect(User::where('discord_id', '42')->firstOrFail()->role)->toBe(Role::Admin);
});

it('promotes an existing user instead of duplicating', function () {
    User::factory()->create(['discord_id' => '42']);

    $this->artisan('lanomat:install', ['--admin-discord-id' => '42'])->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(User::firstOrFail()->role)->toBe(Role::Admin);
});
