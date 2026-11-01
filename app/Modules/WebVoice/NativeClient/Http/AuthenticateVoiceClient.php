<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use App\Modules\WebVoice\NativeClient\Support\NativeClientConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves `Authorization: Bearer <token>` to a user for the native client API routes only. It
 * sets the request's user resolver, never the default auth guard, so a bearer token cannot
 * reach any session-guarded route. Revoked or expired tokens answer 401, like unknown ones.
 */
class AuthenticateVoiceClient
{
    /** Writes to last_used_at / expires_at are skipped inside this window. */
    private const TOUCH_EVERY_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '' || strlen($plain) > 200) {
            return $this->unauthenticated();
        }

        $hash = DeviceCodes::hash($plain);
        $token = VoiceClientToken::query()->where('token_hash', $hash)->first();

        if ($token === null || ! hash_equals($token->token_hash, $hash) || ! $token->isActive()) {
            return $this->unauthenticated();
        }

        $user = User::query()->find($token->user_id);

        if ($user === null) {
            return $this->unauthenticated();
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now(), true) >= self::TOUCH_EVERY_SECONDS) {
            $token->forceFill([
                'last_used_at' => now(),
                'expires_at' => now()->addDays(NativeClientConfig::tokenTtlDays()),
            ])->save();
        }

        $request->setUserResolver(fn (): User => $user);
        $request->attributes->set('voice_client_token', $token);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
