<?php

declare(strict_types=1);

/*
 * Routes owned by the Content module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:content` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Content\Http\Controllers\CommentController;
use Konventix\Content\Http\Controllers\ContentController;

// The reader's surface, on the brand's own domain. `{slug}` is bound by hand
// rather than by `{entry:slug}`: the controllers must apply the live-and-
// audience rules before an entry exists as far as a reader is concerned, and
// implicit binding would resolve a draft first and 403 it afterwards.
Route::get('/news', [ContentController::class, 'index'])->name('content.index');
Route::get('/news/{slug}', [ContentController::class, 'show'])->name('content.show');

// The urgent-announcement feed the site-wide banner polls once per page load.
Route::get('/announcements', [ContentController::class, 'announcements'])->name('content.announcements');

// Commenting requires an account by decision (D-BATCH-7), so these carry
// `auth` while everything above stays open.
Route::middleware('auth')->group(function (): void {
    Route::post('/news/{slug}/comments', [CommentController::class, 'store'])
        ->name('content.comments.store');
    Route::post('/news/{slug}/comments/{comment}/report', [CommentController::class, 'report'])
        ->name('content.comments.report');
    Route::post('/news/{slug}/comments/{comment}/reaction', [CommentController::class, 'react'])
        ->name('content.comments.react');
    Route::delete('/news/{slug}/comments/{comment}/reaction', [CommentController::class, 'unreact'])
        ->name('content.comments.unreact');
});
