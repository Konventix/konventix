<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Identity\Http\Controllers\LinkedAccountController;
use Konventix\Identity\Http\Controllers\MembershipImageController;
use Konventix\Identity\Http\Controllers\ProfileController;
use Konventix\Identity\Http\Controllers\SecurityController;
use Konventix\Identity\Http\Controllers\SessionController;
use Konventix\Identity\Http\Controllers\SocialAuthController;

// Social login (decision log D-IDN-01 as amended: Steam only). Deliberately
// outside the `auth` group — the same callback serves a guest signing in, a
// guest signing up, and a signed-in person linking a second way in, and which
// of the three it is only becomes knowable after the provider has answered.
//
// `{provider}` is validated against `SocialProviderRegistry` in the
// controller, so an unregistered key 404s rather than reaching Socialite.
Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('social.redirect');

Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('social.callback');

// Completing a social signup: the provider proved who somebody is, these two
// collect what it could not tell us. Guest-only — a signed-in person reaching
// the callback links instead of signing up. `require-consent` applies the
// policy gate that Registration's public form uses, so consent is not
// something a social signup routes around.
Route::middleware(['guest', 'require-consent'])->group(function (): void {
    Route::get('auth/{provider}/complete', [SocialAuthController::class, 'complete'])
        ->name('social.complete');

    Route::post('auth/{provider}/complete', [SocialAuthController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('social.complete.store');
});

Route::middleware(['auth'])->group(function (): void {
    // The account hub. Was a redirect to /settings/profile; it is a page of
    // its own now because the topbar's Account menu points at "Settings" as
    // a destination, and dropping someone straight into the profile form
    // hides that anything else lives here.
    Route::inertia('settings', 'settings/Index')->name('settings.index');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('settings/profile/avatar', [MembershipImageController::class, 'storeAvatar'])->name('profile.avatar.store');
    Route::delete('settings/profile/avatar', [MembershipImageController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::post('settings/profile/avatar/steam', [MembershipImageController::class, 'copySteam'])->name('profile.avatar.steam');
    Route::post('settings/profile/banner', [MembershipImageController::class, 'storeBanner'])->name('profile.banner.store');
    Route::delete('settings/profile/banner', [MembershipImageController::class, 'destroyBanner'])->name('profile.banner.destroy');
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

    Route::get('settings/linked-accounts', [LinkedAccountController::class, 'edit'])
        ->name('linked-accounts.edit');

    // Deliberately NOT `password.confirm`, unlike single-session revocation.
    // A Steam-only account has no password to confirm, so that middleware
    // would dead-end the screen for precisely the accounts social login
    // creates — an unsatisfiable gate is not a stronger gate. What actually
    // protects the account is the lockout guard in `UnlinkSocialIdentity`:
    // the last way in cannot be removed at all. Re-authenticating through the
    // provider would be the right confirmation here; it is a follow-up
    // (TODO.md), not something to fake with the password prompt.
    Route::delete('settings/linked-accounts/{provider}', [LinkedAccountController::class, 'destroy'])
        ->name('linked-accounts.destroy');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
