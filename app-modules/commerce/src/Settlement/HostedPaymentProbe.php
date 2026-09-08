<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Carbon\CarbonInterface;
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
        private readonly PaymentWindow $paymentWindow,
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
     *
     * Floored at one like the two graces (review NEW-1): the floor was
     * applied to them and not to this, so a zero — or any non-numeric env
     * value, which `(int) env()` casts to zero — left every order `Unprobed`,
     * which reaches no release valve, and both sweeps stopped cancelling
     * anything at all. Silently, permanently, and with three comments
     * claiming it could not happen.
     */
    public function startRun(): void
    {
        $this->budget = $this->configuredOrDefault('commerce.sweeps.probe_budget', 50);
    }

    /**
     * Whether an order the provider has not settled has waited long enough
     * to be cancelled anyway (#231, review finding 4) — and **the ceiling
     * depends on what the provider said** (fix round 2, review NEW-2).
     *
     * A provider that cannot be reached gets
     * `commerce.sweeps.unknown_payment_grace_hours` (a day): silence must
     * not hold a seat for the length of an outage. A provider that answers
     * "the money is on its way" gets `commerce.sweeps.in_flight_grace_days`
     * (a fortnight), because SEPA direct debit and bank transfer take
     * banking days and the shorter clock would cancel exactly the payments
     * the sweep's question exists to protect.
     *
     * Every other state — including `Unprobed`, the order this run never
     * asked about (NEW-1) — has no valve at all: `Paid`, `NotPaid` and
     * `Unanswerable` are decisions in themselves, and "we did not ask" is
     * not evidence of anything.
     *
     * **Both clocks start at the end of the order's payment window, not at
     * `orders.created_at`** (fix round 3, review F-1), and the window is
     * `PaymentWindow`'s (finding 4).
     *
     * For a **cart** order that matters twice over: `PlaceOrderFromCart`
     * reuses a `checked_out` cart's still-`pending` order and pushes
     * `carts.expires_at` forward every time the payer starts another
     * attempt, so the order's age says nothing about the age of the attempt
     * the provider is being asked about. On the old clock a payer whose
     * first transfer bounced on day 13 and who started a second one that day
     * was cancelled mid-transfer by the fortnight horizon, which is what the
     * horizon exists to prevent.
     *
     * A **direct registration** order has no such retry: its window is
     * `PaymentWindow::MINUTES` from placement and nothing moves it (review
     * nit 26). The clock is still the window's end rather than the order's
     * creation, so the two kinds are described and computed the same way.
     */
    public function holdExpired(Order $order, HostedPaymentState $state): bool
    {
        $windowEndsAt = $this->windowEndsAt($order);

        if ($windowEndsAt === null) {
            return false;
        }

        $deadline = match ($state) {
            HostedPaymentState::Unreachable => $windowEndsAt->copy()->addHours($this->configuredOrDefault('commerce.sweeps.unknown_payment_grace_hours', 24)),
            HostedPaymentState::InFlight => $windowEndsAt->copy()->addDays($this->configuredOrDefault('commerce.sweeps.in_flight_grace_days', 14)),
            default => null,
        };

        return $deadline !== null && $deadline->isPast();
    }

    /**
     * When this order's payer ran out of time to pay — the moment both
     * graces count from.
     *
     * `PaymentWindow` is the one answer to that question (finding 4): the
     * pay pages, the failure mail and `ExpireRegistrationOrders` all read it
     * there, and a second implementation here could promise a deadline the
     * sweep does not keep. Null means the order has no window at all — an
     * invoiced registration order the dunning ladder owns, a bank-transfer
     * brand's order — and that is the probe's safe direction: no window, no
     * valve, nothing this sweep may cancel on a clock.
     */
    private function windowEndsAt(Order $order): ?CarbonInterface
    {
        return $this->paymentWindow->endsFor($order);
    }

    /**
     * A sweep knob's value: the configured one where it is a positive
     * integer, the documented default otherwise (finding 7, review NEW-8).
     *
     * Zero is a plausible reading of "turn this off" and is the value a
     * non-numeric env var casts to, and every zero here is a trap: a zero
     * budget leaves every order unasked, which reaches no valve, so the
     * sweeps stop cancelling anything at all; a zero grace puts the deadline
     * at the payment window's own end, which is past by construction, so the
     * valve fires on the first run. Neither is a setting anyone means, so a
     * zero falls back to the default rather than being clamped to one — the
     * `max(1, …)` this used to carry could never fire, since both defaults
     * are already above it, and it read as a floor the code did not have.
     */
    private function configuredOrDefault(string $key, int $fallback): int
    {
        $value = config($key);

        return is_int($value) && $value > 0 ? $value : $fallback;
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
            // Out of budget for this run: not an answer, and a state of its
            // own so it can never reach a release valve (fix round 2, review
            // NEW-1). The order waits for the next run.
            return [HostedPaymentState::Unprobed, $payment];
        }

        $state = $adapter->hostedPaymentState($payment);

        // Only a question that could have cost a network call spends budget
        // (finding 8). `Unanswerable` is decided locally — Unzer's Paypage
        // id, a payment that never reached a hosted page — and letting a
        // backlog of those drain the budget would starve exactly the Stripe
        // and Mollie orders the budget exists to spend on.
        if ($state !== HostedPaymentState::Unanswerable) {
            $this->budget--;
        }

        return [$state, $payment];
    }
}
