<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http;

use App\Http\Controllers\Controller;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Support\DeviceFlowError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated half of the agent pairing flow: request a code pair, poll for the token.
 *
 * Every failure is a 400 with a machine-readable `error` (RFC 8628 §3.5) — never
 * a 422 validation blob, so the agent has one shape to switch on. Responses are
 * `no-store`: the token must not land in any HTTP cache.
 */
class DeviceFlowController extends Controller
{
    private const MAX_DEVICE_CODE_LENGTH = 128;

    private const MAX_DEVICE_NAME_LENGTH = 120;

    public function device(Request $request, StartDeviceAuthorization $start): JsonResponse
    {
        $name = $request->input('device_name');

        if ($name !== null && (! is_string($name) || mb_strlen($name) > self::MAX_DEVICE_NAME_LENGTH)) {
            return $this->failure('invalid_request');
        }

        return response()->json(
            $start->handle(is_string($name) ? $name : ''),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    public function token(Request $request, ExchangeDeviceCode $exchange): JsonResponse
    {
        $deviceCode = $request->input('device_code');

        if (! is_string($deviceCode) || $deviceCode === '' || strlen($deviceCode) > self::MAX_DEVICE_CODE_LENGTH) {
            return $this->failure('invalid_request');
        }

        try {
            return response()->json(
                $exchange->handle($deviceCode),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (DeviceFlowError $error) {
            return response()->json($error->payload(), 400, ['Cache-Control' => 'no-store']);
        }
    }

    private function failure(string $error): JsonResponse
    {
        return response()->json(['error' => $error], 400, ['Cache-Control' => 'no-store']);
    }
}
