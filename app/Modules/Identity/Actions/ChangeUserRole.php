<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * An admin assigns a role to another user. `users.role` is not mass assignable,
 * so it is written with forceFill. Guards: no self-change, and the last admin
 * can never be demoted (admin rows are locked while counting to rule out a race
 * between two admins demoting each other).
 */
class ChangeUserRole
{
    public function handle(User $actor, User $target, Role $role): User
    {
        Gate::forUser($actor)->authorize('changeRole', $target);

        if ($actor->is($target)) {
            throw IdentityException::cannotChangeOwnRole();
        }

        return DB::transaction(function () use ($actor, $target, $role): User {
            $admins = User::query()->where('role', Role::Admin->value)->lockForUpdate()->pluck('id');
            $fresh = User::query()->lockForUpdate()->whereKey($target->getKey())->firstOrFail();
            $old = $fresh->role;

            if ($old === $role) {
                return $fresh;
            }

            if ($old === Role::Admin && $role !== Role::Admin && $admins->count() <= 1) {
                throw IdentityException::lastAdmin();
            }

            $fresh->forceFill(['role' => $role])->save();

            Log::info('user.role_changed', [
                'actor_id' => $actor->id,
                'user_id' => $fresh->id,
                'from' => $old->value,
                'to' => $role->value,
            ]);

            return $fresh;
        });
    }
}
