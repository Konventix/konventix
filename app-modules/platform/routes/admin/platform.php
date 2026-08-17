<?php

declare(strict_types=1);

/*
 * Platform's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\Admin\AdminDashboardController;
use Konventix\Platform\Http\Controllers\Admin\BrandAdminController;

Route::get('/', AdminDashboardController::class)->name('admin.dashboard');

Route::get('/brands', [BrandAdminController::class, 'index'])->name('admin.brands.index');
Route::post('/brands', [BrandAdminController::class, 'store'])->name('admin.brands.store');
Route::get('/brands/{brand}/edit', [BrandAdminController::class, 'edit'])->name('admin.brands.edit');
Route::put('/brands/{brand}', [BrandAdminController::class, 'update'])->name('admin.brands.update');
Route::put('/brands/{brand}/modules', [BrandAdminController::class, 'updateModules'])->name('admin.brands.modules.update');
