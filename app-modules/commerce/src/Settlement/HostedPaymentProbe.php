<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;

/**
 * "Did the payer pay this at the provider after all?" — the one question
 * #231 adds to the expiry sweeps, asked once per order they are about to
 * cancel.
 *
 * On 2026-09-08 a Stripe Checkout Session was paid on DEV at 01:57, its
 * webhook never arrived (the endpoint still pointed at the Connect era),
 * `commerce:reconcile-payments --pending` at 03:00:01 did not pick it up,
 * and `commerce:expire-carts` cancelled the order four seconds later and
 * mailed the buyer — while the money sat at Stripe. The reconciliation
 * running "shortly before" the sweep is not a guarantee; asking at the
 * moment of cancelling is.
 *
 * **One API call per candidate, and only for candidates.** An order with no
 * `pending` payment never reached a hosted page (the case #131 filed the
 * registration sweep for) and costs nothing; an order whose provider has no
 * hosted checkout is not asked either. That keeps a sweep's provider cost
 * proportional to the orders it is actually about to call off.
 *
 * The answer never settles anything by itself — settlement truth stays the
 * webhook's (ADR-0039). `Paid` makes the caller run the ordinary
 * `RecordPaymentSuccess`, exactly what the lost notification would have
 * done.
 *
 * Every read escapes BrandScope explicitly (ADR-0010): both callers run
 * from console commands, where no brand is resolved from a host.
 */
final class HostedPaymentProbe
{
    public function __construct(
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    /**
     * The provider's answer about `$order`'s open hosted payment, and the
     * payment it is about — `null` where there is nothing to ask, which is
     * also what `Unanswerable` means for the caller: proceed.
     *
     * @return array{0: HostedPaymentState, 1: ?Payment}
     */
    public function ask(Order $order): array
    {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Pending)
            ->latest('created_at')
            ->first();

        if ($payment === null) {
            return [HostedPaymentState::Unanswerable, null];
        }

        $adapter = $this->registry->forProvider($payment->provider);

        if (! $adapter instanceof ProvidesHostedCheckout) {
            // `manual` and `null` hold no session; a bank transfer is not
            // something a sweep can ask a provider about.
            return [HostedPaymentState::Unanswerable, $payment];
        }

        return [$adapter->hostedPaymentState($payment), $payment];
    }
}
