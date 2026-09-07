<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * Where a reversal stands (ADR-0057 point 1). Only `Open` ever moves:
 * `Final` and `Withdrawn` are terminal, so a replayed or out-of-order
 * notification can never un-decide a decided reversal.
 */
enum ReversalStatus: string
{
    /**
     * The provider withholds the money and has not decided. Emits nothing
     * across the module boundary — the row and the operator notice carry
     * it (ADR-0057 point 2).
     */
    case Open = 'open';

    /**
     * The money is gone for good: a return on arrival, a dispute on loss,
     * an external refund on arrival. This is the moment `PaymentReversed`
     * is emitted, once.
     */
    case Final = 'final';

    /**
     * The provider decided in the organizer's favour and the money stays.
     * Nothing crosses the boundary here either: the order never left
     * `paid`, so there is nothing downstream to correct.
     */
    case Withdrawn = 'withdrawn';
}
