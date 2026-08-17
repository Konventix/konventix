<?php

declare(strict_types=1);

/*
 * Content's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Content\Http\Controllers\Admin\CommentModerationController;
use Konventix\Content\Http\Controllers\Admin\ContentAdminController;
use Konventix\Content\Http\Controllers\Admin\ContentLifecycleController;

Route::get('/content', [ContentAdminController::class, 'index'])->name('admin.content.index');
Route::post('/content', [ContentAdminController::class, 'store'])->name('admin.content.store');
Route::get('/content/{entry}/edit', [ContentAdminController::class, 'edit'])->name('admin.content.edit');
Route::put('/content/{entry}', [ContentAdminController::class, 'update'])->name('admin.content.update');

// One endpoint per lifecycle Action — the Actions own every guard.
Route::post('/content/{entry}/publish', [ContentLifecycleController::class, 'publish'])->name('admin.content.publish');
Route::post('/content/{entry}/unpublish', [ContentLifecycleController::class, 'unpublish'])->name('admin.content.unpublish');
Route::post('/content/{entry}/archive', [ContentLifecycleController::class, 'archive'])->name('admin.content.archive');

// Moderation is its own capability, so it is its own screen.
Route::get('/content-comments', [CommentModerationController::class, 'index'])->name('admin.content.comments.index');
Route::post('/content-comments/{comment}', [CommentModerationController::class, 'moderate'])->name('admin.content.comments.moderate');
