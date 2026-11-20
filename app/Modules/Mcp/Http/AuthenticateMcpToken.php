<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http;

use App\Models\User;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication for the MCP server and the agent REST API.
 *
 * Deliberately not the session guard: an MCP client is a separate process and
 * a stale browser session must never authenticate it. Every request resolves
 * the user from the token, so role changes and revocations apply immediately.
 */
class AuthenticateMcpToken
{
    private const TOUCH_EVERY_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if (! is_string($plain) || $plain === '' || strlen($plain) > 200) {
            return $this->unauthenticated();
        }

        $hash = McpCodes::hash($plain);
        $token = McpToken::query()->where('token_hash', $hash)->first();

        if (! $token instanceof McpToken || ! McpCodes::matches($plain, $token->token_hash) || ! $token->isActive()) {
            return $this->unauthenticated();
        }

        $user = User::query()->find($token->user_id);

        if (! $user instanceof User) {
            return $this->unauthenticated();
        }

        $now = now();

        if ($token->last_used_at === null || $token->last_used_at->copy()->addSeconds(self::TOUCH_EVERY_SECONDS)->isPast()) {
            $token->forceFill([
                'last_used_at' => $now,
                'expires_at' => $now->copy()->addDays(max(1, (int) config('mcp_agent.token_ttl_days', 90))),
            ])->save();
        }

        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('mcp_token', $token);

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(
            ['message' => 'Unauthenticated.'],
            401,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
