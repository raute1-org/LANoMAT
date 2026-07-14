<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('shows a public profile without leaking private fields', function () {
    $user = User::factory()->create([
        'name' => 'Gamer',
        'bio' => 'GG',
        'email' => 'secret@example.com',
    ]);

    $this->get("/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Profile/Show')
            ->where('profile.name', 'Gamer')
            ->where('profile.bio', 'GG')
            ->missing('profile.email')
            ->missing('profile.discordId')
            ->missing('profile.role')
        );
});

it('returns 404 for an unknown user', function () {
    $this->get('/users/999999')->assertNotFound();
});
