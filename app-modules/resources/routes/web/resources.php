<?php

declare(strict_types=1);

/*
 * Routes owned by the Resources module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:resources` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Resources\Http\Controllers\SeatAvailabilityController;
use Konventix\Resources\Http\Controllers\SeatPickController;

// The seat picker (design §7.4). `{registration}` is a plain ULID, not a
// route-model binding: Resources may not import Registration's model
// (ADR-0004), and the claim row is what authorises anyway — the controller
// loads the `ResourceClaim` by registration id under the brand scope and
// 404s unless the signed-in account is its registrant. No `require-consent`,
// for the same reason `registration.mine.*` carries none: choosing a seat
// for a contract already entered is not a new contract. The module gate
// comes from the provider (gateable module), never from this file.
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/registrations/{registration}/seat', [SeatPickController::class, 'show'])
        ->name('resources.seating.pick');

    Route::post('/my/registrations/{registration}/seat', [SeatPickController::class, 'store'])
        ->name('resources.seating.pick.store');

    Route::get('/my/registrations/{registration}/seat/availability', [SeatAvailabilityController::class, 'show'])
        ->name('resources.seating.availability');
});
