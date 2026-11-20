<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http;

use App\Http\Controllers\Controller;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Support\DeviceFlowError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Unauthenticated half of the agent pairing flow: request a code pair, poll for the token. */
class DeviceFlowController extends Controller
{
    public function device(Request $request, StartDeviceAuthorization $start): JsonResponse
    {
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
}
