<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use Illuminate\Http\Request;

/**
 * The client address for throttling the unauthenticated device flow.
 *
 * `bootstrap/app.php` trusts every proxy (`trustProxies(at: '*')`) because the
 * app is only reachable through Traefik. That also means Laravel's own
 * `$request->ip()` takes the *leftmost* `X-Forwarded-For` entry — a value the
 * client writes itself. An attacker who rotates that header would otherwise
 * get a fresh rate-limit bucket per request.
 *
 * Traefik appends the address it saw, so the *rightmost* hop is the one value
 * the client cannot choose. With exactly one trusted proxy in front of the app
 * (the documented deployment) it is the real peer. Without a forward header we
 * fall back to `REMOTE_ADDR`.
 */
final class McpClientAddress
{
    public static function for(Request $request): string
    {
        $forwarded = $request->headers->get('X-Forwarded-For');

        if (is_string($forwarded) && trim($forwarded) !== '') {
            $hops = array_values(array_filter(
                array_map(static fn (string $hop): string => trim($hop), explode(',', $forwarded)),
                static fn (string $hop): bool => $hop !== '',
            ));

            $last = $hops === [] ? null : $hops[count($hops) - 1];

            if (is_string($last) && filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }

        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
