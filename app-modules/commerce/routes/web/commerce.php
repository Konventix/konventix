<?php

declare(strict_types=1);

/*
 * Routes owned by the Commerce module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:commerce` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Controllers\CheckoutController;

// Public checkout for `instant` settlement (ADR-0039), addressed by the
// registration ULID — the only identifier the payer's flow carries.
Route::get('/pay/registration/{registration}', [CheckoutController::class, 'show'])
    ->whereUlid('registration')
    ->name('commerce.pay.show');
Route::post('/pay/registration/{registration}/start', [CheckoutController::class, 'start'])
    ->whereUlid('registration')
    ->name('commerce.pay.start');
