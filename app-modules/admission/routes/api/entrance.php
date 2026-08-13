<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Admission\Http\Controllers\EntranceController;
use Konventix\Admission\Http\Middleware\EnsureEntranceDevice;

/*
 * The entrance API (ADR-0033). Stateless and device-authenticated: no
 * session, no CSRF, no brand resolved from the host — the device's own token
 * determines the brand.
 *
 * Bare routes only. The `api` group and the module gate are applied by
 * ModuleServiceProvider; re-applying them here would double them up.
 */

Route::middleware(EnsureEntranceDevice::class)
    ->prefix('entrance')
    ->name('entrance.')
    ->group(function (): void {
        Route::get('signing-keys', [EntranceController::class, 'signingKeys'])->name('signing-keys');
        Route::get('delta', [EntranceController::class, 'delta'])->name('delta');
        Route::post('scans', [EntranceController::class, 'scans'])->name('scans');
    });
