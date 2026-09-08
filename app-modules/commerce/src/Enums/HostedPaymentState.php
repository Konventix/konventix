<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * What a PSP says about a hosted checkout session Konventix has not booked
 * (#231) — the question the expiry sweeps ask before they call an order off.
 *
 * Four answers, because the three obvious ones are not enough: "I could not
 * ask" and "this provider cannot be asked at all" lead to opposite
 * decisions. A provider that has simply not decided yet, or that could not
 * be reached, must be asked again next run; a provider that offers no way
 * to correlate a pending payment at all would otherwise hold the seat
 * forever.
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
     * The provider says nothing was paid: the session is open, expired,
     * cancelled or refused. Cancelling is safe and is what the sweep exists
     * for.
     */
    case NotPaid = 'not_paid';

    /**
     * Asked, no usable answer: the provider is unreachable, the key was
     * rejected, or the payment is genuinely mid-flight (Mollie `pending`,
     * an authorised payment not yet captured). The order keeps its seat for
     * this run and the next sweep asks again — a quarter of an hour of a
     * held seat is cheaper than cancelling an order somebody paid for.
     */
    case Undecided = 'undecided';

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
