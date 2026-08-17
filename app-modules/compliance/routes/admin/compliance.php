<?php

declare(strict_types=1);

/*
 * Compliance's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Compliance\Http\Controllers\Admin\PolicyAdminController;
use Konventix\Compliance\Http\Controllers\Admin\PolicyVersionController;

Route::get('/policies', [PolicyAdminController::class, 'index'])->name('admin.policies.index');
Route::post('/policies', [PolicyAdminController::class, 'store'])->name('admin.policies.store');
Route::get('/policies/{policy}/edit', [PolicyAdminController::class, 'edit'])->name('admin.policies.edit');
Route::put('/policies/{policy}', [PolicyAdminController::class, 'update'])->name('admin.policies.update');

// Versions are their own capability split: drafting text and making it
// binding are different acts (see PolicyVersionController).
Route::post('/policies/{policy}/versions', [PolicyVersionController::class, 'store'])->name('admin.policy-versions.store');
Route::put('/policy-versions/{version}', [PolicyVersionController::class, 'update'])->name('admin.policy-versions.update');
Route::post('/policy-versions/{version}/publish', [PolicyVersionController::class, 'publish'])->name('admin.policy-versions.publish');
