<?php

declare(strict_types=1);

/*
 * Event's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Event\Http\Controllers\Admin\EventAdminController;

Route::get('/events', [EventAdminController::class, 'index'])->name('admin.events.index');
Route::get('/events/{event}', [EventAdminController::class, 'show'])->name('admin.events.show');
