<?php

declare(strict_types=1);

/*
 * Registration's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Registration\Http\Controllers\Admin\EntryConditionAdminController;
use Konventix\Registration\Http\Controllers\Admin\QuotaAdminController;
use Konventix\Registration\Http\Controllers\Admin\RegistrationAdminController;
use Konventix\Registration\Http\Controllers\Admin\RegistrationStatsController;
use Konventix\Registration\Http\Controllers\Admin\RegistrationTypeAdminController;

Route::get('/events/{event}/registration-stats', RegistrationStatsController::class)
    ->name('admin.events.registration-stats');

// The organizer-side registration list (filters, bulk approval, export).
Route::get('/registrations', [RegistrationAdminController::class, 'index'])->name('admin.registrations.index');
Route::post('/registrations/bulk-approve', [RegistrationAdminController::class, 'bulkApprove'])->name('admin.registrations.bulk-approve');
Route::get('/registrations/export', [RegistrationAdminController::class, 'export'])->name('admin.registrations.export');
// D-REG-15: partial cancellation — take units off a confirmed registration
// and let Commerce return the money for exactly those units. Its own
// capability, deliberately not one `registration.desk` holds.
Route::post('/registrations/{registration}/reduce-quantity', [RegistrationAdminController::class, 'reduceQuantity'])
    ->name('admin.registrations.reduce-quantity');
// ADR-0066 §9.1: one registration and the declarations it rests on —
// including the conditions it never declared, because the set on a type
// stays editable after it has sold (D-REG-19). The decision behind it is
// advisory: it gates nothing (D-REG-22).
Route::get('/registrations/{registration}', [RegistrationAdminController::class, 'show'])
    ->name('admin.registrations.show');
Route::post('/registrations/{registration}/entry-conditions/{entryCondition}/decide', [RegistrationAdminController::class, 'decideEntryCondition'])
    ->name('admin.registrations.entry-conditions.decide');

// The offer itself (ADR-0005): axes, grants, price declaration, quota links.
Route::get('/registration-types', [RegistrationTypeAdminController::class, 'index'])->name('admin.registration-types.index');
Route::post('/registration-types', [RegistrationTypeAdminController::class, 'store'])->name('admin.registration-types.store');
Route::get('/registration-types/{registrationType}/edit', [RegistrationTypeAdminController::class, 'edit'])->name('admin.registration-types.edit');
Route::put('/registration-types/{registrationType}', [RegistrationTypeAdminController::class, 'update'])->name('admin.registration-types.update');

// ADR-0066 §9.1: what a reduced fare rests on — the sentence a registrant
// ticks, the document the door asks for, and how it is meant to be checked.
// Archived, never deleted, so there is no destroy route.
Route::get('/entry-conditions', [EntryConditionAdminController::class, 'index'])->name('admin.entry-conditions.index');
Route::post('/entry-conditions', [EntryConditionAdminController::class, 'store'])->name('admin.entry-conditions.store');
Route::get('/entry-conditions/{entryCondition}/edit', [EntryConditionAdminController::class, 'edit'])->name('admin.entry-conditions.edit');
Route::put('/entry-conditions/{entryCondition}', [EntryConditionAdminController::class, 'update'])->name('admin.entry-conditions.update');
Route::post('/entry-conditions/{entryCondition}/archive', [EntryConditionAdminController::class, 'archive'])->name('admin.entry-conditions.archive');

// Quotas are their own entity, shared by several types — hence their own screen.
Route::get('/quotas', [QuotaAdminController::class, 'index'])->name('admin.quotas.index');
Route::post('/quotas', [QuotaAdminController::class, 'store'])->name('admin.quotas.store');
Route::put('/quotas/{quota}', [QuotaAdminController::class, 'update'])->name('admin.quotas.update');
