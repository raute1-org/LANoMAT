<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks participants from the admin panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('allows orga and admin into the admin panel', function (string $factory) {
    $this->actingAs(User::factory()->{$factory}()->create())
        ->get('/admin')
        ->assertOk();
})->with(['orga', 'admin']);

it('sends guests to the app login, not a filament login', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});
