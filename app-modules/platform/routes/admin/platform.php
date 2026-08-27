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
use Konventix\Platform\Http\Controllers\Admin\ConsoleSelectionController;
use Konventix\Platform\Http\Controllers\Admin\FooterLinksAdminController;
use Konventix\Platform\Http\Controllers\Admin\OperatorAdminController;
use Konventix\Platform\Http\Controllers\Admin\StaticPageAdminController;

Route::get('/', AdminDashboardController::class)->name('admin.dashboard');

// Which brand and event the operator is looking at (ROADMAP slice 18). Held in
// the session on this host — the console has no brand, so never a brand cookie
// (ADR-0029). No capability of its own: looking is not an operation.
Route::put('/selection', [ConsoleSelectionController::class, 'update'])->name('admin.selection.update');

// The operator's own identity, including the legal seller every invoice is
// issued under (ADR-0030). No `{operator}` parameter: the admin host resolves
// exactly one (ADR-0001, ADR-0029), and a parameter would invent a way to edit
// somebody else's.
Route::get('/settings/operator', [OperatorAdminController::class, 'edit'])->name('admin.settings.operator.edit');
Route::put('/settings/operator', [OperatorAdminController::class, 'update'])->name('admin.settings.operator.update');

// The footer of every brand-facing page (see FooterLinkKind for what an
// entry may do). Operator-wide, like the operator's own identity above.
Route::get('/settings/footer', [FooterLinksAdminController::class, 'edit'])->name('admin.settings.footer.edit');
Route::put('/settings/footer', [FooterLinksAdminController::class, 'update'])->name('admin.settings.footer.update');
Route::put('/settings/footer/columns', [FooterLinksAdminController::class, 'updateColumns'])->name('admin.settings.footer.columns.update');

Route::get('/brands', [BrandAdminController::class, 'index'])->name('admin.brands.index');
Route::post('/brands', [BrandAdminController::class, 'store'])->name('admin.brands.store');
Route::get('/brands/{brand}/edit', [BrandAdminController::class, 'edit'])->name('admin.brands.edit');
Route::put('/brands/{brand}', [BrandAdminController::class, 'update'])->name('admin.brands.update');
Route::put('/brands/{brand}/modules', [BrandAdminController::class, 'updateModules'])->name('admin.brands.modules.update');
Route::put('/brands/{brand}/features', [BrandAdminController::class, 'updateFeatures'])->name('admin.brands.features.update');

// A brand's static pages, composed of registered block types (slice 21a).
// `{page}` is a plain string: the admin host resolves no brand, so an
// implicitly bound brand-scoped model would 404 (ADR-0029).
Route::get('/pages', [StaticPageAdminController::class, 'index'])->name('admin.pages.index');
Route::post('/pages', [StaticPageAdminController::class, 'store'])->name('admin.pages.store');
Route::get('/pages/{page}/edit', [StaticPageAdminController::class, 'edit'])->name('admin.pages.edit');
Route::put('/pages/{page}', [StaticPageAdminController::class, 'update'])->name('admin.pages.update');
Route::delete('/pages/{page}', [StaticPageAdminController::class, 'destroy'])->name('admin.pages.destroy');
