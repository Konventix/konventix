<?php

declare(strict_types=1);

/*
 * The delivery-log console (ADR-0048 decision 6). Loaded the same way as
 * `routes/admin/mail-settings.php` — every file under `routes/admin/` is
 * `require`d by `ModuleServiceProvider::registerRoutes()`, wrapped in
 * `web`, the operator-host guard, `auth` and `can:platform.access-admin` —
 * declare bare routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Http\Controllers\Admin\MessageLogAdminController;
use Konventix\Messaging\Http\Controllers\Admin\TemplateAdminController;

// The template editor (ADR-0048 decisions 4 and 6, Task 8) — declared
// before `/messages/{message}` below, or `templates` would be swallowed as
// that route's `{message}` parameter.
Route::get('/messages/templates', [TemplateAdminController::class, 'index'])->name('admin.messages.templates.index');
Route::get('/messages/templates/{key}/edit', [TemplateAdminController::class, 'edit'])
    ->where('key', '[a-z0-9.-]+')
    ->name('admin.messages.templates.edit');
Route::put('/messages/templates/{key}', [TemplateAdminController::class, 'update'])
    ->where('key', '[a-z0-9.-]+')
    ->name('admin.messages.templates.update');
Route::delete('/messages/templates/{key}/{locale}', [TemplateAdminController::class, 'reset'])
    ->where('key', '[a-z0-9.-]+')
    ->name('admin.messages.templates.reset');
Route::post('/messages/templates/{key}/preview', [TemplateAdminController::class, 'preview'])
    ->where('key', '[a-z0-9.-]+')
    ->name('admin.messages.templates.preview');

Route::get('/messages', [MessageLogAdminController::class, 'index'])->name('admin.messages.index');
Route::get('/messages/{message}', [MessageLogAdminController::class, 'show'])->name('admin.messages.show');
Route::post('/messages/{message}/resend', [MessageLogAdminController::class, 'resend'])->name('admin.messages.resend');
