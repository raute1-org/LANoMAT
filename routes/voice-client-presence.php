<?php

use App\Modules\Presence\Http\PeopleApiController;
use App\Modules\WebVoice\NativeClient\Http\AuthenticateVoiceClient;
use App\Modules\WebVoice\NativeClient\Http\ForceJsonAccept;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Native client API, "people" block: where the participants of an event are (voice rooms, games)
| and how to follow one of them. Required from routes/voice-client.php (no web group, no session,
| no CSRF), same bearer middleware and per-token throttle as the play block. Events are addressed
| by slug like on the web; a draft event is a 404. Present only while the Presence module is on.
| Docs: docs/infra/native-voice-client-api.md
*/
if (app(Modules::class)->routable(Module::Presence)) {
    Route::prefix('api/voice-client/v1')
        ->middleware([ForceJsonAccept::class, AuthenticateVoiceClient::class, 'throttle:voice-client-api', SubstituteBindings::class])
        ->name('voice-client.v1.')
        ->group(function () {
            Route::get('events/{event:slug}/people', [PeopleApiController::class, 'index'])->name('people');
            // `{user}` stays a plain id (see the controller): no model binding, no oracle.
            Route::get('events/{event:slug}/people/{user}/follow', [PeopleApiController::class, 'follow'])->middleware('throttle:60,1')->name('people.follow');
        });
}
