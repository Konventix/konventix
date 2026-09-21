<?php

declare(strict_types=1);

/*
 * Routes owned by the Support module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:support` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Support\Http\Controllers\SupportRequestController;

/*
 * The report channel (design §6.4, S1). Open to guests: filing a report
 * needs no account and no capability — a channel that required either would
 * be a channel the people who find the bugs cannot reach (ADR-0063).
 *
 * `throttle:support-report` is the per-account/per-IP budget declared in
 * `SupportServiceProvider::configureRateLimiting()`; the per-address one
 * lives in the controller, because only the controller has read the address.
 */
Route::post('/support/requests', [SupportRequestController::class, 'store'])
    ->middleware('throttle:support-report')
    ->name('support.requests.store');
