<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\Payment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Money that arrived for an order that cannot take it (ADR-0009, ADR-0057
 * point 9, #6): a PSP success that landed after the checkout-grace sweep had
 * cancelled the order. The data definition lives in `Payment::scopeUnapplied()`;
 * this is what the console shows of such a payment — who reported it, how
 * much, and when it landed.
 *
 * Deliberately without a status field: every row this shape describes is
 * `succeeded`, and what happens to the money next is the operator's own
 * decision, which neither console view makes.
 */
#[TypeScript]
final class UnappliedPaymentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $externalId,
        public readonly ?string $receivedAt,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofPayment(Payment $payment): self
    {
        return new self(
            id: $payment->getKey(),
            provider: $payment->provider,
            amount: $payment->amount,
            currency: $payment->currency,
            externalId: $payment->external_id,
            receivedAt: $payment->received_at?->format('Y-m-d H:i'),
        );
    }
}
