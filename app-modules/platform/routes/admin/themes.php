<?php

declare(strict_types=1);

/*
 * Brand theme editor routes (ADR-0008, ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, already wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those. The per-operation capability
 * (platform.manage-brand-theme) is checked in the controller.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\Admin\ThemeAdminController;

Route::get('/brands/{brand}/theme', [ThemeAdminController::class, 'edit'])->name('admin.brands.theme.edit');
Route::put('/brands/{brand}/theme', [ThemeAdminController::class, 'update'])->name('admin.brands.theme.update');
