<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The typed payload for `commerce.operations-notice` (ADR-0009, ADR-0048
 * point 3) — one template for every shop-operator notice Commerce sends on
 * its `commerce.operations` topic. `title`/`detail`/`url` are already
 * composed by the sending listener (`NotifyOperatorsOnStockExhausted`,
 * `NotifyOperatorsOnPaymentFailed`), so the template itself stays
 * branch-free regardless of `kind`. `kind` rides along for a future
 * per-type rendering on the in-app inbox side; nothing reads it yet.
 */
#[TypeScript]
final class OperationsNoticeData extends Data
{
    /**
     * @param  'stock-exhausted'|'payment-failed'  $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $url,
    ) {}
}
