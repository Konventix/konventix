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

Route::get('/messages', [MessageLogAdminController::class, 'index'])->name('admin.messages.index');
Route::get('/messages/{message}', [MessageLogAdminController::class, 'show'])->name('admin.messages.show');
Route::post('/messages/{message}/resend', [MessageLogAdminController::class, 'resend'])->name('admin.messages.resend');
