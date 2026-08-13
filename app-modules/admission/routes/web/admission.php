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
