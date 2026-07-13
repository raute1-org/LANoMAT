<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        $allowed = match (Role::from($role)) {
            Role::Admin => $user?->isAdmin() ?? false,
            Role::Orga => $user?->isOrga() ?? false,
            Role::Participant => $user !== null,
        };

        abort_unless($allowed, 403);

        return $next($request);
    }
}
