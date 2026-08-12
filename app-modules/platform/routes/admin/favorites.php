<?php

declare(strict_types=1);

/*
 * Platform's admin-favorites routes (ADR-0029): per-user pinned admin
 * shortcuts. Loaded by the base ModuleServiceProvider from `routes/admin/`
 * alongside `platform.php`, wrapped in `web`, the operator-host guard,
 * `auth`, and `can:platform.access-admin` — declare bare routes here, never
 * re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\Admin\AdminFavoriteController;

Route::post('/favorites', [AdminFavoriteController::class, 'store'])->name('admin.favorites.store');
Route::delete('/favorites/{favorite}', [AdminFavoriteController::class, 'destroy'])->name('admin.favorites.destroy');
