<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('reports can_access_admin for orga and admin only, not helper or participant', function () {
    expect(User::factory()->create(['role' => Role::Admin])->can_access_admin)->toBeTrue()
        ->and(User::factory()->create(['role' => Role::Orga])->can_access_admin)->toBeTrue()
        ->and(User::factory()->create(['role' => Role::Helper])->can_access_admin)->toBeFalse()
        ->and(User::factory()->create(['role' => Role::Participant])->can_access_admin)->toBeFalse();
});

it('serializes can_access_admin into the user array', function () {
    expect(User::factory()->create(['role' => Role::Orga])->toArray())
        ->toHaveKey('can_access_admin', true);
});

it('shares can_access_admin with the frontend auth prop', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]))
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.can_access_admin', true)
        );

    $this->actingAs(User::factory()->create(['role' => Role::Participant]))
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.can_access_admin', false)
        );
});
