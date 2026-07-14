<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a random hex profile_color on creation when none is given', function () {
    $user = User::factory()->create(['profile_color' => null]);

    expect($user->profile_color)->toMatch('/^#[0-9a-fA-F]{6}$/');
});

it('keeps an explicitly provided profile_color', function () {
    $user = User::factory()->create(['profile_color' => '#abcdef']);

    expect($user->profile_color)->toBe('#abcdef');
});

it('assigns different colors to different users (not a constant)', function () {
    $colors = collect(range(1, 20))
        ->map(fn () => User::factory()->create(['profile_color' => null])->profile_color)
        ->unique();

    // extremely unlikely to collide into a single value across 20 users
    expect($colors->count())->toBeGreaterThan(1);
});
