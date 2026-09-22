<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\OrderLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One order line as the console's order page shows it (ADR-0009), including
 * what is left of it to credit (#7, D-0.8.2-1).
 *
 * `creditedQuantity` is the sum of this line's units across every credit
 * note already issued against the order — `credit_note_lines`, the ledger
 * the per-line guard reads. `remainingQuantity` is what the refund dialog's
 * number input may be set to, and a line at zero is shown as fully credited
 * rather than hidden: an operator asking "why can I not credit this?"
 * deserves the answer on the page.
 *
 * The client never computes money from these. It posts lines and
 * quantities; the server resolves them through `ResolveCreditNoteLines`, so
 * a browser cannot round differently from the Gutschrift — and an operator
 * cannot be shown a total that the document then contradicts.
 */
#[TypeScript]
final class OrderCreditLineData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $quantity,
        public readonly string $unitAmountNet,
        public readonly string $taxRate,
        public readonly string $lineNet,
        public readonly string $lineGross,
        public readonly int $creditedQuantity,
        public readonly int $remainingQuantity,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofLine(OrderLine $line, int $creditedQuantity): self
    {
        return new self(
            id: $line->getKey(),
            label: $line->label,
            quantity: $line->quantity,
            unitAmountNet: (string) $line->unit_amount_net,
            taxRate: (string) $line->tax_rate,
            lineNet: (string) $line->line_net,
            lineGross: (string) $line->line_gross,
            creditedQuantity: $creditedQuantity,
            remainingQuantity: max(0, $line->quantity - $creditedQuantity),
        );
    }
}
