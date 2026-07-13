<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesAuthenticatedUser
{
    /**
     * Resolve the authenticated user for a request that is guaranteed to be
     * behind auth middleware, giving static analysis a non-null, typed User
     * instead of the nullable `Authenticatable` returned by `$request->user()`.
     */
    protected function authUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
