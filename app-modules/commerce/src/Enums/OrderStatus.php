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

    /**
     * The order was paid and the money was taken back (ADR-0057 point 3):
     * the sale stands and the amount is owed again. Deliberately not back
     * to `Pending` — `Pending` is what the checkout-grace sweep cancels
     * after sixty minutes (`ExpireCarts`) and what "never paid" means on
     * every screen. `RecordPaymentSuccess` settles an order in this state
     * exactly as it settles a pending one, so the money can come back by
     * the pay link, the door POS or a bank transfer.
     */
    case PaymentReversed = 'payment_reversed';
}
