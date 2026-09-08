<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * Why an operator sent money back (#73, ADR-0037). The code travels on
 * `OrderRefunded` and lands on the credit note that documents the refund
 * (ADR-0031), so it has to still read correctly years later — which is why
 * it is a closed set rather than whatever the operator typed. The operator's
 * own words go in the free-text note beside it.
 *
 * Deliberately not the credit note's `reason_code` column vocabulary
 * (`IssueCreditNote::REASON_*`): that one answers "which kind of document is
 * this" — refund, transfer or cancellation — and every case here is a
 * refund. This answers "why was this one issued".
 *
 * `customer-request` is the code the pre-console refund path already used
 * in tests and tinker; it stays spelled the same so old rows and new ones
 * mean one thing.
 */
enum RefundReason: string
{
    case CustomerRequest = 'customer-request';

    case EventCancelled = 'event-cancelled';

    case DuplicatePayment = 'duplicate-payment';

    case Goodwill = 'goodwill';
}
