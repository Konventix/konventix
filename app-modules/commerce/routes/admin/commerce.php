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
use Konventix\Commerce\Http\Controllers\Admin\CommerceBrandSettingsAdminController;
use Konventix\Commerce\Http\Controllers\Admin\CreditNoteDocumentController;
use Konventix\Commerce\Http\Controllers\Admin\InvoiceDocumentController;
use Konventix\Commerce\Http\Controllers\Admin\OrderAdminController;
use Konventix\Commerce\Http\Controllers\Admin\ProductAdminController;
use Konventix\Commerce\Http\Controllers\Admin\SettlementAccountAdminController;
use Konventix\Commerce\Http\Controllers\Admin\WaitingRoomSettingsAdminController;

Route::get('/products', [ProductAdminController::class, 'index'])->name('admin.products.index');
Route::post('/products', [ProductAdminController::class, 'store'])->name('admin.products.store');
Route::get('/products/{product}/edit', [ProductAdminController::class, 'edit'])->name('admin.products.edit');
Route::put('/products/{product}', [ProductAdminController::class, 'update'])->name('admin.products.update');
Route::delete('/products/{product}', [ProductAdminController::class, 'destroy'])->name('admin.products.destroy');

Route::get('/orders', [OrderAdminController::class, 'index'])->name('admin.orders.index');
Route::get('/orders/{order}', [OrderAdminController::class, 'show'])->name('admin.orders.show');
Route::post('/orders/{order}/payment', [OrderAdminController::class, 'bookPayment'])->name('admin.orders.book-payment');
// The operator's manual "cancel now" for a reversed order whose payment
// never came back (ADR-0057 point 4) — the deadline sweep does the same
// thing automatically, this lets the operator do it earlier.
Route::post('/orders/{order}/cancel-reversed', [OrderAdminController::class, 'cancelReversed'])->name('admin.orders.cancel-reversed');
Route::get('/invoices/{invoice}/document', [InvoiceDocumentController::class, 'download'])->name('admin.invoices.document');
Route::get('/credit-notes/{creditNote}/document', [CreditNoteDocumentController::class, 'download'])->name('admin.credit-notes.document');

Route::get('/settlement-accounts', [SettlementAccountAdminController::class, 'index'])->name('admin.settlement-accounts.index');
// Enabling Stripe for a brand: an ordinary POST with a redirect back — no
// Stripe call and no external redirect since ADR-0058.
Route::post('/settlement-accounts/stripe', [SettlementAccountAdminController::class, 'connectStripe'])->name('admin.settlement-accounts.stripe');
// This instance's own Stripe account (ADR-0058 point 2): one encrypted
// operator row, entered here, winning over STRIPE_SECRET /
// STRIPE_WEBHOOK_SECRET from the environment. Not per brand — a brand's
// Stripe row is only the flag that says it sells through this account.
Route::put('/settlement-accounts/stripe/settings', [SettlementAccountAdminController::class, 'saveStripeSettings'])->name('admin.settlement-accounts.stripe-settings.update');
Route::delete('/settlement-accounts/stripe/settings', [SettlementAccountAdminController::class, 'clearStripeSettings'])->name('admin.settlement-accounts.stripe-settings.destroy');
Route::post('/settlement-accounts/mollie', [SettlementAccountAdminController::class, 'connectMollie'])->name('admin.settlement-accounts.mollie');
Route::post('/settlement-accounts/unzer', [SettlementAccountAdminController::class, 'connectUnzer'])->name('admin.settlement-accounts.unzer');
Route::post('/settlement-accounts/{account}/disconnect', [SettlementAccountAdminController::class, 'disconnect'])->name('admin.settlement-accounts.disconnect');
Route::put('/settlement-accounts/{account}/label', [SettlementAccountAdminController::class, 'updateLabel'])->name('admin.settlement-accounts.label');

// The waiting room's per-brand settings (ADR-0047 point 5), reached from
// the brand edit page's own Waiting room card — see
// CommerceServiceProvider::registerBrandFeatures(). `{brand}` binds
// Platform's own Brand implicitly, same as BrandAdminController's routes:
// Brand is not brand-scoped itself, so no BrandScope hardening applies.
Route::put('/brands/{brand}/waiting-room', [WaitingRoomSettingsAdminController::class, 'update'])->name('admin.brands.waiting-room.update');

// The brand's Commerce settings (ADR-0057 point 4) — today the repayment
// window a reversed payment gets. `{brand}` binds Platform's own Brand
// implicitly, same as the waiting-room route above. The form lives on the
// settlement-accounts page rather than the brand edit page: the brand
// page's only module extension point is `BrandFeature::settingsComponent`,
// which renders solely for a per-brand feature that is switched *on*, and
// a repayment window applies to every brand that ever took money.
Route::put('/brands/{brand}/commerce-settings', [CommerceBrandSettingsAdminController::class, 'update'])->name('admin.brands.commerce-settings.update');

Route::get('/bookkeeping', [BookkeepingExportController::class, 'index'])->name('admin.bookkeeping.index');
Route::put('/bookkeeping/settings', [BookkeepingExportController::class, 'saveSettings'])->name('admin.bookkeeping.settings');
Route::get('/bookkeeping/datev', [BookkeepingExportController::class, 'downloadDatev'])->name('admin.bookkeeping.datev');
Route::get('/bookkeeping/journal', [BookkeepingExportController::class, 'downloadJournal'])->name('admin.bookkeeping.journal');
