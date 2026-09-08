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
 * No amount. ADR-0037's Action accepts a partial one, but the console's
 * button refunds the order whole — a partial refund still splits the tax
 * like the full invoice (#7), so offering it here would produce a credit
 * note whose tax lines are wrong. #73 keeps the two apart deliberately.
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
