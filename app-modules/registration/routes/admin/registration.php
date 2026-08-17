<?php

declare(strict_types=1);

/*
 * Registration's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Registration\Http\Controllers\Admin\QuotaAdminController;
use Konventix\Registration\Http\Controllers\Admin\RegistrationAdminController;
use Konventix\Registration\Http\Controllers\Admin\RegistrationStatsController;
use Konventix\Registration\Http\Controllers\Admin\RegistrationTypeAdminController;

Route::get('/events/{event}/registration-stats', RegistrationStatsController::class)
    ->name('admin.events.registration-stats');

// The organizer-side registration list (filters, bulk approval, export).
Route::get('/registrations', [RegistrationAdminController::class, 'index'])->name('admin.registrations.index');
Route::post('/registrations/bulk-approve', [RegistrationAdminController::class, 'bulkApprove'])->name('admin.registrations.bulk-approve');
Route::get('/registrations/export', [RegistrationAdminController::class, 'export'])->name('admin.registrations.export');

// The offer itself (ADR-0005): axes, grants, price declaration, quota links.
Route::get('/registration-types', [RegistrationTypeAdminController::class, 'index'])->name('admin.registration-types.index');
Route::post('/registration-types', [RegistrationTypeAdminController::class, 'store'])->name('admin.registration-types.store');
Route::get('/registration-types/{registrationType}/edit', [RegistrationTypeAdminController::class, 'edit'])->name('admin.registration-types.edit');
Route::put('/registration-types/{registrationType}', [RegistrationTypeAdminController::class, 'update'])->name('admin.registration-types.update');

// Quotas are their own entity, shared by several types — hence their own screen.
Route::get('/quotas', [QuotaAdminController::class, 'index'])->name('admin.quotas.index');
Route::post('/quotas', [QuotaAdminController::class, 'store'])->name('admin.quotas.store');
Route::put('/quotas/{quota}', [QuotaAdminController::class, 'update'])->name('admin.quotas.update');
