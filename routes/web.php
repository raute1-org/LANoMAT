<?php

use App\Modules\Identity\Http\DiscordAuthController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

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
