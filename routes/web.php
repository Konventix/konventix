<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Konventix\Platform\Blocks\StaticPageRenderer;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;
use Konventix\Platform\Models\StaticPage;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

/*
 * Host-level routes only. Every module registers its own routes from
 * app-modules/<name>/routes/web/ via its ServiceProvider, which applies the
 * `web` group and — for gateable modules — the per-brand activation gate
 * (ADR-0003). Never add a module's routes here.
 */

// If the current brand (BelongsToBrand's global scope) has a published
// `home`-slug static page, render it through the same block pipeline as
// `/pages/{slug}` (StaticPageController); otherwise fall back to the
// minimal Welcome page.
Route::get('/', function (StaticPageRenderer $renderer) {
    $home = StaticPage::query()
        ->where('slug', 'home')
        ->published()
        ->first();

    if ($home === null) {
        return Inertia::render('Welcome');
    }

    return Inertia::render('StaticPage', [
        'title' => $home->title,
        'blocks' => $renderer->render($home),
    ]);
})->name('home');

// Health results, in two shapes for two readers.
//
// The dashboard is part of the console: operator host, diagnostics capability,
// same rules as Pulse and Horizon (ADR-0029, ADR-0013).
Route::middleware(['web', EnsureOperatorHost::class, 'auth', 'can:'.Permission::ViewDiagnostics->value])
    ->get('admin/health', HealthCheckResultsController::class)
    ->name('admin.health');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Brand hosts have no dashboard — the brand landing page is the hub
    // (a shop joins it later). The route name survives as the post-login
    // redirect target (config/fortify.php `home`) and for any generated
    // links, so nothing dangles.
    Route::redirect('dashboard', '/')->name('dashboard');
});
