<?php

declare(strict_types=1);

/*
 * Routes owned by the Commerce module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:commerce` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Controllers\CartController;
use Konventix\Commerce\Http\Controllers\CheckoutController;
use Konventix\Commerce\Http\Controllers\MyInvoiceDocumentController;
use Konventix\Commerce\Http\Controllers\MyOrdersController;
use Konventix\Commerce\Http\Controllers\ShopController;
use Konventix\Commerce\Http\Controllers\WaitingRoomController;

// The public shop (ADR-0047, Task 8): a per-brand feature with no module
// gate behind it, since Commerce itself is Core and cannot be switched off
// (`registerFeatures()`). `ensure-feature:commerce:shop` 404s both routes
// for a brand that never opted in — the middleware's own default.
// `ensure-waiting-room` (ADR-0047 point 5) runs after it: a brand with the
// shop off never reaches the queue check at all, and one with the shop on
// but the waiting room off passes straight through — `CommerceWaitingRoomGate`
// checks `commerce:waiting-room` itself.
Route::middleware(['ensure-feature:commerce:shop', 'ensure-waiting-room'])->group(function (): void {
    Route::get('/shop', [ShopController::class, 'index'])->name('commerce.shop.index');
    Route::get('/shop/{slug}', [ShopController::class, 'show'])->name('commerce.shop.show');
});

// The holding page itself (ADR-0047 point 5, Task 13). Behind its own
// feature — a brand that never switched the waiting room on 404s here
// exactly like `/shop` does behind `commerce:shop` — but deliberately
// NOT behind `ensure-waiting-room`: this page IS the waiting room, so
// gating it with itself would loop the visitor straight back here. Not
// behind `auth` either — a guest queues exactly like a signed-in
// visitor, and the ticket travels in a signed cookie, not a session.
Route::middleware(['ensure-feature:commerce:waiting-room'])->group(function (): void {
    Route::get('/waiting-room', [WaitingRoomController::class, 'show'])->name('commerce.waiting-room.show');
    Route::get('/waiting-room/status', [WaitingRoomController::class, 'status'])->name('commerce.waiting-room.status');
});

// The person-owned cart and its checkout (ADR-0047, Task 11): behind both
// the shop's own feature — a brand that never switched shopping on has no
// cart either — and `auth`, since a cart belongs to a signed-in payer from
// the moment it is opened (ADR-0044's shape, one step earlier). The feature
// gate runs first, so an anonymous visitor on a brand with the feature off
// gets the same 404 the shop routes give, not a login redirect for a page
// that does not exist. `ensure-waiting-room` sits beside it, same as /shop
// above — the queue applies to the cart before auth ever redirects a
// guest to sign in, so a rush is held at the door rather than at login.
Route::middleware(['ensure-feature:commerce:shop', 'ensure-waiting-room', 'auth'])->group(function (): void {
    Route::get('/cart', [CartController::class, 'show'])->name('commerce.cart.show');
    Route::post('/cart/lines', [CartController::class, 'storeLine'])->name('commerce.cart.lines.store');
    Route::patch('/cart/lines/{line}', [CartController::class, 'updateLine'])->name('commerce.cart.lines.update');
    Route::delete('/cart/lines/{line}', [CartController::class, 'destroyLine'])->name('commerce.cart.lines.destroy');
    Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('commerce.cart.checkout');
});

// Public checkout for `instant` settlement (ADR-0039), addressed by the
// registration ULID — the only identifier the payer's flow carries.
Route::get('/pay/registration/{registration}', [CheckoutController::class, 'show'])
    ->whereUlid('registration')
    ->name('commerce.pay.show');
Route::post('/pay/registration/{registration}/start', [CheckoutController::class, 'start'])
    ->whereUlid('registration')
    ->name('commerce.pay.start');

// The registrant's own invoice PDF. `signed` is the authorization — see
// MyInvoiceDocumentController — and `auth` is there because a signed link
// that leaked would otherwise hand a billing address to whoever found it.
//
// `signed:relative` because the link is minted relative: a brand may answer
// on several domains (ADR-0001), and a signature bound to whichever host the
// page happened to render on would break the moment someone reached the same
// brand by another one. The invoice stays brand-scoped regardless — the
// global scope is what 404s it on a different brand's host.
Route::get('/my/invoices/{invoice}/document', MyInvoiceDocumentController::class)
    ->whereUlid('invoice')
    ->middleware(['auth', 'signed:relative'])
    ->name('commerce.my.invoice.document');

// The payer's own orders (ADR-0044). `auth` only — no `require-consent`,
// for the reason Registration gives on `/my/registrations`: reading back what
// you already owe is not a new contract.
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/orders', [MyOrdersController::class, 'index'])->name('commerce.mine.index');
});
