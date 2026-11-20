<?php

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use App\Modules\Identity\Actions\ChangeUserRole;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function roleUser(Role $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

function changeRole(User $actor, User $target, Role $to)
{
    return Livewire::actingAs($actor)->test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->callAction('changeRole', ['role' => $to->value]);
}

it('lets an admin promote a participant to admin', function () {
    $admin = roleUser(Role::Admin);
    $target = roleUser(Role::Participant);

    changeRole($admin, $target, Role::Admin)->assertNotified(__('users.change_role.success'));

    expect($target->fresh()->role)->toBe(Role::Admin);
});

it('lets an admin demote an orga to helper and shows the new role', function () {
    $admin = roleUser(Role::Admin);
    $target = roleUser(Role::Orga);

    changeRole($admin, $target, Role::Helper)->assertSee(Role::Helper->label());

    expect($target->fresh()->role)->toBe(Role::Helper);
});

it('rejects an admin changing their own role', function () {
    $admin = roleUser(Role::Admin);
    roleUser(Role::Admin);

    changeRole($admin, $admin, Role::Participant)->assertNotified(__('users.change_role.failed'));

    expect($admin->fresh()->role)->toBe(Role::Admin);
});

it('never demotes the last admin', function () {
    $last = roleUser(Role::Admin);
    $actor = roleUser(Role::Orga);

    expect(fn () => app(ChangeUserRole::class)->handle($actor, $last, Role::Participant))
        ->toThrow(AuthorizationException::class);

    // an admin acting on the only other admin is fine, but the sole admin cannot be reached by a non-self actor;
    // simulate the guard directly by authorizing as an admin that is being demoted by a stale second admin.
    $second = roleUser(Role::Admin);
    app(ChangeUserRole::class)->handle($second, $last, Role::Participant);
    expect($last->fresh()->role)->toBe(Role::Participant);

    $stale = $last->fresh();
    $stale->forceFill(['role' => Role::Admin]); // actor passes the gate in memory only
    expect(fn () => app(ChangeUserRole::class)->handle($stale, $second, Role::Participant))
        ->toThrow(IdentityException::class, 'last remaining admin');
    expect($second->fresh()->role)->toBe(Role::Admin);
});

it('hides and forbids the action for non-admins', function (Role $role) {
    $actor = roleUser($role);
    $target = roleUser(Role::Participant);

    Livewire::actingAs($actor)->test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->assertActionHidden('changeRole');

    expect(fn () => app(ChangeUserRole::class)->handle($actor, $target, Role::Admin))
        ->toThrow(AuthorizationException::class);
    expect($target->fresh()->role)->toBe(Role::Participant);
})->with([Role::Orga, Role::Helper, Role::Participant]);

it('does not mass assign the role', function () {
    $user = User::factory()->create();
    $user->fill(['role' => Role::Admin->value])->save();

    expect($user->fresh()->role)->toBe(Role::Participant);
});
