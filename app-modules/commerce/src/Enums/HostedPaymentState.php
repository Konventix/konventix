<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * What a PSP says about a hosted checkout session Konventix has not booked
 * (#231) — the question the expiry sweeps ask before they call an order off.
 *
 * Six answers, because collapsing them loses the distinction each sweep
 * decision turns on. "The provider says the money is on its way", "the
 * provider could not be reached" and "we never asked" all used to be one
 * `Undecided`, and the release valve then treated an order the sweep had
 * skipped for want of budget as one the provider had never been able to
 * answer for (fix round 2, review NEW-1 and NEW-2).
 */
enum HostedPaymentState: string
{
    /**
     * The money is at the provider. The sweep books it through the ordinary
     * success path instead of cancelling — the webhook that should have
     * done so never arrived (DEV incident of 2026-09-08, order
     * `01m1zbtcahftrmbh03qt5ynpe2`).
     */
    case Paid = 'paid';

    /**
     * The provider says nothing was paid and nothing is coming: the session
     * is open, expired or cancelled, or the payment attempt was refused.
     * Cancelling is safe and is what the sweep exists for.
     */
    case NotPaid = 'not_paid';

    /**
     * The provider says the money is **on its way**: a Stripe session that
     * completed while its intent is still `processing`, a Mollie payment
     * that is `pending` or `authorized` — SEPA direct debit and bank
     * transfer, which take banking days.
     *
     * Never cancelled on the ordinary sweep clock. A checkout grace of an
     * hour, or a day, says nothing about a transfer that has left the
     * payer's bank; cancelling it would mail the buyer mid-transfer, which
     * is the exact failure #231 was filed for. Only
     * `commerce.sweeps.in_flight_grace_days` — a fortnight by default —
     * ends it, and an operator is told when it does.
     */
    case InFlight = 'in_flight';

    /**
     * Asked, no answer: the provider is unreachable or rejected the key.
     * The order keeps its seat and the next run asks again, up to
     * `commerce.sweeps.unknown_payment_grace_hours`, after which it is
     * cancelled anyway — a provider that stays silent must not hold a seat
     * for ever, and money that did move is found by
     * `commerce:reconcile-payments` and recorded as unapplied money the
     * console can refund (ADR-0057 point 9, #6).
     */
    case Unreachable = 'unreachable';

    /**
     * **Not asked at all**: this run had spent its probe budget
     * (`commerce.sweeps.probe_budget`) before it reached this order.
     *
     * A state of its own precisely so it can never reach the release valve
     * (fix round 2, review NEW-1): "we did not ask" is not evidence that the
     * provider cannot answer, and cancelling on it would release a seat
     * whose session may well be paid — with no provider question asked at
     * all. The order simply waits for the next run.
     */
    case Unprobed = 'unprobed';

    /**
     * This provider cannot be asked about *this* payment at all: Unzer's
     * pending `external_id` is a Paypage id and its API takes a payment id
     * that only exists after settlement; a payment with no session id never
     * reached a hosted page; a provider with no hosted checkout has no
     * session. The sweep proceeds exactly as it did before this question
     * existed — there is no answer to wait for.
     */
    case Unanswerable = 'unanswerable';
}
