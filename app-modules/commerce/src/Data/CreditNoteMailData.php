<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The typed payload for `commerce.credit-note-issued` (ADR-0009 — never a
 * free-form array). Everything printed in the mail is a snapshot from the
 * immutable credit note row, matching the attached document by construction
 * (ADR-0031).
 *
 * `invoiceNumber` is nullable — a credit note always references an invoice
 * at issuance today (`IssueCreditNote` refuses an order without one), but
 * the field stays optional rather than assumed, the way `CreditNote.invoice_id`
 * itself is nullable.
 *
 * `reasonLabelDe`/`reasonLabelEn` carry both languages rather than one
 * `reasonCode` the template branches on — a template string goes through
 * `Blade::render()` on a raw string (`.ai/rules/app-modules-src.md`), and a
 * branch-free template survives a per-brand override cleanly.
 */
#[TypeScript]
final class CreditNoteMailData extends Data
{
    public function __construct(
        public readonly string $creditNoteNumber,
        public readonly ?string $invoiceNumber,
        public readonly string $recipientName,
        public readonly string $sellerName,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly string $reasonLabelDe,
        public readonly string $reasonLabelEn,
        public readonly string $issuedOn,
    ) {}
}
