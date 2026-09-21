<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\RefundReason;
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
 * No amount. The console's button refunds the order whole. Since D-REG-15 a
 * partial refund does split its own tax correctly (#7) — but only where a
 * caller can say *which units* it is refunding, which
 * `RefundUnitsOnRegistrationQuantityReduced` can and an operator typing an
 * amount into this form cannot. #73's console-side partial refund needs that
 * per-line selection first, so the two stay apart deliberately.
 *
 * Inbound only, so no `#[TypeScript]`: nothing renders this shape, the page
 * posts the two fields and reads its errors back under their own names
 * (the same reason `InvoiceMailData` and friends carry none).
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
    ) {}
}
