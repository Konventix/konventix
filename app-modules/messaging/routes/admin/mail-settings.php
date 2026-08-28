<?php

declare(strict_types=1);

/*
 * Messaging's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Http\Controllers\Admin\MailSettingsAdminController;

Route::get('/mail-settings', [MailSettingsAdminController::class, 'index'])->name('admin.mail-settings.index');
Route::get('/mail-settings/{brand}/edit', [MailSettingsAdminController::class, 'edit'])->name('admin.mail-settings.edit');
Route::put('/mail-settings/{brand}', [MailSettingsAdminController::class, 'update'])->name('admin.mail-settings.update');

// "Send test mail to me" (Task 8, ADR-0048 decision 4): confirms a brand's
// sender identity and the branded layout render, addressed to the
// signed-in operator's own account email.
Route::post('/mail-settings/{brand}/test', [MailSettingsAdminController::class, 'test'])->name('admin.mail-settings.test');
