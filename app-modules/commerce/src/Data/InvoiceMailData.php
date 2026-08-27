<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload for both invoice message templates (ADR-0009 — never a
 * free-form array): `commerce.invoice-issued` (open) and
 * `commerce.invoice-paid` (ADR-0048) — one Data contract, two templates,
 * so `SendInvoiceMail` builds the same payload regardless of which key it
 * sends. Everything printed in the mail is a snapshot from the immutable
 * invoice row, matching the attached document by construction (ADR-0031).
 *
 * `paid`/`paidVia`/`paidAt` are additive: unused by `commerce.invoice-issued`
 * (which never reads them), read by `commerce.invoice-paid` to thank the
 * payer for money already received instead of naming a due date.
 * `false`/`null` default so `SendInvoiceMail`'s single construction site
 * needs no branch of its own.
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
        public readonly bool $paid = false,
        public readonly ?string $paidVia = null,
        public readonly ?string $paidAt = null,
    ) {}
}
