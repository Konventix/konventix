<?php

declare(strict_types=1);

/*
 * Commerce's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Controllers\Admin\BookkeepingExportController;
use Konventix\Commerce\Http\Controllers\Admin\InvoiceDocumentController;
use Konventix\Commerce\Http\Controllers\Admin\OrderAdminController;
use Konventix\Commerce\Http\Controllers\Admin\SettlementAccountAdminController;

Route::get('/orders', [OrderAdminController::class, 'index'])->name('admin.orders.index');
Route::get('/orders/{order}', [OrderAdminController::class, 'show'])->name('admin.orders.show');
Route::post('/orders/{order}/payment', [OrderAdminController::class, 'bookPayment'])->name('admin.orders.book-payment');
Route::get('/invoices/{invoice}/document', [InvoiceDocumentController::class, 'download'])->name('admin.invoices.document');

Route::get('/settlement-accounts', [SettlementAccountAdminController::class, 'index'])->name('admin.settlement-accounts.index');
Route::post('/settlement-accounts/stripe', [SettlementAccountAdminController::class, 'connectStripe'])->name('admin.settlement-accounts.stripe');
Route::get('/settlement-accounts/stripe/return/{account}', [SettlementAccountAdminController::class, 'stripeReturn'])->name('admin.settlement-accounts.stripe-return');
Route::post('/settlement-accounts/mollie', [SettlementAccountAdminController::class, 'connectMollie'])->name('admin.settlement-accounts.mollie');
Route::post('/settlement-accounts/unzer', [SettlementAccountAdminController::class, 'connectUnzer'])->name('admin.settlement-accounts.unzer');
Route::post('/settlement-accounts/{account}/disconnect', [SettlementAccountAdminController::class, 'disconnect'])->name('admin.settlement-accounts.disconnect');

Route::get('/bookkeeping', [BookkeepingExportController::class, 'index'])->name('admin.bookkeeping.index');
Route::put('/bookkeeping/settings', [BookkeepingExportController::class, 'saveSettings'])->name('admin.bookkeeping.settings');
Route::get('/bookkeeping/datev', [BookkeepingExportController::class, 'downloadDatev'])->name('admin.bookkeeping.datev');
Route::get('/bookkeeping/journal', [BookkeepingExportController::class, 'downloadJournal'])->name('admin.bookkeeping.journal');
