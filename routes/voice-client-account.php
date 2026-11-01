<?php

use App\Modules\Friends\Http\NativeFriendsController;
use App\Modules\Identity\Http\NativeConnectionsController;
use App\Modules\Identity\Http\NativeProfileController;
use App\Modules\WebVoice\NativeClient\Http\AuthenticateVoiceClient;
use App\Modules\WebVoice\NativeClient\Http\ForceJsonAccept;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Native client API, "account" block: own profile, linked accounts and friends. Required from
| routes/voice-client.php (no web group, no session, no CSRF). Every decision is made by the
| browser's Actions/Policies the controllers delegate to. Docs: docs/infra/native-voice-client-api.md
*/
Route::prefix('api/voice-client/v1')
    ->middleware([ForceJsonAccept::class, AuthenticateVoiceClient::class, 'throttle:voice-client-api', SubstituteBindings::class])
    ->name('voice-client.v1.')
    ->group(function () {
        // Profile + connections: Identity is a core module.
        Route::get('profile', [NativeProfileController::class, 'show'])->name('profile');
        Route::put('profile', [NativeProfileController::class, 'update'])->middleware('throttle:30,1')->name('profile.update');
        Route::patch('profile', [NativeProfileController::class, 'update'])->middleware('throttle:30,1');

        Route::get('connections', [NativeConnectionsController::class, 'index'])->name('connections');
        Route::post('connections/{provider}/link-url', [NativeConnectionsController::class, 'link'])->middleware('throttle:10,1')->name('connections.link-url');
        Route::delete('connections/{provider}', [NativeConnectionsController::class, 'destroy'])->middleware('throttle:30,1')->name('connections.destroy');

        if (app(Modules::class)->routable(Module::Friends)) {
            Route::get('friends', [NativeFriendsController::class, 'index'])->name('friends');
            Route::get('friends/requests', [NativeFriendsController::class, 'requests'])->name('friends.requests');
            Route::get('friends/suggestions', [NativeFriendsController::class, 'suggestions'])->name('friends.suggestions');
            Route::get('friends/blocked', [NativeFriendsController::class, 'blocked'])->name('friends.blocked');
            Route::post('friends/requests', [NativeFriendsController::class, 'sendRequest'])->middleware('throttle:20,1')->name('friends.requests.send');
            Route::post('friends/requests/{friendship}/accept', [NativeFriendsController::class, 'accept'])->middleware('throttle:30,1')->name('friends.requests.accept');
            Route::post('friends/requests/{friendship}/decline', [NativeFriendsController::class, 'decline'])->middleware('throttle:30,1')->name('friends.requests.decline');
            Route::delete('friends/requests/{friendship}', [NativeFriendsController::class, 'cancel'])->middleware('throttle:30,1')->name('friends.requests.cancel');
            Route::delete('friends/{user}', [NativeFriendsController::class, 'remove'])->middleware('throttle:30,1')->name('friends.remove');
            Route::post('users/{user}/block', [NativeFriendsController::class, 'block'])->middleware('throttle:30,1')->name('users.block');
            Route::delete('users/{user}/block', [NativeFriendsController::class, 'unblock'])->middleware('throttle:30,1')->name('users.unblock');
        }
    });
