<?php

declare(strict_types=1);

/*
 * Routes owned by the Platform module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:platform` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\CookiePreferencesController;
use Konventix\Platform\Http\Controllers\HubController;
use Konventix\Platform\Http\Controllers\StaticPageController;

Route::get('/pages/{slug}', [StaticPageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('platform.pages.show');

// The cookie-settings dialog's one write. No auth: a guest's choice about
// their own device is theirs to make (§ 25 TDDDG).
Route::post('/cookie-preferences', CookiePreferencesController::class)->name('platform.cookie-preferences.store');

// The live hub (design §7.1). `auth` first, so a guest lands on login;
// `ensure-active-event` then sends a member nobody admitted home with a toast.
Route::middleware(['auth', 'ensure-active-event'])->get('/live', HubController::class)->name('platform.hub');
