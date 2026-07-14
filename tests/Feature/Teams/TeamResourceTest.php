<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists teams for orga', function () {
    Team::factory()->create(['name' => 'Alpha Squad', 'tag' => 'ALP']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/teams')
        ->assertOk()
        ->assertSee('Alpha Squad');
});

it('forbids participants from the teams resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/teams')
        ->assertForbidden();
});

it('shows the german column label', function () {
    Team::factory()->create();

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/teams')
        ->assertOk()
        ->assertSee('Kapitän'); // i18n gate — owner column label
});
