<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * A payment attempt's lifecycle (ADR-0039). `Pending` is created when a
 * hosted checkout starts; every transition out of it happens exclusively in
 * a verified webhook handler — never on the return page, which only displays
 * whatever state the webhook has already written.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The provider closed the attempt without a payment — an abandoned
     * checkout session or a lapsed payment window. Distinct from `Failed`,
     * which says the payer tried and the payment did not go through.
     */
    case Expired = 'expired';

    /**
     * The money left again after it had settled (ADR-0057 point 1) —
     * written only when a `payment_reversals` row becomes final for this
     * payment's **whole** amount, so every existing "did this pay?"
     * question stays a one-column read while the row says why and how much.
     * A partial reversal leaves the payment `Succeeded` and is an operator
     * case until #35's partial payments exist.
     *
     * Terminal for the refund guards: a reversed payment is not refundable,
     * because there is nothing left to refund.
     */
    case Reversed = 'reversed';
}
