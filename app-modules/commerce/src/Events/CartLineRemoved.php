<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * "The buyer removed this registration line and did not take it back."
 *
 * Since #219 that is **not** the same moment as the click. `RemoveCartLine`
 * only stamps `cart_lines.removed_at` and leaves everything the line holds
 * alone, which is what makes the undo window on the cart page honest; this
 * event is emitted when that window closes without an undo, by whichever of
 * the two closers gets there first — `ResolveRemovedCartLines` at checkout,
 * `ExpireCarts` when the cart hold runs out. `removedAt` is the click, not
 * the close: the buyer's decision is what the event reports.
 *
 * A product line never produces one. It is deleted on the click and its
 * stock released silently, since nothing outside Commerce needs to know.
 *
 * Registration listens for this to cancel the still-pending registration the
 * line was holding (ADR-0047 point 3) — with its own reason code,
 * `cart_line_removed`, so the cancellation mail says the buyer removed it
 * rather than that it expired (#220).
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class CartLineRemoved
{
    public function __construct(
        public string $cartId,
        public string $brandId,
        public string $kind,
        public string $refId,
        public ?string $requestKey,
        public string $removedAt,
    ) {}
}
