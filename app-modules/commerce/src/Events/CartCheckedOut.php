<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * A cart finished checkout (ADR-0047, Task 11): `PlaceOrderFromCart` moved
 * it from `open` to `checked_out` and named the `Order` it produced. Fired
 * inside the same transaction as `OrderPlaced`, which already carries the
 * order's totals — this event exists for anything that only cares "this
 * cart is spoken for now", without reading Commerce's `Cart` table.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class CartCheckedOut
{
    public function __construct(
        public string $cartId,
        public string $brandId,
        public string $payerUserId,
        public string $orderId,
        public string $checkedOutAt,
    ) {}
}
