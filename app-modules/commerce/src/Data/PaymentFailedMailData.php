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
    ) {}
}
