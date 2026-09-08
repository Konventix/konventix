<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Carbon\CarbonInterface;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Cart;
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
     * `orders.created_at`** (fix round 3, review F-1). A checkout hold can
     * be renewed without limit — `PlaceOrderFromCart` reuses a `checked_out`
     * cart's still-`pending` order and pushes `carts.expires_at` forward
     * every time the payer starts another attempt — so the order's age says
     * nothing about the age of the attempt the provider is being asked
     * about. On the old clock a payer whose first transfer bounced on day 13
     * and who started a second one that day was cancelled mid-transfer by
     * the fortnight horizon, which is what the horizon exists to prevent.
     * The window's end moves with the renewal, so the grace does too, and it
     * needs no column of its own: a cart order reads it off its cart, and a
     * direct registration order off `PaymentWindow`, exactly as the two
     * sweeps compute their own deadlines.
     */
    public function holdExpired(Order $order, HostedPaymentState $state): bool
    {
        $windowEndsAt = $this->windowEndsAt($order);

        if ($windowEndsAt === null) {
            return false;
        }

        $deadline = match ($state) {
            HostedPaymentState::Unreachable => $windowEndsAt->copy()->addHours($this->positiveConfig('commerce.sweeps.unknown_payment_grace_hours', 24)),
            HostedPaymentState::InFlight => $windowEndsAt->copy()->addDays($this->positiveConfig('commerce.sweeps.in_flight_grace_days', 14)),
            default => null,
        };

        return $deadline !== null && $deadline->isPast();
    }

    /**
     * When this order's payer ran out of time to pay — the moment both
     * graces count from.
     *
     * A cart order's hold *is* `carts.expires_at`, the column
     * `ExpireCarts`' own pass sweeps on and the one `PlaceOrderFromCart`
     * renews. A direct registration order never had a cart, so its window is
     * the one `ExpireRegistrationOrders` applies: `PaymentWindow::MINUTES`
     * from the order's creation. Null where neither can be established,
     * which means no valve — the safe direction.
     */
    private function windowEndsAt(Order $order): ?CarbonInterface
    {
        $cartExpiry = Cart::withoutBrandScope()
            ->where('checked_out_order_id', $order->id)
            ->orderByDesc('expires_at')
            ->value('expires_at');

        if ($cartExpiry instanceof CarbonInterface) {
            return $cartExpiry;
        }

        return $order->created_at?->copy()->addMinutes(PaymentWindow::MINUTES);
    }

    private function positiveConfig(string $key, int $fallback): int
    {
        $value = config($key);

        return is_int($value) && $value >= 0 ? $value : $fallback;
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

        $this->budget--;

        return [$adapter->hostedPaymentState($payment), $payment];
    }
}
