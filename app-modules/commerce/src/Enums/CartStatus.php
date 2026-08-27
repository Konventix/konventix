<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * A cart's lifecycle (ADR-0047, Task 9). `Open` is the only state
 * `OpenCart::for()` ever returns — the partial unique index
 * `carts_one_open_per_payer` makes a second concurrent one impossible.
 * `Expired` is the sweep's own terminal state; `CheckedOut` is Task 11's,
 * once `checked_out_order_id` is set.
 */
enum CartStatus: string
{
    case Open = 'open';
    case Expired = 'expired';
    case CheckedOut = 'checked_out';
}
