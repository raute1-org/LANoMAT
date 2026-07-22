<?php

namespace App\Modules\Discord\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal gateway ingress: the discord.js sidecar authenticates
 * with a shared secret over the compose network. Replaces the (now retired)
 * Ed25519 signature check — that verified *Discord*; this verifies *our
 * sidecar → our app*.
 */
class VerifyGatewaySecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.discord.gateway_bridge_secret');
        $provided = $request->header('X-Gateway-Secret');

        if (! is_string($expected) || $expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
