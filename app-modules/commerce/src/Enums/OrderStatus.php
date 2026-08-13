<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * An order's settlement state (SPEC.md "Owns" — `Order` — "the authoritative
 * record of what was ordered ... in which currency", ADR-0006). Commerce
 * stays dormant for `settlement: free` registrations (ADR-0026): no
 * `Order` row is ever created for one, so this catalogue only describes
 * orders that exist because something needs to be paid.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * Money went back to the payer (ADR-0037). Distinct from `Cancelled`,
     * which says the order was called off — an order can be cancelled without
     * ever having been paid, and a refunded one was definitely paid first.
     */
    case Refunded = 'refunded';
}
