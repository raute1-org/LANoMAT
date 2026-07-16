<?php

use App\Models\User;
use App\Modules\Games\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids participants from the games resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/games')
        ->assertForbidden();
});

it('forbids helpers from the games resource', function () {
    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin/games')
        ->assertForbidden();
});

it('allows orga into the games resource and renders the list', function () {
    Game::factory()->create(['name' => 'Counter-Strike 2']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/games')
        ->assertOk()
        ->assertSee('Counter-Strike 2');
});
