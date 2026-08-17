<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Konventix\Platform\Blocks\StaticPageRenderer;
use Konventix\Platform\Models\StaticPage;

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
        ->whereNotNull('published_at')
        ->first();

    if ($home === null) {
        return Inertia::render('Welcome');
    }

    return Inertia::render('StaticPage', [
        'title' => $home->title,
        'blocks' => $renderer->render($home),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Brand hosts have no dashboard — the brand landing page is the hub
    // (a shop joins it later). The route name survives as the post-login
    // redirect target (config/fortify.php `home`) and for any generated
    // links, so nothing dangles.
    Route::redirect('dashboard', '/')->name('dashboard');
});
