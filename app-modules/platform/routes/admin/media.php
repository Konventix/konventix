<?php

declare(strict_types=1);

/*
 * Media library operator-console routes (ADR-0035, ADR-0029). Loaded by the
 * base ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare
 * bare routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\Admin\MediaAdminController;

Route::get('/media', [MediaAdminController::class, 'index'])->name('admin.media.index');
Route::post('/media', [MediaAdminController::class, 'store'])->name('admin.media.store');
Route::delete('/media/{media}', [MediaAdminController::class, 'destroy'])->name('admin.media.destroy');
