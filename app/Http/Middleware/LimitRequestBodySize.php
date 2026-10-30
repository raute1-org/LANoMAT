<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second layer of the request-body cap (Traefik's `buffering` middleware is
 * the first, see config/lanomat.php `limits`): refuses a request whose
 * declared Content-Length exceeds the cap of its route class with a 413
 * before any controller, validation or file handling runs. Only the
 * upload routes listed in `lanomat.limits.routes` get a higher cap; every
 * other route is held to `default_mb`.
 *
 * It trusts the Content-Length header, so a chunked body without one is only
 * bounded by Traefik/PHP's post_max_size — that is why the proxy cap exists.
 */
class LimitRequestBodySize
{
    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if ($declared !== null && ctype_digit($declared) && (int) $declared > $this->limitBytes($request)) {
            abort(413, 'Die Anfrage ist zu groß.');
        }

        return $next($request);
    }

    private function limitBytes(Request $request): int
    {
        $name = $request->route()?->getName();
        $routes = (array) config('lanomat.limits.routes', []);
        $key = is_string($name) && isset($routes[$name]) && is_string($routes[$name])
            ? $routes[$name]
            : 'default_mb';

        return (int) config('lanomat.limits.'.$key, config('lanomat.limits.default_mb')) * 1024 * 1024;
    }
}
