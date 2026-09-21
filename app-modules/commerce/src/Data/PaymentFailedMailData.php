<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The typed payload for `commerce.payment-failed` (ADR-0009, ADR-0048 point
 * 3). `reason` is an already-localised sentence
 * (`SendPaymentFailedMailOnPaymentFailed::reasonSentence()`), the same
 * branch-free-template shape `CreditNoteMailData.reasonLabelDe/En` uses —
 * the raw `reasonCode` never rides in the payload, so a per-brand template
 * override stays a plain string with no conditional of its own
 * (`.ai/rules/app-modules-src.md`).
 */
#[TypeScript]
final class PaymentFailedMailData extends Data
{
    public function __construct(
        public readonly string $orderNumberShort,
        public readonly string $reason,
        public readonly string $retryUrl,
        public readonly string $ordersUrl,
        /**
         * The order's own page (`commerce.mine.show`, bugfix round
         * 2026-09-21, Task 9) on the brand host; `ordersUrl` stays the
         * list the template links beside it.
         */
        public readonly string $orderUrl,
        /**
         * The deadline the retry has, already formatted, or null when this
         * order has none — a bank-transfer brand's registration order, or a
         * cart order whose cart is gone (#131, whole-branch review finding
         * 2). Null rather than an empty string so the template's `@if` is
         * about a fact and not about a formatting accident.
         */
        public readonly ?string $payBy = null,
    ) {}
}
