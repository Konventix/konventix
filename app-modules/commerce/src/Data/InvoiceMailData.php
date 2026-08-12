<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload for the `commerce.invoice-issued` message template
 * (ADR-0009 — never a free-form array). Everything printed in the mail is
 * a snapshot from the immutable invoice row, matching the attached
 * document by construction (ADR-0031).
 */
final class InvoiceMailData extends Data
{
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $recipientName,
        public readonly string $sellerName,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly string $dueOn,
        public readonly int $termsDaysHint,
    ) {}
}
