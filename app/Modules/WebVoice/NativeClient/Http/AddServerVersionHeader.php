<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the native client which LANoMAT version answered (`X-Server-Version`), on every response of
 * the client API including errors, so it can compare with its own build without an extra call.
 * The value is the release string (`config('app.version')`), nothing about the machine or the user.
 */
class AddServerVersionHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Server-Version', (string) config('app.version'));

        return $response;
    }
}
