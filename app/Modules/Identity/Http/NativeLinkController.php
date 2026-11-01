<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Identity\Support\NativeLinkTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Browser end of the native client's "link an account" hand-off (see NativeConnectionsController).
 * Sits behind the normal `auth` middleware: a visitor without a session goes through the regular
 * login first and lands here again (intended URL). Only then is the token consumed and the
 * browser continues with the standard logged-in link flow.
 */
class NativeLinkController
{
    use ResolvesAuthenticatedUser;

    public function __invoke(Request $request, string $token, NativeLinkTokens $tokens): RedirectResponse
    {
        $provider = $tokens->consume($token, $this->authUser($request));

        abort_if($provider === false, 403);
        abort_if($provider === null, 410);

        return redirect()->route('connections.redirect', $provider->value);
    }
}
