<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;

/**
 * The sweep #131 asks for: a *direct* registration order — the one a brand
 * without the `commerce:shop` feature still places straight from an
 * `instant` settlement request (ADR-0047 point 3 hands only shop brands'
 * instant settlements to a cart) — never expired. `ExpireCarts` sweeps
 * carts and, since ADR-0047 point 4, checked-out carts whose order went
 * unpaid; nothing swept an order that never had a cart at all, so an
 * unpaid `instant` registration held its quota forever.
 *
 * **The window is the carts' own**, read off `Cart::CHECKOUT_GRACE_MINUTES`
 * rather than restated, so the two cannot drift: #131 asks for "the same
 * window as carts", and the question both ask is the same one — how long a
 * payer gets between being sent to the PSP and the seat coming back.
 *
 * **Only orders nobody was ever invoiced for.** An `invoice`-settled
 * registration order is a receivable with payment terms (ADR-0031: the
 * document exists and stands); cancelling it an hour after it was issued
 * would undo a debt the payer has days to settle. The dunning ladder
 * (`RunDunningSweep`) owns that one. Filtered on the existence of an
 * `invoices` row rather than on a settlement column, because the order
 * carries no settlement kind — the invoice *is* the record that terms were
 * given.
 *
 * Cancelling emits `OrderCancelled` with reason
 * `payment_window_expired`, the event Registration already consumes
 * (`CancelRegistrationOnOrderUndone`) to release the quota. No
 * `OrderLineCancelled`: that one exists so a *cart* order can say which of
 * its several registrations it undid, and a direct order settles exactly
 * one thing, which `settlesId` names. No credit note either — an invoiced
 * order is never selected here, so there is no document to correct
 * (ADR-0031).
 *
 * A late payment against an order this sweep cancelled behaves exactly as
 * it does after the cart sweep (#6): `RecordPaymentSuccess` refuses to
 * settle a cancelled order and records `PaymentUnapplied` (ADR-0057 point
 * 9) so the operator sees the money and decides.
 *
 * One transaction per order so one bad row never blocks the rest, and
 * every read escapes BrandScope explicitly (ADR-0010): this runs from a
 * console command, where no brand is resolved from a host. The status is
 * re-checked under the row lock — a payment may have succeeded between the
 * query and the lock.
 */
final class ExpireRegistrationOrders
{
    /**
     * The reason code `OrderCancelled` carries. Registration keys its
     * cancellation listeners on the reason, so this string is contract.
     */
    public const string REASON_PAYMENT_WINDOW_EXPIRED = 'payment_window_expired';

    /**
     * The same window a checked-out cart's order gets (#131: "dieselbe
     * Frist wie Warenkoerbe").
     */
    public const int WINDOW_MINUTES = Cart::CHECKOUT_GRACE_MINUTES;

    private const string SETTLES_REGISTRATION = 'registration';

    /**
     * @return int the number of orders cancelled
     */
    public function handle(?string $brandId = null): int
    {
        $cancelled = 0;

        $query = Order::withoutBrandScope()
            ->where('settles_type', self::SETTLES_REGISTRATION)
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes(self::WINDOW_MINUTES))
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw('1'))
                    ->from((new Invoice)->getTable())
                    ->whereColumn('invoices.order_id', 'orders.id');
            })
            ->orderBy('id');

        if ($brandId !== null && $brandId !== '') {
            $query->where('brand_id', $brandId);
        }

        foreach ($query->cursor() as $order) {
            $handled = DB::transaction(function () use ($order): bool {
                $locked = Order::withoutBrandScope()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->status !== OrderStatus::Pending) {
                    return false;
                }

                // No `CancelOrder` Action covers this transition and `Order`
                // has no `cancelled_at` column — set directly, exactly as
                // `ExpireCarts`'s checkout-grace pass does for the cart case.
                $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

                event(new OrderCancelled(
                    orderId: $locked->id,
                    brandId: $locked->brand_id,
                    settlesType: $locked->settles_type,
                    settlesId: $locked->settles_id,
                    // Nobody acted: this is a deadline passing.
                    cancelledByUserId: null,
                    reasonCode: self::REASON_PAYMENT_WINDOW_EXPIRED,
                    creditNoteId: null,
                    cancelledAt: now()->toIso8601String(),
                ));

                return true;
            });

            if ($handled) {
                $cancelled++;
            }
        }

        return $cancelled;
    }
}
