<?php

use App\Enums\Role;
use App\Models\User;

it('grants orga capabilities to admins', function () {
    $admin = User::factory()->make(['role' => Role::Admin]);
    $orga = User::factory()->make(['role' => Role::Orga]);
    $participant = User::factory()->make(['role' => Role::Participant]);

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->isOrga())->toBeTrue()
        ->and($orga->isAdmin())->toBeFalse()
        ->and($orga->isOrga())->toBeTrue()
        ->and($participant->isOrga())->toBeFalse();
});
