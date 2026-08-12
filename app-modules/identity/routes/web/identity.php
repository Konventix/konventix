<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Identity\Http\Controllers\ProfileController;
use Konventix\Identity\Http\Controllers\SecurityController;
use Konventix\Identity\Http\Controllers\SessionController;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Single-session revocation is password-confirm gated (task brief: "keep
    // simple"); revoking every other session is not — it is the lower-risk
    // operation (it can never lock the acting session out) and mirrors
    // Fortify's own "log out other browser sessions" pattern of not
    // requiring a fresh confirmation.
    Route::delete('settings/sessions/{session}', [SessionController::class, 'destroy'])
        ->middleware('password.confirm')
        ->name('sessions.destroy');

    Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])
        ->name('sessions.destroy-others');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
