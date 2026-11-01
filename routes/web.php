<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevLoginController;
use App\Modules\Casting\Http\OverlayController;
use App\Modules\Catering\Http\CateringController;
use App\Modules\Discord\Http\GatewayIngressController;
use App\Modules\Discord\Http\InteractionsController;
use App\Modules\Discord\Http\VoicePresenceController;
use App\Modules\Events\Http\EventPageController;
use App\Modules\Files\Http\FilePageController;
use App\Modules\Files\Http\TusHookController;
use App\Modules\Files\Http\UploadTicketController;
use App\Modules\Friends\Http\FriendsController;
use App\Modules\Gallery\Http\GalleryPageController;
use App\Modules\Gallery\Http\PhotoController;
use App\Modules\GameServers\Http\GameServerPageController;
use App\Modules\GameServers\Http\MatchTelemetryController;
use App\Modules\GameServers\Http\PermanentServerPageController;
use App\Modules\Identity\Http\AvatarController;
use App\Modules\Identity\Http\DiscordAuthController;
use App\Modules\Identity\Http\ProfileController;
use App\Modules\Identity\Support\AvatarStore;
use App\Modules\Infoscreen\Http\OrgaPingController;
use App\Modules\Infoscreen\Http\ScreenControlController;
use App\Modules\Infoscreen\Http\ScreenController;
use App\Modules\Jukebox\Http\JukeboxController;
use App\Modules\Lfg\Http\LfgController;
use App\Modules\Notifications\Http\NotificationController;
use App\Modules\Presence\Http\PresencePageController;
use App\Modules\Recap\Http\RecapPageController;
use App\Modules\Registration\Http\CheckInController;
use App\Modules\Registration\Http\CheckinQrPrintController;
use App\Modules\Registration\Http\RegistrationController;
use App\Modules\Registration\Http\SelfCheckInController;
use App\Modules\Schedule\Http\ScheduleController;
use App\Modules\Seating\Http\SeatingController;
use App\Modules\Stats\Http\StatsPageController;
use App\Modules\Teams\Http\TeamController;
use App\Modules\Tournaments\Http\TournamentPageController;
use App\Modules\Voice\Http\VoiceSetupController;
use App\Modules\Voting\Http\PollPageController;
use App\Modules\WebVoice\Http\WebVoiceController;
use App\Modules\WebVoice\NativeClient\Http\ClientDownloadController;
use App\Modules\WebVoice\NativeClient\Http\ConnectController;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Route;

$modules = app(Modules::class);

// ---- core (always) ----

Route::get('/', [EventPageController::class, 'home'])->name('home');
Route::get('/users/{user}', [ProfileController::class, 'show'])->name('profile.show');

Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');

// Local-only demo login (guarded to 404 unless APP_ENV=local — see the
// controller). Lets people explore a local clone without a Discord app.
Route::post('dev/login/{role}', DevLoginController::class)->name('dev.login');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Local avatar copies (Identity, core): every logged-in user, immutable per content hash.
    Route::get('avatars/{user}/{hash}', [AvatarController::class, 'show'])
        ->whereNumber('user')
        ->where('hash', AvatarStore::HASH_PATTERN)
        ->middleware('throttle:avatars')
        ->name('avatars.show');

    // Nobody who is not logged in may see ANY event data: the event list, the
    // event page and every event-scoped participant page require login. Guests
    // only get the event-free landing at `/` (EventPageController::home).
    Route::get('/events', [EventPageController::class, 'archive'])->name('events.index');
    Route::get('/events/{event:slug}', [EventPageController::class, 'show'])->name('events.show');
    Route::get('/events/{event:slug}/seating', [SeatingController::class, 'index'])->name('events.seating');
    Route::get('/events/{event:slug}/tournaments', [TournamentPageController::class, 'index'])->name('tournaments.index');
    Route::get('/tournaments/{tournament}', [TournamentPageController::class, 'show'])->name('tournaments.show');

    Route::get('/events/{event:slug}/register', [RegistrationController::class, 'show'])->name('events.register');
    Route::post('/events/{event:slug}/register', [RegistrationController::class, 'store'])->name('events.register.store');
    Route::patch('/events/{event:slug}/register/arrival', [RegistrationController::class, 'updateArrival'])->name('events.register.arrival');
    Route::delete('/events/{event:slug}/register', [RegistrationController::class, 'destroy'])->name('events.register.destroy');

    // On-site self check-in (one QR per event). 404 for unknown/rotated tokens.
    Route::get('/checkin/{token}', [SelfCheckInController::class, 'show'])->middleware('throttle:self-checkin')->name('checkin.show');
    Route::post('/checkin/{token}', [SelfCheckInController::class, 'store'])->middleware('throttle:self-checkin')->name('checkin.store');

    Route::post('/events/{event:slug}/seating/{seat}', [SeatingController::class, 'claim'])->name('events.seating.claim');
    Route::delete('/events/{event:slug}/seating', [SeatingController::class, 'release'])->name('events.seating.release');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');

    Route::post('/tournaments/{tournament}/enroll', [TournamentPageController::class, 'enroll'])->name('tournaments.enroll');
    Route::post('/tournaments/{tournament}/checkin', [TournamentPageController::class, 'checkin'])->name('tournaments.checkin');
    Route::post('/tournaments/{tournament}/withdraw', [TournamentPageController::class, 'withdraw'])->name('tournaments.withdraw');
    Route::post('/matches/{match}/report', [TournamentPageController::class, 'report'])->name('matches.report');
    Route::post('/matches/{match}/confirm', [TournamentPageController::class, 'confirm'])->name('matches.confirm');
    Route::post('/matches/{match}/dispute', [TournamentPageController::class, 'dispute'])->name('matches.dispute');
    Route::post('/matches/{match}/report/withdraw', [TournamentPageController::class, 'withdrawReport'])->name('matches.report.withdraw');
    Route::post('/matches/{match}/report/correct', [TournamentPageController::class, 'correctReport'])->name('matches.report.correct');
    Route::post('/matches/{match}/go', [TournamentPageController::class, 'go'])->name('matches.go');

    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('/teams/{team}/edit', [TeamController::class, 'edit'])->name('teams.edit');
    Route::patch('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::post('/teams/{team}/join', [TeamController::class, 'join'])->name('teams.join');
    Route::post('/teams/{team}/requests/{teamRequest}', [TeamController::class, 'respond'])->name('teams.respond');
    Route::post('/teams/{team}/transfer', [TeamController::class, 'transfer'])->name('teams.transfer');
    Route::delete('/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');
});

Route::middleware(['auth', 'role:helper'])->group(function () {
    Route::get('/orga/events/{event:slug}/checkin', [CheckInController::class, 'show'])->name('orga.checkin');
    Route::post('/orga/events/{event:slug}/checkin', [CheckInController::class, 'store'])->name('orga.checkin.store');
});

Route::middleware(['auth', 'role:orga'])->group(function () {
    Route::get('/orga/events/{event:slug}/checkin-qr', CheckinQrPrintController::class)->name('orga.checkin.print');
});

Route::middleware(['guest'])->group(function () {
    Route::get('auth/discord/redirect', [DiscordAuthController::class, 'redirect'])->name('login.discord');
});

// Not behind 'guest': the callback must be able to complete the OAuth handshake
// regardless of prior auth state (double tab, back-button replay, stale redirect
// hitting an already-authenticated session).
Route::get('auth/discord/callback', [DiscordAuthController::class, 'callback']);

// ---- optional modules (one block each; 404 when the module is off) ----

if ($modules->routable(Module::Stats)) {
    // Login-required cross-event leaderboard — aggregates completed matches/tournament
    // wins across every event (see LeaderboardQuery), so unlike the rest of the
    // participant UI it is not scoped under /events/{event:slug}.
    Route::middleware(['auth'])->group(function () {
        Route::get('/stats/leaderboard', [StatsPageController::class, 'leaderboard'])->name('stats.leaderboard');
    });
}

if ($modules->routable(Module::Schedule)) {
    // Login-required programme/schedule page — same "login-required like seating/tournaments,
    // login required" visibility rule as the rest of the participant UI.
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/schedule', [ScheduleController::class, 'show'])->name('schedule.index');
        Route::get('/events/{event:slug}/schedule.ics', [ScheduleController::class, 'ics'])->name('schedule.ics');
    });

    Route::middleware(['auth'])->group(function () {
        Route::post('/schedule/{item}/favorite', [ScheduleController::class, 'favorite'])->name('schedule.favorite');
        Route::delete('/schedule/{item}/favorite', [ScheduleController::class, 'unfavorite'])->name('schedule.unfavorite');
    });
}

if ($modules->routable(Module::Catering)) {
    // Login-required catering page — same "login-required like seating/tournaments/schedule, no
    // auth required" visibility rule; placing/cancelling an item requires auth
    // (see below).
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/catering', [CateringController::class, 'show'])->name('catering.show');
    });

    Route::middleware(['auth'])->group(function () {
        Route::post('/food-orders/{foodOrder}/items', [CateringController::class, 'store'])->name('catering.items.store');
        Route::delete('/food-order-items/{foodOrderItem}', [CateringController::class, 'destroy'])->name('catering.items.destroy');
    });
}

if ($modules->routable(Module::Voting)) {
    // Login-required polls pages — same "login-required like seating/tournaments/schedule/catering,
    // login required" visibility rule; casting a vote requires auth (see below).
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/polls', [PollPageController::class, 'index'])->name('polls.index');
        Route::get('/polls/{poll}', [PollPageController::class, 'show'])->name('polls.show');
    });

    Route::middleware(['auth'])->group(function () {
        Route::post('/polls/{poll}/vote', [PollPageController::class, 'vote'])->name('polls.vote');
    });
}

if ($modules->routable(Module::Lfg)) {
    // Login-required LFG board — same "login-required like seating/tournaments/schedule/catering/
    // polls, login required" visibility rule; creating/deleting a post requires
    // auth (see below).
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/lfg', [LfgController::class, 'index'])->name('lfg.index');
    });

    Route::middleware(['auth'])->group(function () {
        Route::post('/events/{event:slug}/lfg', [LfgController::class, 'store'])->name('lfg.store');
        Route::delete('/lfg/{lfgPost}', [LfgController::class, 'destroy'])->name('lfg.destroy');
    });
}

if ($modules->routable(Module::GameServers)) {
    // Login-required server list — same "login-required like seating/tournaments/schedule/
    // catering/polls/lfg, login required" visibility rule; lists the event's
    // Ready game servers only (see ServerListProjection).
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/servers', [GameServerPageController::class, 'index'])->name('servers.index');
        // Permanent servers: all year, independent of events, every logged-in user.
        Route::get('/servers', [PermanentServerPageController::class, 'index'])->name('servers.permanent');
    });

    // CS2 live-stats webhook (roadmap 6.9): a MatchZy/G5API game server POSTs
    // round/score events here, authenticated by its own per-ServerLink bearer
    // token (see MatchTelemetryController) rather than a Laravel session — like
    // the Discord interactions endpoint, it is exempted from CSRF in
    // bootstrap/app.php instead of relying on session/cookie state.
    Route::post('api/telemetry/cs2/{serverLink}', MatchTelemetryController::class)
        ->name('telemetry.cs2');
}

if ($modules->routable(Module::Files)) {
    // Login-required LAN file-sharing list — same "login-required like seating/tournaments/
    // schedule/catering/polls/lfg, login required" visibility rule; shows only
    // approved files (+ the viewer's own pending ones); uploading/deleting
    // requires auth (see below). Files are approved by a helper/orga (Task 6).
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/files', [FilePageController::class, 'index'])->name('files.index');
    });

    Route::middleware(['auth'])->group(function () {
        Route::post('/events/{event:slug}/files', [FilePageController::class, 'store'])->name('files.store');
        // Resumable big-file upload, step 1: a single-use ticket for one file (see docs/infra/file-host.md).
        Route::post('/events/{event:slug}/files/uploads', UploadTicketController::class)
            ->middleware('throttle:file-upload-tickets')
            ->name('files.uploads.ticket');
        Route::get('/files/{sharedFile}/download', [FilePageController::class, 'download'])->name('files.download');
        Route::delete('/files/{sharedFile}', [FilePageController::class, 'destroy'])->name('files.destroy');
    });

    // tusd's HTTP hooks, relayed by the file host's nginx (shared secret header, no session).
    Route::post('internal/files/tusd-hook', TusHookController::class)
        ->middleware('files.tusd')
        ->name('files.tusd.hook');
}

if ($modules->routable(Module::Presence)) {
    // Login-required presence board — same "login-required like seating/tournaments/schedule/
    // catering/polls/lfg/servers/files, login required" visibility rule; who is
    // checked in, where, and what's currently live (see PresenceProjection).
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/presence', [PresencePageController::class, 'show'])->name('presence.show');
    });
}

if ($modules->routable(Module::Recap)) {
    // Login-required post-LAN recap — same "login required" visibility rule as the
    // rest of the participant UI, further gated to Finished/Archived events
    // only (see RecapPageController); this is the between-LANs page anyone can
    // revisit after the event wraps.
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/recap', [RecapPageController::class, 'show'])->name('recap.show');
    });
}

if ($modules->routable(Module::Infoscreen)) {
    // Public beamer screen — deliberately stays no-auth (unattended beamer
    // hardware cannot log in; it is not part of the login rule); renders with no
    // app navigation/layout (a bare full-viewport shell), see resources/js/app.ts.
    Route::get('/screen/{event:slug}', [ScreenController::class, 'show'])->name('screen.show');

    Route::middleware(['auth'])->group(function () {
        // Task 13: the "Orga rufen" participant button — no ticket system, just a
        // ping to everyone with an orga-or-above role, carrying the caller's seat
        // + up to three optional words. Throttled since there is no other rate
        // limit on this action and it fans out to every orga/helper.
        Route::post('/events/{event:slug}/ping-orga', [OrgaPingController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('events.ping-orga');
    });

    Route::middleware(['auth', 'role:helper'])->group(function () {
        // Helper "remote": a one-click "show now" push of a configured scene to
        // the beamer (see ShowSceneNow), reusing the same Action as the
        // Filament resource's show_now row action so the two surfaces never
        // drift on behaviour.
        Route::get('/screen/{event:slug}/control', [ScreenControlController::class, 'index'])->name('screen.control');

        // One-click triggers (Task 10): notify the bell (Discord DM mirrors per
        // preference), then, for the food trigger, also push the beamer.
        Route::post('/screen/{event:slug}/control/triggers/food-ready/{foodOrder}', [ScreenControlController::class, 'foodReady'])->name('screen.control.trigger.food-ready');
        Route::post('/screen/{event:slug}/control/triggers/checkin-open', [ScreenControlController::class, 'checkinOpen'])->name('screen.control.trigger.checkin-open');

        // Task 11: the tombola "draw next prize" trigger, one draw per request
        // (the control page lists remaining undrawn prizes, one button each).
        Route::post('/screen/{event:slug}/control/tombola/{tombolaPrize}/draw', [ScreenControlController::class, 'tombolaDraw'])->name('screen.control.tombola.draw');

        // Task 12: the operations status tile's "set status" control — a helper
        // flags one component's level, popping an outage reassurance onto the
        // beamer (or clearing it on recovery to Ok). Registered before the
        // generic `{scene}` show-now route below: both are single extra path
        // segments under `/control/`, so `status` would otherwise be swallowed
        // as a (non-existent) scene id.
        Route::post('/screen/{event:slug}/control/status', [ScreenControlController::class, 'setStatus'])->name('screen.control.set-status');

        Route::post('/screen/{event:slug}/control/{scene}', [ScreenControlController::class, 'show'])->name('screen.control.show');
    });
}

if ($modules->routable(Module::Gallery)) {
    // Public gallery photo/thumbnail serving for the beamer slideshow (Task 7)
    // and the Task-9 public recap: unlike `gallery.photos.show`/`thumb` below
    // (auth-gated, re-checks EventPhotoPolicy so an uploader can see their own
    // pending photo), these routes serve ONLY photos that are already both
    // Approved and belong to a publicly-visible event — that check is itself
    // the gate (no auth middleware, no policy, see PhotoController::publicShow()).
    Route::get('/gallery/public/photos/{eventPhoto}', [PhotoController::class, 'publicShow'])->name('gallery.photos.public.show');
    Route::get('/gallery/public/photos/{eventPhoto}/thumb', [PhotoController::class, 'publicThumb'])->name('gallery.photos.public.thumb');

    Route::middleware(['auth'])->group(function () {
        // Gallery photo/thumbnail serving — deliberately auth-gated (approved
        // photos to any authenticated participant, pending ones only to the
        // owner/orga via EventPhotoPolicy::view()). The public recap uses its
        // own narrow public-highlights path, not these routes.
        Route::get('/gallery/photos/{eventPhoto}', [PhotoController::class, 'show'])->name('gallery.photos.show');
        Route::get('/gallery/photos/{eventPhoto}/thumb', [PhotoController::class, 'thumb'])->name('gallery.photos.thumb');

        // The participant gallery page itself is also auth-gated (unlike the
        // rest of the "login-required like seating/tournaments/..." participant UI)
        // because it renders <img> tags pointing at the two routes above; a
        // public index would show guests broken/401 images. See
        // GalleryPageController's class docblock.
        Route::get('/events/{event:slug}/gallery', [GalleryPageController::class, 'index'])->name('gallery.index');
        Route::post('/events/{event:slug}/gallery', [GalleryPageController::class, 'store'])->name('gallery.store');
        Route::delete('/gallery/photos/{eventPhoto}', [GalleryPageController::class, 'destroy'])->name('gallery.destroy');
        Route::get('/events/{event:slug}/gallery/zip', [GalleryPageController::class, 'downloadZip'])->name('gallery.zip');
    });
}

if ($modules->routable(Module::Jukebox)) {
    // Login-required jukebox board — same "login-required like seating/tournaments/schedule/
    // catering/polls/lfg/servers/files/presence, login required" visibility
    // rule; queueing/voting/skipping/removing all require auth (see below) and
    // are further gated by JukeboxPolicy inside each action.
    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/jukebox', [JukeboxController::class, 'index'])->name('jukebox.index');
    });

    Route::middleware(['auth'])->group(function () {
        Route::get('/events/{event:slug}/jukebox/search', [JukeboxController::class, 'search'])->name('jukebox.search');
        Route::post('/events/{event:slug}/jukebox', [JukeboxController::class, 'add'])->name('jukebox.add');
        Route::post('/events/{event:slug}/jukebox/skip', [JukeboxController::class, 'skip'])->name('jukebox.skip');
        Route::post('/jukebox/{jukeboxItem}/vote', [JukeboxController::class, 'vote'])->name('jukebox.vote');
        Route::post('/jukebox/{jukeboxItem}/skip-vote', [JukeboxController::class, 'skipVote'])->name('jukebox.skip-vote');
        Route::delete('/jukebox/{jukeboxItem}', [JukeboxController::class, 'remove'])->name('jukebox.remove');
    });
}

if ($modules->routable(Module::Casting)) {
    // Public, no-auth, transparent-background OBS overlay — reuses the already-
    // public bracket data (BracketMatchProjection, same as the tournament show
    // page) rendered render-only; gated by the owning event's public visibility,
    // same rule as every other public participant surface above.
    Route::get('/overlay/tournament/{tournament}/bracket', [OverlayController::class, 'bracket'])->name('overlay.bracket');

    // Public, no-auth, transparent-background per-match scoreboard OBS overlay —
    // same public-visibility gate as the bracket overlay above, seeded from the
    // match's persisted score and then updated live over `tournament.{id}`'s
    // `.match.score_updated` broadcast (see OverlayController::scoreboard()).
    Route::get('/overlay/match/{match}/scoreboard', [OverlayController::class, 'scoreboard'])->name('overlay.scoreboard');
}

if ($modules->routable(Module::Voice)) {
    Route::middleware(['auth'])->group(function () {
        // Participant "Voice einrichten" page (roadmap 8.7): per-active-provider
        // connect data + downloadable client installers. Not event-scoped —
        // voice providers are an installation-wide concern, not per-event.
        Route::get('/voice/setup', [VoiceSetupController::class, 'index'])->name('voice.setup');
        Route::get('/voice/installers/{installer}/download', [VoiceSetupController::class, 'download'])->name('voice.installers.download');
    });
}

if ($modules->routable(Module::WebVoice)) {
    // Browser voice + text chat. Not event-scoped (general channels are installation-wide);
    // every endpoint authorizes through WebVoiceChannelPolicy inside its action.
    Route::middleware(['auth'])->prefix('talk')->name('webvoice.')->group(function () {
        Route::get('/', [WebVoiceController::class, 'index'])->name('index');
        // Native client download: the files come from the validated manifest only (ClientBuilds).
        Route::get('client', [ClientDownloadController::class, 'show'])->name('client');
        Route::get('client/download/{file}', [ClientDownloadController::class, 'download'])
            ->where('file', '[A-Za-z0-9][A-Za-z0-9._-]{0,127}')
            ->middleware('throttle:20,1')
            ->name('client.download');
        Route::post('channels/{channel}/token', [WebVoiceController::class, 'token'])->middleware('throttle:30,1')->name('token');
        Route::post('latency-test/token', [WebVoiceController::class, 'latencyTestToken'])->middleware('throttle:10,1')->name('latency-test.token');
        Route::get('channels/{channel}/messages', [WebVoiceController::class, 'messages'])->name('messages');
        Route::post('channels/{channel}/messages', [WebVoiceController::class, 'post'])->middleware('throttle:web-voice-chat')->name('messages.store');
        Route::delete('messages/{message}', [WebVoiceController::class, 'destroyMessage'])->name('messages.destroy');
        Route::post('channels/{channel}/participants/{user}/mute', [WebVoiceController::class, 'mute'])->name('participants.mute');
        Route::delete('channels/{channel}/participants/{user}/mute', [WebVoiceController::class, 'unmute'])->name('participants.unmute');
        Route::post('channels/{channel}/participants/{user}/remove', [WebVoiceController::class, 'remove'])->name('participants.remove');

        // Native desktop client: pairing approval page and "Verbundene Geräte".
        Route::get('connect', [ConnectController::class, 'show'])->name('connect');
        Route::post('connect', [ConnectController::class, 'decide'])->middleware('throttle:voice-client-approve')->name('connect.decide');
        Route::delete('devices/{device}', [ConnectController::class, 'revoke'])->name('devices.revoke');
    });
}

if ($modules->routable(Module::Friends)) {
    Route::middleware(['auth'])->group(function () {
        Route::get('/friends', [FriendsController::class, 'index'])->name('friends.index');
        Route::post('/friends/request', [FriendsController::class, 'request'])->name('friends.request');
        Route::patch('/friends/{friendship}/respond', [FriendsController::class, 'respond'])->name('friends.respond');
        Route::delete('/friends/{friendship}/cancel', [FriendsController::class, 'cancel'])->name('friends.cancel');
        Route::delete('/friends/{user}', [FriendsController::class, 'remove'])->name('friends.remove');
        Route::post('/friends/{user}/block', [FriendsController::class, 'block'])->name('friends.block');
        Route::delete('/friends/{user}/block', [FriendsController::class, 'unblock'])->name('friends.unblock');
    });
}

if ($modules->routable(Module::Discord)) {
    // Public Discord voice occupancy read endpoint — maps current voice channels
    // and attendee counts, projection is the privacy boundary (No-PII, mapped names only).
    Route::get('discord/voice', VoicePresenceController::class)->name('discord.voice');

    // Discord HTTP Interactions endpoint. No routes/api.php exists in this project
    // (see bootstrap/app.php), so this lives here like the rest of the app's routes;
    // it is exempted from CSRF and from session/cookie middleware concerns via its
    // own signature verification instead (see bootstrap/app.php CSRF exception).
    Route::post('api/discord/interactions', InteractionsController::class)
        ->middleware('discord.signature')
        ->name('discord.interactions');

    // Internal ingress for gateway events forwarded by the discord.js sidecar
    // (docker/discord-gateway). Authenticated by a shared secret over the
    // compose network (see VerifyGatewaySecret), not by Discord's Ed25519
    // signature — the sidecar, not Discord, is the caller here.
    Route::post('internal/discord/gateway', GatewayIngressController::class)
        ->middleware('discord.gateway')
        ->name('discord.gateway.ingress');
}

require __DIR__.'/settings.php';
