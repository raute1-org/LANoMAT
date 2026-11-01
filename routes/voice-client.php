<?php

use App\Modules\WebVoice\NativeClient\Http\AuthenticateVoiceClient;
use App\Modules\WebVoice\NativeClient\Http\DeviceFlowController;
use App\Modules\WebVoice\NativeClient\Http\Features\CateringApiController;
use App\Modules\WebVoice\NativeClient\Http\Features\EventsApiController;
use App\Modules\WebVoice\NativeClient\Http\Features\FilesApiController;
use App\Modules\WebVoice\NativeClient\Http\Features\PollsApiController;
use App\Modules\WebVoice\NativeClient\Http\ForceJsonAccept;
use App\Modules\WebVoice\NativeClient\Http\VoiceClientApiController;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
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

/*
| === Event features for the native client (events, polls, catering, files) =====================
| Same bearer middleware as above; every action goes through the browser's Actions and Policies.
| Each feature block exists only while its module is routable, like the web routes.
*/
$nativeFeatureModules = app(Modules::class);

Route::prefix('api/voice-client/v1')
    ->middleware([ForceJsonAccept::class, AuthenticateVoiceClient::class, 'throttle:voice-client-api', SubstituteBindings::class])
    ->name('voice-client.v1.')
    ->group(function () use ($nativeFeatureModules) {
        Route::get('events', [EventsApiController::class, 'index'])->name('events');

        if ($nativeFeatureModules->routable(Module::Voting)) {
            Route::get('events/{event}/polls', [PollsApiController::class, 'index'])->name('polls.index');
            Route::get('polls/{poll}', [PollsApiController::class, 'show'])->name('polls.show');
            Route::post('polls/{poll}/vote', [PollsApiController::class, 'vote'])->middleware('throttle:30,1')->name('polls.vote');
        }

        if ($nativeFeatureModules->routable(Module::Catering)) {
            Route::get('events/{event}/catering', [CateringApiController::class, 'show'])->name('catering.show');
            Route::get('events/{event}/catering/mine', [CateringApiController::class, 'mine'])->name('catering.mine');
            Route::post('food-orders/{foodOrder}/items', [CateringApiController::class, 'store'])->middleware('throttle:30,1')->name('catering.items.store');
            Route::delete('food-order-items/{foodOrderItem}', [CateringApiController::class, 'destroy'])->middleware('throttle:30,1')->name('catering.items.destroy');
        }

        if ($nativeFeatureModules->routable(Module::Files)) {
            Route::get('events/{event}/files', [FilesApiController::class, 'index'])->name('files.index');
            Route::get('events/{event}/files/quota', [FilesApiController::class, 'quota'])->name('files.quota');
            Route::post('events/{event}/files/uploads/ticket', [FilesApiController::class, 'ticket'])->middleware('throttle:file-upload-tickets')->name('files.uploads.ticket');
            Route::get('files/{sharedFile}/download', [FilesApiController::class, 'download'])->middleware('throttle:60,1')->name('files.download');
            Route::delete('files/{sharedFile}', [FilesApiController::class, 'destroy'])->middleware('throttle:30,1')->name('files.destroy');
        }
    });
