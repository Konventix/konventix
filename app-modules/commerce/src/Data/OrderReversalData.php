<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\PaymentReversal;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One reversal as the operator console shows it (ADR-0009, ADR-0057
 * point 8): the fields of the `payment_reversals` row that answer "what
 * left, why, and by when is it owed again" — not the whole row, which
 * carries the correlation ids only `RecordPaymentReversal` needs.
 *
 * Both console views render this same shape: the orders index's reversals
 * list and the order detail page's Reversals table. The enums stay enums so
 * the generated TypeScript names them rather than widening to `string`.
 *
 * `feeAmount` is the provider's chargeback fee "as reported" — only Stripe
 * disputes report one, so it is nullable by nature rather than by accident.
 * It is the organizer's own cost (ADR-0007), recorded here and never
 * invoiced onward.
 */
#[TypeScript]
final class OrderReversalData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly ReversalKind $kind,
        public readonly ReversalStatus $status,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $providerReference,
        public readonly ?string $reasonCode,
        public readonly ?string $feeAmount,
        public readonly string $occurredAt,
        public readonly ?string $finalizedAt,
        public readonly ?string $repayDueOn,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofReversal(PaymentReversal $reversal): self
    {
        return new self(
            id: $reversal->getKey(),
            provider: $reversal->provider,
            kind: $reversal->kind,
            status: $reversal->status,
            amount: $reversal->amount,
            currency: $reversal->currency,
            providerReference: $reversal->provider_reference,
            reasonCode: $reversal->reason_code,
            feeAmount: $reversal->fee_amount,
            // Console timestamps, in the format every other row on these two
            // pages uses — the payment attempts beside them included.
            // Instants (#238): the page renders them in the reader's own
            // zone through `formatDateTime()`. `repayDueOn` below stays a
            // calendar date on purpose — a deadline is not an instant.
            occurredAt: $reversal->occurred_at->toIso8601String(),
            finalizedAt: $reversal->finalized_at?->toIso8601String(),
            repayDueOn: $reversal->repay_due_on?->toDateString(),
        );
    }
}
