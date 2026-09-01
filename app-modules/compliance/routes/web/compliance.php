<?php

declare(strict_types=1);

/*
 * Routes owned by the Compliance module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:compliance` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Compliance\Http\Controllers\ConsentController;
use Konventix\Compliance\Http\Controllers\DataRequestController;
use Konventix\Compliance\Http\Controllers\PolicyController;
use Konventix\Compliance\Http\Controllers\PrivacyController;
use Konventix\Compliance\Http\Controllers\PublicDataRequestController;

// The published text of one policy, open to everyone: an imprint or privacy
// link that only works for logged-in visitors is not a published policy.
// Never carries `require-consent` — a gate that blocks the page explaining
// what you are agreeing to is a loop.
Route::get('/policies/{slug}', [PolicyController::class, 'show'])->name('policies.show');

// Public Art. 15 / Art. 17 entry (ADR-0050 §4). Deliberately outside
// `auth`: the obligation does not depend on whether the subject can log in.
Route::get('/privacy/request', [PublicDataRequestController::class, 'create'])
    ->name('data-requests.public.create');

Route::post('/privacy/request', [PublicDataRequestController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('data-requests.public.store');

Route::get('/privacy/request/{token}', [PublicDataRequestController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('data-requests.public.verify');

Route::middleware('auth')->group(function (): void {
    // Where the `require-consent` gate lands. Also reachable directly, so
    // someone can re-read what they are being asked to agree to.
    Route::get('/policies-required', [PolicyController::class, 'required'])->name('policies.required');

    Route::post('/consents', [ConsentController::class, 'store'])->name('consents.store');
    Route::delete('/consents/{consent}', [ConsentController::class, 'destroy'])->name('consents.destroy');

    // Lives with the account's other settings pages; the rows are this
    // module's, the shell is Identity's (same split as Messaging's
    // notification preferences).
    Route::get('settings/privacy', [PrivacyController::class, 'edit'])->name('privacy.edit');

    // Self-service Art. 15 / Art. 17 requests, and the subject's own download
    // of what came back. No signed link — see the controller.
    Route::post('/data-requests', [DataRequestController::class, 'store'])->name('data-requests.store');
    Route::get('/data-requests/{subjectRequest}/download', [DataRequestController::class, 'download'])
        ->name('data-requests.download');
});
