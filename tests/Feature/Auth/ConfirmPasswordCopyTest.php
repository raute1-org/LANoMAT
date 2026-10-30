<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('answers a wrong password confirmation in german', function () {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->actingAs($user)
        ->postJson('/user/confirm-password', ['password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password'])
        ->assertJsonPath('errors.password.0', 'Das eingegebene Passwort ist falsch.');
});
