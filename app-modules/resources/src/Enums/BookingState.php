<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

/**
 * A booking's lifecycle (SPEC.md "Owns" — `ResourceBooking`).
 *
 * `Held` is provisional and expires; `Confirmed` is firm. The distinction is
 * load-bearing beyond this module: Resources' SPEC settles that the event
 * others may react to is `ResourceBookingConfirmed`, because "a hold is
 * provisional and must never reach a badge".
 *
 * Only `Held` and `Confirmed` occupy the interval — the exclusion constraint
 * is written to ignore the rest, so releasing a booking frees the slot
 * without deleting the history of who had it.
 */
enum BookingState: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * @return list<string>
     */
    public static function occupyingValues(): array
    {
        return [self::Held->value, self::Confirmed->value];
    }
}
