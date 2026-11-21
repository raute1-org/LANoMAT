<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Force JSON responses so validation failures never try an Inertia/session redirect. */
class ForceJsonAccept
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
