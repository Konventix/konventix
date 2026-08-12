<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An invoice number was drawn from the brand's range and the invoice is
 * finalized (SPEC.md "Public contract", ADR-0031 — immutable from this
 * moment). Scalars only (ADR-0004). Messaging will eventually consume this
 * to send the document; Registration may track it for payment-status
 * display.
 */
final readonly class InvoiceIssued
{
    public function __construct(
        public string $invoiceId,
        public string $orderId,
        public string $brandId,
        public string $invoiceNumber,
        public string $issuedOn,
        public string $dueOn,
        public string $totalGross,
        public string $currency,
    ) {}
}
