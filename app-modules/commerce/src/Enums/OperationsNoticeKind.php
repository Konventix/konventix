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
}
