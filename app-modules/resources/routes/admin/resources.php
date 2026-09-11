<?php

declare(strict_types=1);

/*
 * Resources' operator-console routes (ADR-0029, design §8). Loaded by the
 * base ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth` and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those. No `ensure-module` middleware either:
 * the admin host has no brand to gate on; each controller method opens
 * with `Gate::authorize()` instead.
 *
 * `/plans/upload` is declared before the `{plan}` routes so the literal
 * segment wins. The route names `download` and `upload` (rather than
 * `export` and `import`) exist because Wayfinder turns a route name into a
 * named TypeScript export, and both words are reserved there.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Resources\Http\Controllers\Admin\PlanAdminController;
use Konventix\Resources\Http\Controllers\Admin\ResourceTypeAdminController;

// Categories (= ResourceType, design §8.1).
Route::get('/resource-types', [ResourceTypeAdminController::class, 'index'])->name('admin.resource-types.index');
Route::post('/resource-types', [ResourceTypeAdminController::class, 'store'])->name('admin.resource-types.store');
Route::get('/resource-types/{resourceType}/edit', [ResourceTypeAdminController::class, 'edit'])->name('admin.resource-types.edit');
Route::put('/resource-types/{resourceType}', [ResourceTypeAdminController::class, 'update'])->name('admin.resource-types.update');

// Plans (design §8.2).
Route::get('/plans', [PlanAdminController::class, 'index'])->name('admin.plans.index');
Route::post('/plans', [PlanAdminController::class, 'store'])->name('admin.plans.store');
Route::post('/plans/upload', [PlanAdminController::class, 'import'])->name('admin.plans.upload');
Route::post('/plans/{plan}/publish', [PlanAdminController::class, 'publish'])->name('admin.plans.publish');
Route::post('/plans/{plan}/unpublish', [PlanAdminController::class, 'unpublish'])->name('admin.plans.unpublish');
Route::get('/plans/{plan}/export', [PlanAdminController::class, 'export'])->name('admin.plans.download');
Route::get('/plans/{plan}/occupancy', [PlanAdminController::class, 'occupancy'])->name('admin.plans.occupancy');
Route::post('/plans/{plan}/places/{place}/reserve', [PlanAdminController::class, 'reserve'])->name('admin.plans.reserve');
Route::delete('/plans/{plan}/places/{place}/reserve', [PlanAdminController::class, 'unreserve'])->name('admin.plans.unreserve');

// The editor (design §8.3): page, JSON save, JSON dry run.
Route::get('/plans/{plan}/edit', [PlanAdminController::class, 'edit'])->name('admin.plans.edit');
Route::put('/plans/{plan}', [PlanAdminController::class, 'update'])->name('admin.plans.update');
Route::post('/plans/{plan}/validate', [PlanAdminController::class, 'validate'])->name('admin.plans.validate');
