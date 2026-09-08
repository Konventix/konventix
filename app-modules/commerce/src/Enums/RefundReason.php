<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * Why an operator sent money back (#73, ADR-0037), and only the operator:
 * this closes the **console's** vocabulary, not the field's. The same
 * `OrderRefunded::$reasonCode` also carries `RecordPaymentReversal`'s
 * `external_refund` — a refund made in the provider's own dashboard, which
 * no console form ever named.
 *
 * Where a value ends up: on `OrderRefunded`, and in the audit entry
 * `OrderAdminController::refund()` writes, which is where an operator reads
 * back why the money went back. It does **not** reach the Gutschrift: the
 * credit note's own `reason_code` is always `IssueCreditNote::REASON_REFUND`
 * (`RecordRefund` sets it), and the document prints that — "Grund:
 * Erstattung" for every refund. Printing the operator's reason on the
 * document is a follow-up; the set is closed so the audit trail can be read
 * as data years later, not because a customer ever sees it.
 *
 * Deliberately not the credit note's `reason_code` vocabulary
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
