<?php

declare(strict_types=1);

/*
 * Commerce's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Controllers\Admin\InvoiceDocumentController;
use Konventix\Commerce\Http\Controllers\Admin\OrderAdminController;

Route::get('/orders', [OrderAdminController::class, 'index'])->name('admin.orders.index');
Route::post('/orders/{order}/payment', [OrderAdminController::class, 'bookPayment'])->name('admin.orders.book-payment');
Route::get('/invoices/{invoice}/document', [InvoiceDocumentController::class, 'download'])->name('admin.invoices.document');
