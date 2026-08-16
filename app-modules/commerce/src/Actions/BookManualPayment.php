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
 * The account id is the brand's live *manual* account when one exists, and
 * the `manual` sentinel otherwise: a bank transfer booked by hand involves
 * no PSP, so a live Stripe/Mollie/Unzer account is deliberately never
 * referenced here — the adapter contract is account-scoped on every call
 * (ADR-0007) even where no PSP is involved.
 */
final class BookManualPayment
{
    public function __construct(
        private readonly ManualSettlementAdapter $adapter,
    ) {}

    public function handle(Order $order): Order
    {
        if ($order->status !== OrderStatus::Pending) {
            throw new RuntimeException(sprintf(
                'Order [%s] is not pending (status: %s) — only a pending order can take a manual payment.',
                $order->id,
                $order->status->value,
            ));
        }

        $live = SettlementAccount::liveFor($order->brand_id);

        return $this->adapter->markOrderPaid(
            accountId: $live?->provider === SettlementAccount::PROVIDER_MANUAL
                ? $live->id
                : SettlementAccount::PROVIDER_MANUAL,
            order: $order,
            amountPaid: $order->total_gross,
            currency: $order->currency,
            settlementMethod: 'bank_transfer',
        );
    }
}
