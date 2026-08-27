<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Illuminate\Support\Facades\URL;
use Konventix\Commerce\Models\CreditNote;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One credit note as its payer sees it on "my orders" (ADR-0009, ADR-0044,
 * ADR-0048 point 2) — mirrors how `MyOrderData` shows the order's invoice.
 *
 * `documentUrl` is a temporary signed link minted here, inside the render
 * that already established the reader is the payer for the whole "my
 * orders" list — same shape `MyOrderData::ofOrder()` uses for
 * `commerce.my.invoice.document`.
 */
#[TypeScript]
final class MyCreditNoteData extends Data
{
    public function __construct(
        public readonly string $number,
        public readonly string $issuedOn,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly ?string $documentUrl,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofCreditNote(CreditNote $creditNote): self
    {
        return new self(
            number: $creditNote->credit_note_number,
            issuedOn: $creditNote->issued_on->toIso8601String(),
            totalGross: $creditNote->total_gross,
            currency: $creditNote->currency,
            documentUrl: $creditNote->document_path === null ? null : URL::temporarySignedRoute(
                'commerce.my.credit-note.document',
                now()->addMinutes(30),
                ['creditNote' => $creditNote->getKey()],
                absolute: false,
            ),
        );
    }
}
