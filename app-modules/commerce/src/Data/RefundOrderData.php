<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\RefundReason;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * The console's whole-order refund form (#73,
 * `OrderAdminController::refund()`): why the money goes back, and the
 * operator's own words about it.
 *
 * **Still no amount, and that is the decision** (D-0.8.2-1, #7). A partial
 * refund is expressed by picking lines and quantities — `$lines` — never by
 * typing a sum. A sum would have to be split back across the order's lines
 * to be turned into a credit note, and on an invoice that mixes tax rates
 * every rule for doing that is a guess about which line the operator meant;
 * the VAT figure on the document would then be one nobody charged. Naming
 * the lines makes the whole thing computable: `ResolveCreditNoteLines` takes
 * each line's own snapshotted rate through `TaxCalculator`, and the gross
 * the provider is asked for is the result rather than the input.
 *
 * An empty `$lines` is the whole-order refund the button has always made.
 *
 * Fractions of a unit are not expressible and are refused rather than
 * approximated — see `CreditNoteLineSelectionData`.
 *
 * Inbound only, so no `#[TypeScript]`: nothing renders this shape, the page
 * posts its fields and reads its errors back under their own names — the
 * selection's under `lines.<i>.quantity` — the same reason `InvoiceMailData`
 * and friends carry none. What the page *renders* is `OrderCreditLineData`,
 * which carries the attribute.
 */
final class RefundOrderData extends Data
{
    public function __construct(
        #[Required, Enum(RefundReason::class)]
        public readonly RefundReason $reasonCode,

        /**
         * Stored verbatim in two places: `credit_notes.reason_note`
         * (`IssueCreditNote`), where it is kept but never rendered — the
         * document prints only "Grund: Erstattung" — and the audit entry
         * the controller writes, which is where an operator reads it back.
         * Bounded so it stays a sentence about one refund rather than a
         * case file on a row that is never edited again (ADR-0031).
         */
        #[Nullable, StringType, Max(500)]
        public readonly ?string $reasonNote = null,

        /**
         * The lines and quantities a partial refund credits (D-0.8.2-1).
         * Absent or empty means the whole order, which is what the console's
         * button did before this and still does.
         *
         * @var list<CreditNoteLineSelectionData>
         */
        #[Nullable, ArrayType, Max(100), DataCollectionOf(CreditNoteLineSelectionData::class)]
        public readonly array $lines = [],
    ) {}

    /**
     * Null rather than `[]` for the Actions below, which read "no selection"
     * as "credit the whole invoice" and must not be handed an empty list
     * that `ResolveCreditNoteLines` would refuse as an empty pick.
     *
     * @return list<CreditNoteLineSelectionData>|null
     */
    public function creditLines(): ?array
    {
        return $this->lines === [] ? null : $this->lines;
    }
}
