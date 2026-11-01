<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Modules\WebVoice\NativeClient\Actions\ExchangeDeviceCode;
use App\Modules\WebVoice\NativeClient\Actions\StartDeviceAuthorization;
use App\Modules\WebVoice\NativeClient\Support\DeviceFlowError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The unauthenticated half of the device flow: request a code pair, poll for the token. */
class DeviceFlowController extends Controller
{
    public function device(Request $request, StartDeviceAuthorization $start): JsonResponse
    {
        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($start->handle((string) ($data['device_name'] ?? '')), 200, ['Cache-Control' => 'no-store']);
    }

    public function token(Request $request, ExchangeDeviceCode $exchange): JsonResponse
    {
        $data = $request->validate([
            'device_code' => ['required', 'string', 'max:128'],
        ]);

        try {
            return response()->json($exchange->handle($data['device_code']), 200, ['Cache-Control' => 'no-store']);
        } catch (DeviceFlowError $e) {
            return response()->json($e->payload(), 400, ['Cache-Control' => 'no-store']);
        }
    }
}
