<?php

use App\Modules\Identity\Http\DiscordAuthController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['guest'])->group(function () {
    Route::get('auth/discord/redirect', [DiscordAuthController::class, 'redirect'])->name('login.discord');
    Route::get('auth/discord/callback', [DiscordAuthController::class, 'callback']);
});

require __DIR__.'/settings.php';
