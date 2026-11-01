<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pusher-protocol channel authorization for native clients, bearer instead of session. It runs
 * the very same callbacks as the browser's `/broadcasting/auth` (routes/channels.php) for the
 * bearer user, so a websocket can never show more than the HTTP API. Only the private channel
 * families a native client has a use for are signed; everything else is a 403.
 */
class VoiceClientBroadcastingController extends Controller
{
    /** Channel names (without the `private-` prefix) a native client may ask to subscribe to. */
    private const ALLOWED = [
        '/^web-voice\.channel\.\d+$/',
        '/^web-voice\.directory$/',
        '/^App\.Models\.User\.\d+$/',
    ];

    public function __invoke(Request $request): mixed
    {
        $name = $request->input('channel_name');
        $socket = $request->input('socket_id');

        if (! is_string($name) || ! is_string($socket) || ! str_starts_with($name, 'private-')) {
            throw new AccessDeniedHttpException;
        }

        $bare = substr($name, strlen('private-'));

        foreach (self::ALLOWED as $pattern) {
            if (preg_match($pattern, $bare) === 1) {
                // Throws AccessDeniedHttpException (403) when the channel callback says no or
                // no callback is registered.
                return Broadcast::auth($request);
            }
        }

        throw new AccessDeniedHttpException;
    }
}
