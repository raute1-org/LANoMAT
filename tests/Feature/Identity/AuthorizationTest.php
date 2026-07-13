<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('/_test/orga-only', fn () => 'ok')->middleware(['web', 'auth', 'role:orga']);
});

it('blocks participants from orga routes', function () {
    $this->actingAs(User::factory()->create())
        ->get('/_test/orga-only')
        ->assertForbidden();
});

it('allows orga and admin on orga routes', function (string $factory) {
    $this->actingAs(User::factory()->{$factory}()->create())
        ->get('/_test/orga-only')
        ->assertOk();
})->with(['orga', 'admin']);
