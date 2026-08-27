<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * First-class manual payment booking (SPEC.md "Owns" — "an organizer marking
 * a bank transfer as received is a normal, supported flow, not a
 * workaround"). Marks the order paid and emits `OrderPaid` — the module's
 * outbound contract (ADR-0006) — plus the `Payment` record and
 * `PaymentReceived` (ADR-0039): a hand-booked bank transfer is a payment
 * like any other, it just skips the pending/webhook lifecycle because the
 * money already arrived before anyone touched the system.
 *
 * `brand_id` is stamped from the order explicitly: booking happens on the
 * operator admin host, where BrandContext resolves nothing (.ai/rules).
 *
 * `refund()` is a deliberate no-op — the organizer wires the money back
 * themselves; `RefundOrder` still writes the domain record and the credit
 * note.
 */
final class ManualSettlementAdapter implements SettlementAdapter
{
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return DB::transaction(function () use ($accountId, $order, $amountPaid, $currency, $settlementMethod): Order {
            $order->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $payment = new Payment([
                'order_id' => $order->id,
                // The `manual` sentinel is not a SettlementAccount ULID —
                // only a real connected account may be referenced here.
                'settlement_account_id' => $accountId === SettlementAccount::PROVIDER_MANUAL ? null : $accountId,
                'provider' => SettlementAccount::PROVIDER_MANUAL,
                'status' => PaymentStatus::Succeeded,
                'amount' => $amountPaid,
                'currency' => $currency,
                'external_id' => null,
                'received_at' => now(),
            ]);
            // brand_id from the order, not ambient context (ADR-0010) —
            // booking happens on the operator admin host.
            $payment->forceFill(['brand_id' => $order->brand_id])->save();

            event(new PaymentReceived(
                paymentId: $payment->id,
                orderId: $order->id,
                brandId: $order->brand_id,
                amount: $amountPaid,
                currency: $currency,
                settlementMethod: $settlementMethod,
                externalReference: null,
                receivedAt: $payment->received_at->toIso8601String(),
                settlementAccountId: null,
            ));

            event(new OrderPaid(
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                amountPaid: $amountPaid,
                currency: $currency,
                paidAt: $order->paid_at->toIso8601String(),
                settlementMethod: $settlementMethod,
                settlementAccountId: null,
            ));

            return $order->refresh();
        });
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): void {
        // No provider to instruct: the organizer transfers the money back
        // outside the platform (ADR-0039).
    }
}
