<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * One picked invoice line and how many units of it a credit note credits
 * (#7, D-0.8.2-1).
 *
 * **A quantity, never an amount.** An operator picks lines and units; they
 * do not type a sum. That is the only shape that is exact when an invoice
 * mixes tax rates: the net, the VAT and the gross of the credited subset
 * are then *computed* at each line's own snapshotted rate rather than
 * allocated out of a total whose split nobody can reconstruct. An amount
 * would have to be split back across lines by some rule, and every such
 * rule is a guess about which line the operator meant.
 *
 * **Part of a single unit is not supported, and is refused rather than
 * approximated.** `#[Min(1)]` on an integer says so at the edge of the
 * system: an operator who wants to give back half of one ticket is asking
 * for a discount on a sale that already happened, which is a different
 * document with a different amount — and splitting a unit's net by a
 * fraction would put a rounded, unverifiable figure on a legal document
 * that cannot be corrected afterwards (ADR-0031). The refusal is a
 * validation error naming the field, not a silently clamped 1.
 *
 * Inbound only, so no `#[TypeScript]`: the console posts this shape and
 * reads its errors back under `lines.<i>.quantity`. What the page *renders*
 * — the line, what is left of it — is `OrderCreditLineData`, which is the
 * class that carries the attribute.
 */
final class CreditNoteLineSelectionData extends Data
{
    public function __construct(
        /**
         * The `order_lines` row, because an invoice has no lines of its own:
         * the invoice document renders the order's (ADR-0031). Validated as
         * a ULID here and checked against *this order* in
         * `ResolveCreditNoteLines`, which is where "belongs to the order
         * being credited" can actually be answered.
         */
        #[Required, StringType, Ulid]
        public readonly string $orderLineId,

        #[Required, IntegerType, Min(1)]
        public readonly int $quantity,
    ) {}
}
