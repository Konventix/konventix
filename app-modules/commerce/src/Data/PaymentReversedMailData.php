<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The typed payload for `commerce.payment-reversed` (ADR-0009, ADR-0057
 * point 8): money that had settled left again, and the payer is told what
 * happened, by when the amount is owed back, and where to pay it.
 *
 * `reasonDe`/`reasonEn` carry both languages rather than a `reasonCode` the
 * template branches on, the same shape `CreditNoteMailData.reasonLabelDe/En`
 * uses — a template string goes through `Blade::render()` on a raw string
 * (`.ai/rules/app-modules-src.md`), so a branch-free template survives a
 * per-brand override cleanly. The raw provider code never rides in the
 * payload: it is a bank or card-network string, and ADR-0057 point 8 is
 * explicit that "the mail informs, it does not accuse".
 *
 * `dueOn` is already formatted (`d.m.Y`), like `placedAt` on the order
 * confirmation — the deadline after which
 * `commerce:cancel-unpaid-reversals` cancels the order.
 *
 * `payUrl` is null wherever paying online is not on offer for the reversed
 * order — a cart order today, because `CheckoutController` is addressed by
 * registration ULID and repaying a cart is a follow-up. The
 * `commerce.payment-reversed-manual` template never reads it; the two keys
 * exist so neither template branches (`.ai/rules/app-modules-src.md`).
 */
#[TypeScript]
final class PaymentReversedMailData extends Data
{
    public function __construct(
        public readonly string $orderNumberShort,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reasonDe,
        public readonly string $reasonEn,
        public readonly string $dueOn,
        public readonly ?string $payUrl,
        public readonly string $ordersUrl,
        /**
         * The order's own page (`commerce.mine.show`, bugfix round
         * 2026-09-21, Task 9) on the brand host; `ordersUrl` stays the
         * list both templates link beside it.
         */
        public readonly string $orderUrl,
    ) {}
}
