<?php

use App\Modules\WebVoice\NativeClient\Http\AuthenticateVoiceClient;
use App\Modules\WebVoice\NativeClient\Http\DeviceFlowController;
use App\Modules\WebVoice\NativeClient\Http\VoiceClientApiController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Native desktop voice client API (WebVoice module). Loaded from bootstrap/app.php without the
| `web` middleware group: no session, no cookies, no CSRF. Docs: docs/infra/native-voice-client-api.md
*/

// Device authorization flow: unauthenticated by design, strictly rate limited.
Route::post('api/voice-client/device', [DeviceFlowController::class, 'device'])
    ->middleware('throttle:voice-client-device')
    ->name('voice-client.device');
Route::post('api/voice-client/token', [DeviceFlowController::class, 'token'])
    ->middleware('throttle:voice-client-token')
    ->name('voice-client.token');

// Versioned API, bearer-authenticated. Authorization of every room happens in WebVoiceChannelPolicy.
Route::prefix('api/voice-client/v1')->middleware([AuthenticateVoiceClient::class, 'throttle:voice-client-api', SubstituteBindings::class])->name('voice-client.v1.')->group(function () {
    Route::get('me', [VoiceClientApiController::class, 'me'])->name('me');
    Route::get('rooms', [VoiceClientApiController::class, 'rooms'])->name('rooms');
    Route::post('rooms/{room}/token', [VoiceClientApiController::class, 'token'])->middleware('throttle:30,1')->name('rooms.token');
    Route::get('config', [VoiceClientApiController::class, 'config'])->name('config');
});
