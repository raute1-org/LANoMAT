<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports is_staff true for helper-or-above, false for participants', function () {
    expect(User::factory()->create(['role' => Role::Admin])->is_staff)->toBeTrue()
        ->and(User::factory()->create(['role' => Role::Orga])->is_staff)->toBeTrue()
        ->and(User::factory()->create(['role' => Role::Helper])->is_staff)->toBeTrue()
        ->and(User::factory()->create(['role' => Role::Participant])->is_staff)->toBeFalse();
});

it('serializes is_staff into the user array', function () {
    expect(User::factory()->create(['role' => Role::Orga])->toArray())
        ->toHaveKey('is_staff', true);
});
