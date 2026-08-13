<?php

declare(strict_types=1);

/*
 * Routes owned by the Admission module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:admission` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Admission\Http\Controllers\ClaimController;
use Konventix\Admission\Http\Controllers\EntranceClientAssetController;

/*
 * Claiming a holder-less admission (ADR-0032). Two guards stack, exactly as
 * Registration's waitlist claim does it: `signed` proves the URL was minted
 * by us and has not expired, `auth` proves a real account is attaching itself
 * (D-REG-08). Neither alone is enough — a signed link with no account would
 * assign an admission to nobody.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::post('/admissions/claim/{link}', [ClaimController::class, 'store'])
        ->middleware('signed')
        ->name('admission.claim');
});

/*
 * The offline entrance client's shell (ADR-0002's carve-out, ADR-0033's trust
 * model). Unauthenticated on purpose: the page is a static bundle carrying no
 * data, and the *device* authenticates itself to `/api/entrance/*` with its
 * own bearer token, which the operator enters once at enrolment. Requiring a
 * session here would defeat the point — a door tablet has no user to log in.
 */
Route::view('/entrance', 'admission::entrance.client')->name('admission.entrance-client');

/*
 * The service worker and manifest are served from the site root rather than
 * from /build, because a worker may only control URLs at or below its own
 * path — one at /build/entrance-sw.js could never claim /entrance.
 */
Route::get('/entrance-sw.js', [EntranceClientAssetController::class, 'serviceWorker']);
Route::get('/entrance.webmanifest', [EntranceClientAssetController::class, 'manifest']);
