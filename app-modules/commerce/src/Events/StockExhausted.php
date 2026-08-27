<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Emitted by the consumer Action (Task 7) the moment a `ProductVariant`'s
 * `remaining()` reaches zero from a `consumeStock()` call — the transition
 * into sold-out, not every purchase against an already-exhausted variant.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class StockExhausted
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $brandId,
        public string $exhaustedAt,
    ) {}
}
