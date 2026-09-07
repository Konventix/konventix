<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\ManualSettlementAdapter;
use RuntimeException;

/**
 * First-class manual payment booking (SPEC.md "Owns": "an organizer marking
 * a bank transfer as received is a normal, supported flow, not a
 * workaround"). Guards the order state, then delegates to
 * `ManualSettlementAdapter`, which marks paid and emits `OrderPaid` — the
 * event Registration consumes (ADR-0006).
 *
 * The account id is always the `manual` sentinel: a bank transfer booked by
 * hand involves no PSP, so a live Stripe/Mollie/Unzer account is
 * deliberately never referenced here — the adapter contract is
 * account-scoped on every call (ADR-0007) even where no PSP is involved.
 */
final class BookManualPayment
{
    public function __construct(
        private readonly ManualSettlementAdapter $adapter,
    ) {}

    public function handle(Order $order): Order
    {
        // ADR-0057 point 3: a `payment_reversed` order is owed again exactly
        // as a pending one is — the door POS or a bank transfer is one of
        // the ways the money comes back, alongside the pay link
        // (`RecordPaymentSuccess`). Every other status stays refused.
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::PaymentReversed], true)) {
            throw new RuntimeException(sprintf(
                'Order [%s] is not pending or payment_reversed (status: %s) — only one of those can take a manual payment.',
                $order->id,
                $order->status->value,
            ));
        }

        return $this->adapter->markOrderPaid(
            accountId: SettlementAccount::PROVIDER_MANUAL,
            order: $order,
            amountPaid: $order->total_gross,
            currency: $order->currency,
            settlementMethod: SettlementAccount::PROVIDER_MANUAL,
        );
    }
}
