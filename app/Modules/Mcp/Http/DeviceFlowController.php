<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http;

use App\Http\Controllers\Controller;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Support\DeviceFlowError;
use App\Modules\Mcp\Support\McpClientAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Unauthenticated half of the agent pairing flow: request a code pair, poll for the token. */
class DeviceFlowController extends Controller
{
    /** A start mints a row; one every six seconds per client is plenty. */
    private const DEVICE_ATTEMPTS = 10;

    /** Polling clients legitimately hit this every `interval` seconds. */
    private const TOKEN_ATTEMPTS = 60;

    public function device(Request $request, StartDeviceAuthorization $start): JsonResponse
    {
        if (($limited = $this->throttle($request, 'device', self::DEVICE_ATTEMPTS)) instanceof JsonResponse) {
            return $limited;
        }

        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(
            $start->handle((string) ($data['device_name'] ?? '')),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    public function token(Request $request, ExchangeDeviceCode $exchange): JsonResponse
    {
        if (($limited = $this->throttle($request, 'token', self::TOKEN_ATTEMPTS)) instanceof JsonResponse) {
            return $limited;
        }

        $data = $request->validate([
            'device_code' => ['required', 'string', 'max:128'],
        ]);

        try {
            return response()->json(
                $exchange->handle($data['device_code']),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (DeviceFlowError $error) {
            return response()->json($error->payload(), 400, ['Cache-Control' => 'no-store']);
        }
    }

    /**
     * A second, proxy-aware throttle on top of the route's `throttle:mcp-*`
     * middleware. The route throttle keys on `$request->ip()`, which honours a
     * client-supplied `X-Forwarded-For` (all proxies are trusted); this one
     * keys on the rightmost hop Traefik appended, so rotating the header does
     * not buy a fresh bucket.
     */
    private function throttle(Request $request, string $bucket, int $maxAttempts): ?JsonResponse
    {
        $key = 'mcp-flow|'.$bucket.'|'.McpClientAddress::for($request);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return response()->json(
                ['error' => 'too_many_requests', 'retry_after' => $retryAfter],
                429,
                ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'no-store'],
            );
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
