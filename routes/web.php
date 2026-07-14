<?php

use App\Modules\Events\Http\EventPageController;
use App\Modules\Identity\Http\DiscordAuthController;
use App\Modules\Identity\Http\ProfileController;
use App\Modules\Registration\Http\CheckInController;
use App\Modules\Registration\Http\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EventPageController::class, 'home'])->name('home');
Route::get('/events', [EventPageController::class, 'archive'])->name('events.index');
Route::get('/events/{event:slug}', [EventPageController::class, 'show'])->name('events.show');
Route::get('/users/{user}', [ProfileController::class, 'show'])->name('profile.show');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('/events/{event:slug}/register', [RegistrationController::class, 'show'])->name('events.register');
    Route::post('/events/{event:slug}/register', [RegistrationController::class, 'store'])->name('events.register.store');
    Route::delete('/events/{event:slug}/register', [RegistrationController::class, 'destroy'])->name('events.register.destroy');
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

require __DIR__.'/settings.php';
