<?php

declare(strict_types=1);

namespace App\Modules\Files\Http\Middleware;

use App\Modules\Files\Support\FileUrlSigner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the tusd hook endpoint. tusd cannot send a static auth header, so the
 * file host's nginx relays hook calls and adds `X-Tusd-Hook-Secret`
 * (docs/infra/file-host.md). The secret is compared in constant time and is
 * never logged; a missing or too-weak configured secret refuses every call.
 */
class VerifyTusHookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('files.tus.hook_secret');
        $provided = $request->header('X-Tusd-Hook-Secret');

        if (
            ! is_string($expected) || ! FileUrlSigner::isAcceptableSecret($expected)
            || ! is_string($provided) || ! hash_equals($expected, $provided)
        ) {
            abort(401);
        }

        return $next($request);
    }
}
