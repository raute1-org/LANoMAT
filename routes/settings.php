<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\RedirectPasswordlessUsersFromSecurity;
use App\Modules\Identity\Http\ConnectionsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Minimal stub so `route('connections.edit')` resolves and the Steam
    // callback redirect (below) doesn't dead-end — Task 9.5 replaces this
    // with the real Inertia connections page.
    Route::redirect('settings/connections', '/settings/profile')->name('connections.edit');

    Route::get('settings/connections/{provider}/redirect', [ConnectionsController::class, 'redirect'])->name('connections.redirect');
    Route::get('settings/connections/{provider}/callback', [ConnectionsController::class, 'callback'])->name('connections.callback');
    Route::delete('settings/connections/{provider}', [ConnectionsController::class, 'destroy'])->name('connections.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware([RedirectPasswordlessUsersFromSecurity::class, RequirePassword::class])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
