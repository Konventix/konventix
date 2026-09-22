<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Http\Controllers\StaticPageController;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;
use Konventix\Platform\Operators\OperatorContext;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

/*
 * Host-level routes only. Every module registers its own routes from
 * app-modules/<name>/routes/web/ via its ServiceProvider, which applies the
 * `web` group and — for gateable modules — the per-brand activation gate
 * (ADR-0003). Never add a module's routes here.
 */

// The brand's landing page: a published `home`-slug static page this
// viewer may read, rendered through the same block pipeline as
// `/pages/{slug}`, else the minimal Welcome page. The query, the
// visibility check and the admin-host redirect all live in the controller
// beside `show()`, so `/` and `/pages/{slug}` cannot drift apart.
Route::get('/', [StaticPageController::class, 'home'])->name('home');

// The same landing page, guaranteed plain: while an event's operator has
// made `/` the live hub for admitted members (D-HUB-04), the topbar logo
// points here so the brand's home page stays reachable.
Route::get('/home', [StaticPageController::class, 'plainHome'])->name('home.plain');

// Health results, in two shapes for two readers.
//
// The dashboard is part of the console: operator host, diagnostics capability,
// same rules as Pulse and Horizon (ADR-0029, ADR-0013).
Route::middleware(['web', EnsureOperatorHost::class, 'auth', 'can:'.Permission::ViewDiagnostics->value])
    ->get('admin/health', HealthCheckResultsController::class)
    ->name('admin.health');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Where a fresh login lands (config/fortify.php `home`) and where every
    // "already signed in, go home" redirect points, so the answer depends on
    // the host it was asked on. Brand hosts have no dashboard — the brand
    // landing page is the hub (a shop joins it later). The admin host has no
    // public content at all (ADR-0029), so there the console is home. The
    // switch is the operator context, a host fact: what the console shows
    // next is the capability's business, never this route's.
    Route::get('dashboard', fn (OperatorContext $operator) => redirect()->to(
        $operator->hasOperator() ? route('admin.dashboard', absolute: false) : '/',
    ))->name('dashboard');
});
