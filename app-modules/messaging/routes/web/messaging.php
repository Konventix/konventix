<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Http\Controllers\NotificationPreferencesController;

Route::middleware(['auth'])->group(function (): void {
    // The preferences page lives with the account's other settings pages;
    // Identity links here — the rows live in Messaging (ADR-0041,
    // D-MSG-06).
    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])
        ->name('notification-preferences.edit');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])
        ->name('notification-preferences.update');
});

/*
 * Routes owned by the Messaging module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:messaging` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */
