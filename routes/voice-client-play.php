<?php

use App\Modules\WebVoice\NativeClient\Http\AuthenticateVoiceClient;
use App\Modules\WebVoice\NativeClient\Http\GameServerApiController;
use App\Modules\WebVoice\NativeClient\Http\NowFeedController;
use App\Modules\WebVoice\NativeClient\Http\TournamentApiController;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Native client API, "play" block: the now feed, tournaments/matches and game servers. Required from
| routes/voice-client.php, so it shares its bootstrapping (no web group, no session, no CSRF) and
| the same bearer middleware. Events are addressed by slug like on the web; a draft event is a 404.
| Docs: docs/infra/native-voice-client-api.md
*/
Route::prefix('api/voice-client/v1')
    ->middleware([AuthenticateVoiceClient::class, 'throttle:voice-client-api', SubstituteBindings::class])
    ->name('voice-client.v1.')
    ->group(function () {
        Route::get('events/{event:slug}/now', [NowFeedController::class, 'show'])->name('now');

        // Tournaments is a core module: always present.
        Route::get('events/{event:slug}/tournaments', [TournamentApiController::class, 'tournaments'])->name('tournaments');
        Route::get('events/{event:slug}/my-matches', [TournamentApiController::class, 'myMatches'])->name('my-matches');
        Route::post('tournaments/{tournament}/checkin', [TournamentApiController::class, 'checkin'])->middleware('throttle:30,1')->name('tournaments.checkin');
        Route::post('matches/{match}/report', [TournamentApiController::class, 'report'])->middleware('throttle:30,1')->name('matches.report');
        Route::post('matches/{match}/confirm', [TournamentApiController::class, 'confirm'])->middleware('throttle:30,1')->name('matches.confirm');
        Route::post('matches/{match}/dispute', [TournamentApiController::class, 'dispute'])->middleware('throttle:30,1')->name('matches.dispute');
        Route::post('matches/{match}/report/withdraw', [TournamentApiController::class, 'withdrawReport'])->middleware('throttle:30,1')->name('matches.report.withdraw');

        if (app(Modules::class)->routable(Module::GameServers)) {
            Route::get('events/{event:slug}/servers', [GameServerApiController::class, 'event'])->name('servers.event');
            Route::get('servers', [GameServerApiController::class, 'permanent'])->name('servers.permanent');
        }
    });
