<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectPasswordlessUsersFromSecurity
{
    /**
     * Discord-provisioned users have no local password, so Fortify's
     * `RequirePassword` middleware (which sits behind this one on the
     * security route) can never be satisfied for them — it would redirect
     * to a password-confirmation form they have no password to submit.
     * Intercept before that happens and send them somewhere useful instead.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->has_password) {
            return redirect()
                ->route('profile.edit')
                ->with('status', __('Security settings require a password. Discord-only accounts have none yet.'));
        }

        return $next($request);
    }
}
