<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * A credit note was issued against an order (ADR-0031). Emitted by
 * `Konventix\Commerce\Actions\IssueCreditNote`.
 *
 * Issued for two distinct reasons, which `reasonCode` distinguishes: a refund
 * (ADR-0037) and a post-invoice transfer to a different billing party
 * (ADR-0036). A consumer that cares about money moving must check it — a
 * transfer credit note is immediately followed by a new invoice for the same
 * amount, and treating that as a refund would double-count.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class CreditNoteIssued
{
    public function __construct(
        public string $creditNoteId,
        public string $creditNoteNumber,
        public string $orderId,
        public ?string $invoiceId,
        public string $brandId,
        public string $totalGross,
        public string $currency,
        public string $reasonCode,
        public string $issuedAt,
    ) {}
}
