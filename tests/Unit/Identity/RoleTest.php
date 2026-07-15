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

it('labels the helper role in German', function () {
    expect(Role::Helper->label())->toBe('Helfer');
});

it('grants helper-or-above semantics via isHelper without granting orga', function () {
    $admin = User::factory()->make(['role' => Role::Admin]);
    $orga = User::factory()->make(['role' => Role::Orga]);
    $helper = User::factory()->make(['role' => Role::Helper]);
    $participant = User::factory()->make(['role' => Role::Participant]);

    expect($admin->isHelper())->toBeTrue()
        ->and($orga->isHelper())->toBeTrue()
        ->and($helper->isHelper())->toBeTrue()
        ->and($helper->isOrga())->toBeFalse()
        ->and($helper->isAdmin())->toBeFalse()
        ->and($participant->isHelper())->toBeFalse();
});
