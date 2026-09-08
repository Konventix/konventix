<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What triggered a `commerce.operations-notice` (ADR-0048 point 3) —
 * `title`/`detail`/`url` already say everything the mail/in-app body needs,
 * so nothing renders from this today; it exists for a future per-kind
 * treatment on the in-app inbox side, and so the generated TypeScript type
 * is a real union instead of `unknown`.
 */
#[TypeScript]
enum OperationsNoticeKind: string
{
    case StockExhausted = 'stock-exhausted';
    case PaymentFailed = 'payment-failed';

    /**
     * `commerce:reconcile-payments` (#71) found a payment the webhook never
     * reported and corrected it. Distinct from the settlement events the
     * correction itself raises: this one says the *notification path* is
     * lossy, which is an operations fact rather than a money fact.
     */
    case PaymentReconciled = 'payment-reconciled';

    /**
     * ADR-0057 point 8: a reversal became final — a Rücklastschrift on
     * arrival, a dispute on loss. The receivable revived and the order is
     * waiting for the money again.
     */
    case PaymentReversed = 'payment-reversed';

    /**
     * ADR-0057 point 8: the provider withholds the money and has not
     * decided. Nothing downstream moves; evidence is filed in the
     * provider's own dashboard (ADR-0039 point 5), which is why this
     * notice exists at all.
     */
    case DisputeOpened = 'dispute-opened';

    /**
     * ADR-0057 point 9 / #6: a PSP success arrived for an order the
     * checkout-grace sweep had already cancelled. The money moved and the
     * order cannot take it; what happens next is the operator's decision.
     */
    case PaymentUnapplied = 'payment-unapplied';
}
