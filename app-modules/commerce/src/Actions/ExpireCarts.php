<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\HostedPaymentState;
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
    public function __construct(
        private readonly HostedPaymentProbe $probe,
        private readonly RecordPaymentSuccess $recordSuccess,
    ) {}

    public function handle(): int
    {
        // Opens the run's provider-call budget (#231, fix round 1): a slow
        // PSP must not turn one scheduled command into hundreds of
        // sequential HTTP calls.
        $this->probe->startRun();

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
            DB::transaction(function () use ($cart): void {
                $this->releaseCartLines($cart);

                $cart->forceFill(['status' => CartStatus::Expired])->save();

                event(new CartExpired(
                    cartId: $cart->getKey(),
                    brandId: $cart->brand_id,
                    payerUserId: $cart->payer_user_id,
                    expiredAt: now()->toIso8601String(),
                ));
            });

            $expired++;
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
            ->get()
            ->all();

        foreach ($carts as $cart) {
            // Asked before the transaction, never inside it: this is a
            // network call, and holding a row lock across one would let a
            // provider's latency block whatever else touches the order.
            if (! $this->providerSaysThisOrderMayBeCancelled($cart)) {
                continue;
            }

            $handled = DB::transaction(function () use ($cart): bool {
                if ($cart->checked_out_order_id === null) {
                    return false;
                }

                $order = Order::withoutBrandScope()
                    ->whereKey($cart->checked_out_order_id)
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

                $this->releaseCartLines($cart);

                $cart->forceFill(['status' => CartStatus::Expired])->save();

                event(new CartExpired(
                    cartId: $cart->getKey(),
                    brandId: $cart->brand_id,
                    payerUserId: $cart->payer_user_id,
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
     * The #231 question, asked once per cart this pass is about to cancel.
     *
     * `false` means "leave this cart alone this run", for one of two very
     * different reasons — the money is at the provider and has just been
     * booked, or the provider could not say. Both are recorded at warning
     * level: an operator has to be able to see that the notification path
     * is lossy, which is the only thing a sweep can say about it.
     *
     * Booking here is the ordinary settlement path, not a shortcut:
     * `RecordPaymentSuccess` writes the payment, the order and `OrderPaid`
     * exactly as the lost webhook would have, so the buyer gets the
     * confirmation instead of a cancellation mail. The cart stays
     * `checked_out` — a paid cart is never swept, and releasing its lines
     * would hand away the seats that were just paid for.
     */
    private function providerSaysThisOrderMayBeCancelled(Cart $cart): bool
    {
        if ($cart->checked_out_order_id === null) {
            return true;
        }

        $order = Order::withoutBrandScope()->find($cart->checked_out_order_id);

        if ($order === null) {
            return true;
        }

        [$state, $payment] = $this->probe->ask($order);

        if ($payment === null) {
            return true;
        }

        if ($state === HostedPaymentState::Paid) {
            Log::warning('Cart sweep found a hosted payment the provider calls paid; booking it instead of cancelling (#231).', [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'external_id' => $payment->external_id,
            ]);

            $this->recordSuccess->handle($payment);

            return false;
        }

        if ($state === HostedPaymentState::Undecided) {
            if (! $this->probe->holdExpired($order)) {
                Log::warning('Cart sweep left an order standing: the provider could not say whether its hosted payment was paid (#231).', [
                    'order_id' => $order->id,
                    'brand_id' => $order->brand_id,
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'external_id' => $payment->external_id,
                ]);

                return false;
            }

            // The release valve (fix round 1, review finding 4): "we do not
            // know" cannot hold a seat for ever. Money that did move is found
            // by `commerce:reconcile-payments` and lands as unapplied money
            // the console shows and can refund (#6, ADR-0057 point 9).
            Log::warning('Cart sweep cancelled an order the provider never answered for; check it for unapplied money (#231).', [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'external_id' => $payment->external_id,
                'order_created_at' => $order->created_at?->toIso8601String(),
            ]);
        }

        return true;
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
