<?php

declare(strict_types=1);

/*
 * Registration's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Registration\Http\Controllers\Admin\RegistrationStatsController;

Route::get('/events/{event}/registration-stats', RegistrationStatsController::class)
    ->name('admin.events.registration-stats');
