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
    /** RFC 8628 field limits; anything longer is `invalid_request`, not a 422. */
    private const MAX_DEVICE_CODE_LENGTH = 128;

    private const MAX_DEVICE_NAME_LENGTH = 120;

    /** A start mints a row; one every six seconds per client is plenty. */
    private const DEVICE_ATTEMPTS = 10;

    /** Polling clients legitimately hit this every `interval` seconds. */
    private const TOKEN_ATTEMPTS = 60;

    public function device(Request $request, StartDeviceAuthorization $start): JsonResponse
    {
        if (($limited = $this->throttle($request, 'device', self::DEVICE_ATTEMPTS)) instanceof JsonResponse) {
            return $limited;
        }

        $name = $request->input('device_name');

        if ($name !== null && (! is_string($name) || mb_strlen($name) > self::MAX_DEVICE_NAME_LENGTH)) {
            return $this->invalidRequest();
        }

        return response()->json(
            $start->handle(is_string($name) ? $name : ''),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    public function token(Request $request, ExchangeDeviceCode $exchange): JsonResponse
    {
        if (($limited = $this->throttle($request, 'token', self::TOKEN_ATTEMPTS)) instanceof JsonResponse) {
            return $limited;
        }

        $code = $request->input('device_code');

        if (! is_string($code) || $code === '' || mb_strlen($code) > self::MAX_DEVICE_CODE_LENGTH) {
            return $this->invalidRequest();
        }

        try {
            return response()->json(
                $exchange->handle($code),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (DeviceFlowError $error) {
            return response()->json($error->payload(), 400, ['Cache-Control' => 'no-store']);
        }
    }

    /** The RFC 8628 shape for a malformed request (never a Laravel 422). */
    private function invalidRequest(): JsonResponse
    {
        return response()->json(['error' => 'invalid_request'], 400, ['Cache-Control' => 'no-store']);
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
