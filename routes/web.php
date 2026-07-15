<?php

use App\Modules\Discord\Http\InteractionsController;
use App\Modules\Events\Http\EventPageController;
use App\Modules\Identity\Http\DiscordAuthController;
use App\Modules\Identity\Http\ProfileController;
use App\Modules\Notifications\Http\NotificationController;
use App\Modules\Registration\Http\CheckInController;
use App\Modules\Registration\Http\RegistrationController;
use App\Modules\Schedule\Http\ScheduleController;
use App\Modules\Seating\Http\SeatingController;
use App\Modules\Teams\Http\TeamController;
use App\Modules\Tournaments\Http\TournamentPageController;
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

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('/events/{event:slug}/register', [RegistrationController::class, 'show'])->name('events.register');
    Route::post('/events/{event:slug}/register', [RegistrationController::class, 'store'])->name('events.register.store');
    Route::delete('/events/{event:slug}/register', [RegistrationController::class, 'destroy'])->name('events.register.destroy');

    Route::post('/events/{event:slug}/seating/{seat}', [SeatingController::class, 'claim'])->name('events.seating.claim');
    Route::delete('/events/{event:slug}/seating', [SeatingController::class, 'release'])->name('events.seating.release');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');

    Route::post('/tournaments/{tournament}/enroll', [TournamentPageController::class, 'enroll'])->name('tournaments.enroll');
    Route::post('/tournaments/{tournament}/checkin', [TournamentPageController::class, 'checkin'])->name('tournaments.checkin');
    Route::post('/matches/{match}/report', [TournamentPageController::class, 'report'])->name('matches.report');
    Route::post('/matches/{match}/confirm', [TournamentPageController::class, 'confirm'])->name('matches.confirm');
    Route::post('/matches/{match}/dispute', [TournamentPageController::class, 'dispute'])->name('matches.dispute');

    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('/teams/{team}/edit', [TeamController::class, 'edit'])->name('teams.edit');
    Route::patch('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::post('/teams/{team}/join', [TeamController::class, 'join'])->name('teams.join');
    Route::post('/teams/{team}/requests/{teamRequest}', [TeamController::class, 'respond'])->name('teams.respond');
    Route::delete('/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');
});

Route::middleware(['auth', 'role:orga'])->group(function () {
    Route::get('/orga/events/{event:slug}/checkin', [CheckInController::class, 'show'])->name('orga.checkin');
    Route::post('/orga/events/{event:slug}/checkin', [CheckInController::class, 'store'])->name('orga.checkin.store');
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
