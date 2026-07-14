<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects duplicate discord_id at the database level', function () {
    User::factory()->create(['discord_id' => 'duplicate-id']);

    User::factory()->create(['discord_id' => 'duplicate-id']);
})->throws(QueryException::class);

it('allows creating a user with null email and null password', function () {
    $user = User::factory()->create([
        'email' => null,
        'password' => null,
        'discord_id' => 'nullable-fields-user',
    ]);

    expect($user->refresh())
        ->email->toBeNull()
        ->password->toBeNull();
});
