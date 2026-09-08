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
 * hosted checkout is not asked either.
 *
 * **And at most `commerce.sweeps.probe_budget` of them per run**, because
 * proportional is not bounded (fix round 1, review finding 4): during an
 * outage nothing is cancelled, so the candidate set grows and each run would
 * make more sequential calls than the last. `startRun()` opens the budget,
 * `holdExpired()` is the other half — the age past which an order the
 * provider has never answered for is cancelled anyway.
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
    /**
     * Provider calls left in this run. Set by `startRun()`, which every
     * sweep calls before its loop — a fresh instance is built per run, and
     * saying so explicitly is cheaper than relying on that.
     */
    private int $budget = 0;

    public function __construct(
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    /**
     * Opens a sweep run and gives it its provider-call budget (#231, fix
     * round 1, review finding 4).
     *
     * Without a cap a slow or failing PSP turns a scheduled command into
     * hundreds of sequential HTTP calls: nothing gets cancelled, so the
     * candidate set only grows, and the next run makes N+1 of them. Past the
     * budget the run stops asking and leaves those orders to the next one —
     * they keep their seats for another quarter of an hour, which is the
     * cheap half of this trade.
     */
    public function startRun(): void
    {
        $budget = config('commerce.sweeps.probe_budget');

        $this->budget = is_int($budget) && $budget >= 0 ? $budget : 50;
    }

    /**
     * Whether an order the provider has never been able to answer for has
     * waited long enough to be cancelled anyway (#231, fix round 1, review
     * finding 4).
     *
     * The release valve for an outage: `Undecided` on its own holds the
     * seat, the quota and the stock for as long as the provider is unwell,
     * and "for ever" is not an answer a sweep may give. Past
     * `commerce.sweeps.unknown_payment_grace_hours` the order is called off
     * like any other — safe, because money that did move is found by
     * `commerce:reconcile-payments` and recorded as unapplied money
     * (ADR-0057 point 9), which the console shows and can refund (#6).
     */
    public function holdExpired(Order $order): bool
    {
        $hours = config('commerce.sweeps.unknown_payment_grace_hours');
        $hours = is_int($hours) && $hours >= 0 ? $hours : 24;

        return $order->created_at !== null
            && $order->created_at->lte(now()->subHours($hours));
    }

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

        if ($this->budget <= 0) {
            // Out of budget for this run: the honest answer is that we did
            // not ask, which is `Undecided` — the order keeps its seat and
            // the next run asks. Deliberately not `Unanswerable`, which
            // would cancel it unasked.
            return [HostedPaymentState::Undecided, $payment];
        }

        $this->budget--;

        return [$adapter->hostedPaymentState($payment), $payment];
    }
}
