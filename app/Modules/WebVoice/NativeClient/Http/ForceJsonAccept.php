<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The native client API only speaks JSON: without an `Accept: application/json` header a
 * validation failure would otherwise turn into a redirect, which an API client cannot use.
 */
class ForceJsonAccept
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
