<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\Concerns\AsksThePspBeforeCancelling;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Settlement\HostedPaymentProbe;

/**
 * Two sweeps over `expires_at`, both scheduled `commerce:expire-carts`
 * every fifteen minutes across all brands (`withoutBrandScope()`
 * deliberately, one transaction per cart so one bad row never blocks the
 * rest — mirroring `Registration\Actions\ExpireWaitlistOffers` exactly).
 *
 * **The shopping pass** (ADR-0047, Task 9): an `open` cart past its
 * twenty-minute `Cart::LIFETIME_MINUTES` reservation — nobody checked out,
 * so every line's hold is given back.
 *
 * **The checkout-grace pass** (ADR-0047 point 4, Task 11 fix round 1): a
 * `checked_out` cart past its sixty-minute `Cart::CHECKOUT_GRACE_MINUTES`
 * window whose order is still `pending` — a payer who closed the PSP tab,
 * or whose hosted session simply expired, without either the webhook or a
 * return visit ever landing. Both passes release the same way (product
 * stock silently, a registration line's hold via `CartLineExpired`), but
 * this one also cancels the order first: nothing may claim a checked-out
 * cart's stock and registration lines forever just because a payment never
 * arrived. A cart whose order is already `paid` is *never* selected by
 * this pass, however long it has sat `checked_out` — see `handleGraced()`.
 *
 * Cancelling here sets `Order.status` directly (`OrderStatus::Cancelled`
 * already exists on the enum; there is no `CancelOrder` Action and no
 * `cancelled_at` column to fill — `OrderCancelled` itself is still
 * "proposed" per SPEC.md, so this sweep does not emit it either).
 *
 * **The provider is asked first (#231).** Before an order with an open
 * hosted payment is called off, `HostedPaymentProbe` asks the PSP what
 * became of the session: money at the provider is booked through the
 * ordinary `RecordPaymentSuccess` instead of being cancelled, and a
 * provider that cannot answer this run keeps its order until the next one.
 * On DEV a paid Stripe session whose webhook never arrived was cancelled by
 * this sweep four seconds after the hourly reconciliation had missed it,
 * and the buyer was mailed about it while the money sat at Stripe. One
 * question per candidate order, outside the row lock — a network call must
 * never be made while holding one.
 *
 * A PSP success that lands *after* this sweep has cancelled anyway is still
 * refused by `RecordPaymentSuccess::settleOrder()`'s not-pending guard and
 * recorded as unapplied money (ADR-0057 point 9, #6), which the operator
 * can now refund from the console.
 */
final class ExpireCarts
{
    use AsksThePspBeforeCancelling;

    public function __construct(
        private readonly HostedPaymentProbe $probe,
        private readonly RecordPaymentSuccess $recordSuccess,
    ) {}

    public function handle(): int
    {
        // Opens the run's provider-call budget (#231, fix round 1): a slow
        // PSP must not turn one scheduled command into hundreds of
        // sequential HTTP calls.
        $this->startSweepRun();

        return $this->expireOpenCarts() + $this->expireCheckedOutCarts();
    }

    /**
     * The original sweep (Task 9): every `open` cart past `expires_at`.
     */
    private function expireOpenCarts(): int
    {
        $expired = 0;

        /** @var list<Cart> $carts */
        $carts = Cart::withoutBrandScope()
            ->open()
            ->where('expires_at', '<=', now())
            ->get()
            ->all();

        foreach ($carts as $cart) {
            $handled = DB::transaction(function () use ($cart): bool {
                // Re-read under the lock (finding 3): between the query and
                // here the shopper may have added a line, and every cart
                // write pushes `expires_at` forward. Releasing the hold then
                // would take the stock out from under someone who is still
                // shopping.
                $locked = Cart::withoutBrandScope()->whereKey($cart->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status !== CartStatus::Open || $locked->expires_at->isFuture()) {
                    return false;
                }

                $this->releaseCartLines($locked);

                $locked->forceFill(['status' => CartStatus::Expired])->save();

                event(new CartExpired(
                    cartId: $locked->getKey(),
                    brandId: $locked->brand_id,
                    payerUserId: $locked->payer_user_id,
                    expiredAt: now()->toIso8601String(),
                ));

                return true;
            });

            if ($handled) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * The grace-window sweep (Task 11 fix round 1): every `checked_out`
     * cart past `expires_at` whose order is still `pending`. Pre-filtered
     * at the query level to a `pending` order's cart only, via a raw
     * subquery on `orders` rather than an Eloquent relation (`Cart` keeps
     * `checked_out_order_id` a plain ULID, same-module FK or not, per its
     * own docblock) — a `paid` cart's row is never even fetched, let alone
     * touched, so "left alone forever" holds without relying on this
     * method never running again.
     */
    private function expireCheckedOutCarts(): int
    {
        $expired = 0;

        /** @var list<Cart> $carts */
        $carts = Cart::withoutBrandScope()
            ->where('status', CartStatus::CheckedOut)
            ->where('expires_at', '<=', now())
            ->whereIn('checked_out_order_id', function ($query): void {
                $query->select('id')->from('orders')->where('status', OrderStatus::Pending->value);
            })
            // Oldest first, like the registration sweep (fix round 3, review
            // F-5). Unordered, Postgres tends to return the same rows in the
            // same order every run, so during an outage a backlog larger than
            // the probe budget would starve the same tail run after run —
            // holding its stock and its registration lines long past their
            // own grace without ever being asked about.
            ->orderBy('id')
            ->get()
            ->all();

        foreach ($carts as $cart) {
            $order = $cart->checked_out_order_id === null
                ? null
                : Order::withoutBrandScope()->find($cart->checked_out_order_id);

            // Asked before the transaction, never inside it: this is a
            // network call, and holding a row lock across one would let a
            // provider's latency block whatever else touches the order. A
            // cart without an order has nothing to ask about and is swept as
            // it always was.
            if ($order !== null && ! $this->providerSaysThisOrderMayBeCancelled($order, 'Cart sweep')) {
                continue;
            }

            $handled = DB::transaction(function () use ($cart): bool {
                if ($cart->checked_out_order_id === null) {
                    return false;
                }

                // The cart under its own lock first (finding 3).
                // `PlaceOrderFromCart` reuses a checked-out cart's still
                // pending order and pushes `expires_at` a fresh grace
                // forward on every new attempt, so a payer who pressed "pay
                // again" between the query above and this transaction — the
                // moment a hold expires is exactly when they come back — was
                // cancelled mid-attempt, and this branch now makes a
                // synchronous provider call inside that window. The probe
                // does not save them: the session they are looking at
                // answers `open`/`unpaid`, which is `NotPaid`.
                $locked = Cart::withoutBrandScope()->whereKey($cart->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status !== CartStatus::CheckedOut || $locked->expires_at->isFuture()) {
                    return false;
                }

                $order = Order::withoutBrandScope()
                    ->whereKey($locked->checked_out_order_id)
                    ->lockForUpdate()
                    ->first();

                // Re-checked under lock: the pre-filter above is not
                // itself atomic with this transaction, and a payment may
                // have succeeded (or another sweep run may have already
                // cancelled it) between the query and this lock.
                if ($order === null || $order->status !== OrderStatus::Pending) {
                    return false;
                }

                // No `CancelOrder` Action exists and `Order` has no
                // `cancelled_at` column (see class docblock) — set
                // directly, the same way the sweep's other transitions do.
                $order->forceFill(['status' => OrderStatus::Cancelled])->save();

                $this->releaseCartLines($locked);

                $locked->forceFill(['status' => CartStatus::Expired])->save();

                event(new CartExpired(
                    cartId: $locked->getKey(),
                    brandId: $locked->brand_id,
                    payerUserId: $locked->payer_user_id,
                    expiredAt: now()->toIso8601String(),
                ));

                return true;
            });

            if ($handled) {
                $expired++;

                // The valve's operator notice, once the cancellation is
                // committed and not before (fix round 3, review F-4): the
                // transaction above gives up if a late webhook settled the
                // order under the lock, and nobody may be told about a
                // cancellation that then did not happen.
                if ($order !== null) {
                    $this->reportCancellation($order, 'Cart sweep');
                }
            }
        }

        return $expired;
    }

    /**
     * Shared by both passes: a product line's stock is released silently;
     * a registration line emits `CartLineExpired` so Registration can
     * cancel the still-pending registration it was holding.
     */
    private function releaseCartLines(Cart $cart): void
    {
        /** @var list<CartLine> $lines */
        $lines = CartLine::withoutBrandScope()
            ->where('cart_id', $cart->getKey())
            ->get()
            ->all();

        foreach ($lines as $line) {
            if ($line->kind === CartLineKind::Product) {
                $locked = ProductVariant::withoutBrandScope()
                    ->whereKey($line->ref_id)
                    ->lockForUpdate()
                    ->first();

                $locked?->releaseStock($line->quantity);

                continue;
            }

            event(new CartLineExpired(
                cartId: $cart->getKey(),
                brandId: $cart->brand_id,
                kind: $line->kind->value,
                refId: $line->ref_id,
                requestKey: $line->request_key,
                expiredAt: now()->toIso8601String(),
            ));
        }
    }
}
