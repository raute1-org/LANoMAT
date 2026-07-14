<?php

use App\Modules\Events\Http\EventPageController;
use App\Modules\Identity\Http\DiscordAuthController;
use App\Modules\Identity\Http\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EventPageController::class, 'home'])->name('home');
Route::get('/events', [EventPageController::class, 'archive'])->name('events.index');
Route::get('/events/{event:slug}', [EventPageController::class, 'show'])->name('events.show');
Route::get('/users/{user}', [ProfileController::class, 'show'])->name('profile.show');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['guest'])->group(function () {
    Route::get('auth/discord/redirect', [DiscordAuthController::class, 'redirect'])->name('login.discord');
});

// Not behind 'guest': the callback must be able to complete the OAuth handshake
// regardless of prior auth state (double tab, back-button replay, stale redirect
// hitting an already-authenticated session).
Route::get('auth/discord/callback', [DiscordAuthController::class, 'callback']);

require __DIR__.'/settings.php';
