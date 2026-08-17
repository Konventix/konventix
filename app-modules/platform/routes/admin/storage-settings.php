<?php

declare(strict_types=1);

/*
 * Per-brand storage settings, operator console (ADR-0043, ADR-0029). Loaded
 * by the base ModuleServiceProvider from `routes/admin/`, wrapped in `web`,
 * the operator-host guard, `auth`, and `can:platform.access-admin` — declare
 * bare routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\Admin\StorageSettingsAdminController;

Route::get('/storage-settings', [StorageSettingsAdminController::class, 'index'])->name('admin.storage-settings.index');
Route::get('/storage-settings/{brand}/edit', [StorageSettingsAdminController::class, 'edit'])->name('admin.storage-settings.edit');
Route::put('/storage-settings/{brand}', [StorageSettingsAdminController::class, 'update'])->name('admin.storage-settings.update');
