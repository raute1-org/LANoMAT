<?php

use App\Models\User;

it('reports has_password as false for discord-only users without a password', function () {
    $user = User::factory()->make(['password' => null]);

    expect($user->has_password)->toBeFalse()
        ->and($user->toArray())->toHaveKey('has_password', false);
});

it('reports has_password as true once a password is set', function () {
    $user = User::factory()->make(['password' => 'hashed-secret']);

    expect($user->has_password)->toBeTrue();
});
