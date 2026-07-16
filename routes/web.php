<?php

use App\Modules\Catering\Http\CateringController;
use App\Modules\Discord\Http\InteractionsController;
use App\Modules\Events\Http\EventPageController;
use App\Modules\Identity\Http\DiscordAuthController;
use App\Modules\Identity\Http\ProfileController;
use App\Modules\Infoscreen\Http\OrgaPingController;
use App\Modules\Infoscreen\Http\ScreenControlController;
use App\Modules\Infoscreen\Http\ScreenController;
use App\Modules\Lfg\Http\LfgController;
use App\Modules\Notifications\Http\NotificationController;
use App\Modules\Registration\Http\CheckInController;
use App\Modules\Registration\Http\RegistrationController;
use App\Modules\Schedule\Http\ScheduleController;
use App\Modules\Seating\Http\SeatingController;
use App\Modules\Teams\Http\TeamController;
use App\Modules\Tournaments\Http\TournamentPageController;
use App\Modules\Voting\Http\PollPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EventPageController::class, 'home'])->name('home');
Route::get('/events', [EventPageController::class, 'archive'])->name('events.index');
Route::get('/events/{event:slug}', [EventPageController::class, 'show'])->name('events.show');
Route::get('/users/{user}', [ProfileController::class, 'show'])->name('profile.show');

Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');

// Public "who sits where" seat map — readable without authentication;
// claiming/releasing a seat requires auth + an active registration (see below).
Route::get('/events/{event:slug}/seating', [SeatingController::class, 'index'])->name('events.seating');

// Tournament index/bracket views are public (like the seating map) —
// enrolling/checking in/reporting requires auth (see below).
Route::get('/events/{event:slug}/tournaments', [TournamentPageController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentPageController::class, 'show'])->name('tournaments.show');

// Public programme/schedule page — same "public like seating/tournaments,
// no auth required" visibility rule as the rest of the participant UI.
Route::get('/events/{event:slug}/schedule', [ScheduleController::class, 'show'])->name('schedule.index');
Route::get('/events/{event:slug}/schedule.ics', [ScheduleController::class, 'ics'])->name('schedule.ics');

// Public catering page — same "public like seating/tournaments/schedule, no
// auth required" visibility rule; placing/cancelling an item requires auth
// (see below).
Route::get('/events/{event:slug}/catering', [CateringController::class, 'show'])->name('catering.show');

// Public polls pages — same "public like seating/tournaments/schedule/catering,
// no auth required" visibility rule; casting a vote requires auth (see below).
Route::get('/events/{event:slug}/polls', [PollPageController::class, 'index'])->name('polls.index');
Route::get('/polls/{poll}', [PollPageController::class, 'show'])->name('polls.show');

// Public LFG board — same "public like seating/tournaments/schedule/catering/
// polls, no auth required" visibility rule; creating/deleting a post requires
// auth (see below).
Route::get('/events/{event:slug}/lfg', [LfgController::class, 'index'])->name('lfg.index');

// Public beamer screen — same "public like seating/tournaments/schedule/
// catering/polls/lfg, no auth required" visibility rule; renders with no
// app navigation/layout (a bare full-viewport shell), see resources/js/app.ts.
Route::get('/screen/{event:slug}', [ScreenController::class, 'show'])->name('screen.show');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('/events/{event:slug}/register', [RegistrationController::class, 'show'])->name('events.register');
    Route::post('/events/{event:slug}/register', [RegistrationController::class, 'store'])->name('events.register.store');
    Route::delete('/events/{event:slug}/register', [RegistrationController::class, 'destroy'])->name('events.register.destroy');

    Route::post('/food-orders/{foodOrder}/items', [CateringController::class, 'store'])->name('catering.items.store');
    Route::delete('/food-order-items/{foodOrderItem}', [CateringController::class, 'destroy'])->name('catering.items.destroy');

    Route::post('/events/{event:slug}/seating/{seat}', [SeatingController::class, 'claim'])->name('events.seating.claim');
    Route::delete('/events/{event:slug}/seating', [SeatingController::class, 'release'])->name('events.seating.release');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');

    Route::post('/schedule/{item}/favorite', [ScheduleController::class, 'favorite'])->name('schedule.favorite');
    Route::delete('/schedule/{item}/favorite', [ScheduleController::class, 'unfavorite'])->name('schedule.unfavorite');

    Route::post('/tournaments/{tournament}/enroll', [TournamentPageController::class, 'enroll'])->name('tournaments.enroll');
    Route::post('/tournaments/{tournament}/checkin', [TournamentPageController::class, 'checkin'])->name('tournaments.checkin');
    Route::post('/matches/{match}/report', [TournamentPageController::class, 'report'])->name('matches.report');
    Route::post('/matches/{match}/confirm', [TournamentPageController::class, 'confirm'])->name('matches.confirm');
    Route::post('/matches/{match}/dispute', [TournamentPageController::class, 'dispute'])->name('matches.dispute');

    Route::post('/polls/{poll}/vote', [PollPageController::class, 'vote'])->name('polls.vote');

    Route::post('/events/{event:slug}/lfg', [LfgController::class, 'store'])->name('lfg.store');
    Route::delete('/lfg/{lfgPost}', [LfgController::class, 'destroy'])->name('lfg.destroy');

    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('/teams/{team}/edit', [TeamController::class, 'edit'])->name('teams.edit');
    Route::patch('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::post('/teams/{team}/join', [TeamController::class, 'join'])->name('teams.join');
    Route::post('/teams/{team}/requests/{teamRequest}', [TeamController::class, 'respond'])->name('teams.respond');
    Route::delete('/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

    // Task 13: the "Orga rufen" participant button — no ticket system, just a
    // ping to everyone with an orga-or-above role, carrying the caller's seat
    // + up to three optional words. Throttled since there is no other rate
    // limit on this action and it fans out to every orga/helper.
    Route::post('/events/{event:slug}/ping-orga', [OrgaPingController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('events.ping-orga');
});

Route::middleware(['auth', 'role:helper'])->group(function () {
    Route::get('/orga/events/{event:slug}/checkin', [CheckInController::class, 'show'])->name('orga.checkin');
    Route::post('/orga/events/{event:slug}/checkin', [CheckInController::class, 'store'])->name('orga.checkin.store');

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

Route::middleware(['guest'])->group(function () {
    Route::get('auth/discord/redirect', [DiscordAuthController::class, 'redirect'])->name('login.discord');
});

// Not behind 'guest': the callback must be able to complete the OAuth handshake
// regardless of prior auth state (double tab, back-button replay, stale redirect
// hitting an already-authenticated session).
Route::get('auth/discord/callback', [DiscordAuthController::class, 'callback']);

// Discord HTTP Interactions endpoint. No routes/api.php exists in this project
// (see bootstrap/app.php), so this lives here like the rest of the app's routes;
// it is exempted from CSRF and from session/cookie middleware concerns via its
// own signature verification instead (see bootstrap/app.php CSRF exception).
Route::post('api/discord/interactions', InteractionsController::class)
    ->middleware('discord.signature')
    ->name('discord.interactions');

require __DIR__.'/settings.php';
