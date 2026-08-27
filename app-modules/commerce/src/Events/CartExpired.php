<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Emitted once per cart by the expiry sweep (`Actions\ExpireCarts`) after
 * every one of its lines has been released or announced — the cart itself
 * moved to `expired` (ADR-0047, Task 9).
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class CartExpired
{
    public function __construct(
        public string $cartId,
        public string $brandId,
        public string $payerUserId,
        public string $expiredAt,
    ) {}
}
